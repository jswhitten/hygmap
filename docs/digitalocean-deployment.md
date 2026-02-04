# DigitalOcean Deployment Guide

This guide covers deploying HYGMap to a DigitalOcean droplet with:
- `hygmap.space` → PHP app (port 80)
- `new.hygmap.space` → React frontend (port 5173)
- `api.hygmap.space` → FastAPI backend (port 8000)

## Prerequisites

- DigitalOcean account
- Domain name with DNS managed by DigitalOcean (or ability to update DNS elsewhere)
- SSH key pair on your local machine
- GitHub Container Registry images published (run the publish workflow first)

## Important: Frontend API URL

The frontend image is built with `VITE_API_URL=https://api.hygmap.space` baked in at build time. If you're deploying to a different domain, you'll need to:

1. Update `hygmap-frontend/Dockerfile.prod` to change the default ARG value
2. Or update `.github/workflows/publish.yml` to pass your domain as a build-arg
3. Then rebuild and push the frontend image

## 1. Create the Droplet

### Via DigitalOcean Console

1. Log in to [DigitalOcean](https://cloud.digitalocean.com/)
2. Click **Create** → **Droplets**
3. Configure:
   - **Region**: Choose closest to your users
   - **Image**: Ubuntu 24.04 LTS
   - **Size**: Basic → Regular → **$12/mo** (2GB RAM, 1 vCPU) minimum
     - For production with full star database: **$24/mo** (4GB RAM) recommended
   - **Authentication**: SSH Key (add your public key)
   - **Hostname**: `hygmap-prod` (or similar)
4. Click **Create Droplet**
5. Note the IP address (e.g., `164.92.xxx.xxx`)

### Via doctl CLI (Alternative)

```bash
# Install doctl and authenticate first
doctl compute droplet create hygmap-prod \
  --region nyc1 \
  --size s-2vcpu-4gb \
  --image ubuntu-24-04-x64 \
  --ssh-keys $(doctl compute ssh-key list --format ID --no-header | head -1)
```

## 2. Initial Server Setup

### Connect as Root

```bash
ssh root@YOUR_DROPLET_IP
```

### Create Deploy User

```bash
# Create user with sudo privileges
adduser deploy
usermod -aG sudo deploy

# Copy SSH key to new user
mkdir -p /home/deploy/.ssh
cp ~/.ssh/authorized_keys /home/deploy/.ssh/
chown -R deploy:deploy /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
chmod 600 /home/deploy/.ssh/authorized_keys

# Test login in new terminal before continuing
```

### Configure Firewall

```bash
# Allow SSH, HTTP, HTTPS
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable

# Verify
ufw status
```

### Disable Root Login (Optional but Recommended)

```bash
# Edit SSH config
sed -i 's/PermitRootLogin yes/PermitRootLogin no/' /etc/ssh/sshd_config
systemctl restart sshd
```

Now disconnect and reconnect as deploy user:

```bash
ssh deploy@YOUR_DROPLET_IP
```

## 3. Install Docker

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install Docker
curl -fsSL https://get.docker.com | sudo sh

# Add deploy user to docker group (no sudo needed for docker commands)
sudo usermod -aG docker deploy

# Log out and back in for group change to take effect
exit
```

Reconnect and verify:

```bash
ssh deploy@YOUR_DROPLET_IP
docker --version
docker compose version
```

## 4. Pull and Run Images

### Check Image Visibility

If your GitHub repository is **public**, images are public and no authentication is needed.

If **private**, authenticate first:

```bash
# Create a GitHub Personal Access Token with read:packages scope
echo "YOUR_GITHUB_PAT" | docker login ghcr.io -u YOUR_GITHUB_USERNAME --password-stdin
```

### Create Application Directory

```bash
mkdir -p ~/hygmap
cd ~/hygmap
```

### Create docker-compose.prod.yml

```bash
cat > docker-compose.yml << 'EOF'
services:
  hygmap-db:
    image: ghcr.io/jswhitten/hygmap/hygmap-db:latest
    container_name: hygmap_db
    environment:
      - POSTGRES_DB=hygmap
      - POSTGRES_USER=hygmap_user
      - POSTGRES_PASSWORD=${POSTGRES_PASSWORD}
    volumes:
      - db_data:/var/lib/postgresql/data
    restart: always
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U hygmap_user -d hygmap"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 60s

  hygmap-php:
    image: ghcr.io/jswhitten/hygmap/hygmap-php:latest
    container_name: hygmap_php
    ports:
      - "127.0.0.1:8080:80"
    depends_on:
      hygmap-db:
        condition: service_healthy
    environment:
      - DB_USERNAME=hygmap_user
      - DB_PASSWORD=${POSTGRES_PASSWORD}
      - DB_HOST=hygmap-db
      - DB_PORT=5432
      - DB_NAME=hygmap
    restart: always

  hygmap-api:
    image: ghcr.io/jswhitten/hygmap/hygmap-api:latest
    container_name: hygmap_api
    ports:
      - "127.0.0.1:8000:8000"
    depends_on:
      hygmap-db:
        condition: service_healthy
    environment:
      - DATABASE_URL=postgresql+asyncpg://hygmap_user:${POSTGRES_PASSWORD}@hygmap-db:5432/hygmap
      - DEBUG=False
      - CORS_ORIGINS=https://new.hygmap.space,https://hygmap.space
    restart: always

  hygmap-frontend:
    image: ghcr.io/jswhitten/hygmap/hygmap-frontend:latest
    container_name: hygmap_frontend
    ports:
      - "127.0.0.1:3000:80"
    depends_on:
      - hygmap-api
    restart: always

volumes:
  db_data:
EOF
```

### Create Environment File

```bash
# Generate a strong password
POSTGRES_PASSWORD=$(openssl rand -base64 32 | tr -d '/+=' | head -c 24)

cat > .env << EOF
POSTGRES_PASSWORD=${POSTGRES_PASSWORD}
EOF

# Secure the file
chmod 600 .env

# Show the password (save it somewhere safe)
echo "Database password: ${POSTGRES_PASSWORD}"
```

### Pull Images and Start

```bash
docker compose pull
docker compose up -d
```

### Wait for Database Initialization

The first start takes 2-3 minutes as the database imports 2.5M stars:

```bash
# Watch the logs
docker compose logs -f hygmap-db

# Wait for "database system is ready to accept connections"
# and all init scripts to complete
```

## 5. Test Without Domain

### Quick Health Check

```bash
# Test PHP app
curl -I http://localhost:8080/

# Test API
curl http://localhost:8000/health

# Test Frontend
curl -I http://localhost:3000/
```

## 5. Install and Configure Nginx

### Install Nginx

```bash
sudo apt install nginx -y
sudo systemctl enable nginx
```

### Create Site Configurations

**hygmap.space (PHP app):**

```bash
sudo tee /etc/nginx/sites-available/hygmap.space << 'EOF'
server {
    listen 80;
    server_name hygmap.space www.hygmap.space;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
EOF
```

**new.hygmap.space (React frontend):**

```bash
sudo tee /etc/nginx/sites-available/new.hygmap.space << 'EOF'
server {
    listen 80;
    server_name new.hygmap.space;

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
EOF
```

**api.hygmap.space (FastAPI):**

```bash
sudo tee /etc/nginx/sites-available/api.hygmap.space << 'EOF'
server {
    listen 80;
    server_name api.hygmap.space;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # WebSocket support (if needed)
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
EOF
```

### Enable Sites

```bash
sudo ln -s /etc/nginx/sites-available/hygmap.space /etc/nginx/sites-enabled/
sudo ln -s /etc/nginx/sites-available/new.hygmap.space /etc/nginx/sites-enabled/
sudo ln -s /etc/nginx/sites-available/api.hygmap.space /etc/nginx/sites-enabled/

# Remove default site
sudo rm /etc/nginx/sites-enabled/default

# Test configuration
sudo nginx -t

# Reload
sudo systemctl reload nginx
```

## 7. Configure DNS

In your DNS provider (DigitalOcean, Cloudflare, etc.), create A records pointing to your droplet IP:

| Type | Name | Value |
|------|------|-------|
| A | @ | YOUR_DROPLET_IP |
| A | www | YOUR_DROPLET_IP |
| A | new | YOUR_DROPLET_IP |
| A | api | YOUR_DROPLET_IP |

DNS propagation can take up to 48 hours, but usually completes within minutes.

### Verify DNS

```bash
dig +short hygmap.space
dig +short new.hygmap.space
dig +short api.hygmap.space
```

## 8. Set Up SSL with Let's Encrypt

### Install Certbot

```bash
sudo apt install certbot python3-certbot-nginx -y
```

### Obtain Certificates

```bash
sudo certbot --nginx -d hygmap.space -d www.hygmap.space
sudo certbot --nginx -d new.hygmap.space
sudo certbot --nginx -d api.hygmap.space
```

Follow the prompts:
- Enter email for renewal notices
- Agree to terms
- Choose to redirect HTTP to HTTPS (recommended)

### Verify Auto-Renewal

```bash
sudo certbot renew --dry-run
```

## 9. Final Verification

```bash
# Test all endpoints with HTTPS
curl -I https://hygmap.space/
curl https://api.hygmap.space/health
curl -I https://new.hygmap.space/
```

## 10. Maintenance Commands

### View Logs

```bash
cd ~/hygmap
docker compose logs -f              # All services
docker compose logs -f hygmap-db    # Database only
docker compose logs -f hygmap-api   # API only
```

### Update Images

```bash
cd ~/hygmap
docker compose pull
docker compose up -d
```

### Restart Services

```bash
docker compose restart
```

### Check Status

```bash
docker compose ps
```

### Database Backup

```bash
docker compose exec hygmap-db pg_dump -U hygmap_user hygmap > backup_$(date +%Y%m%d).sql
```

### Database Restore

```bash
cat backup_20260124.sql | docker compose exec -T hygmap-db psql -U hygmap_user hygmap
```

## Troubleshooting

### Container Won't Start

```bash
# Check logs
docker compose logs hygmap-db

# Check if port is in use
sudo lsof -i :8080
```

### Database Not Ready

The database takes 2-3 minutes on first start to import 2.5M stars. Check progress:

```bash
docker compose logs -f hygmap-db | grep -E "NOTICE|ERROR"
```

### 502 Bad Gateway

Usually means the container isn't running or hasn't started yet:

```bash
docker compose ps
docker compose up -d
```

### SSL Certificate Issues

```bash
# Check certificate status
sudo certbot certificates

# Force renewal
sudo certbot renew --force-renewal
```

### Check Nginx Errors

```bash
sudo tail -f /var/log/nginx/error.log
```

## Security Checklist

- [ ] Root login disabled
- [ ] UFW firewall enabled (only 22, 80, 443 open)
- [ ] Strong database password generated
- [ ] `.env` file has restricted permissions (600)
- [ ] SSL certificates installed
- [ ] HTTP redirects to HTTPS
- [ ] Regular backups configured

## Cost Estimate

| Resource | Monthly Cost |
|----------|--------------|
| Droplet (4GB) | $24 |
| **Total** | **$24/month** |

Optional additions:
- Managed database: +$15/month
- Automated backups: +$4.80/month
- Load balancer: +$12/month
