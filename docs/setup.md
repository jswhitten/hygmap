# HYGMap Setup Guide

Complete installation guide for setting up HYGMap on your system.

## System Requirements

- **Docker** and **Docker Compose** installed
- **2GB RAM** minimum (4GB recommended)
- **5GB disk space** for star database
- **Git** for downloading the code

## Installation

### 1. Install Docker

**Ubuntu/Debian:**
```bash
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker $USER
# Log out and back in
```

**Windows/Mac:**
Download Docker Desktop from https://www.docker.com/products/docker-desktop/

### 2. Download HygMap

```bash
git clone https://github.com/jswhitten/hygmap.git
cd hygmap
```

### 2. Download HYG CSV files

```bash
cd db/data
wget https://codeberg.org/astronexus/athyg/media/branch/main/data/athyg_v33-1.csv.gz
wget https://codeberg.org/astronexus/athyg/media/branch/main/data/athyg_v33-2.csv.gz
gunzip *.csv.gz
```

### 3. Configure Environment

```bash
# Create your environment file
cp .env.example .env

# Edit with your desired credentials
nano .env
```

**Example .env file:**
```bash
POSTGRES_DB=hygmap
POSTGRES_USER=hygmap_user
POSTGRES_PASSWORD=your_secure_password_here
```

**Important:** The username and password you set will be created automatically when the database is first built.

### 4. Start HygMap

```bash
# Build and start all containers
docker compose up -d --build
```

This will:
- Download and build the containers
- Create the PostgreSQL database
- Import the HYG star catalog (~2.5 million stars)
- Import fictional star names from various sci-fi universes
- Start the web application

### 5. Access HygMap

Open your web browser and go to:
- **http://localhost** (main application)

The initial database import may take a few minutes. You can monitor progress with:
```bash
docker compose logs -f hygmap-db
```

## Verification

### Check Everything is Running

```bash
# View container status
docker compose ps

# Should show both containers as "Up"
```

### Test the Database

```bash
# Connect to database
docker compose exec hygmap-db psql -U hygmap_user -d hygmap

# Check star count
SELECT COUNT(*) FROM athyg;

# Should count ~2.5M stars
```

### Test the Application

Visit http://localhost and you should see:
- Star map interface with controls
- Table of stars at the bottom
- Ability to zoom, pan, and filter stars

## Troubleshooting

### Containers Won't Start

```bash
# Check logs for errors
docker compose logs hygmap-php
docker compose logs hygmap-db

# Common issues:
# - Port 80 already in use (stop Apache/nginx)
# - Port 5432 already in use (stop PostgreSQL)
# - Insufficient memory
```

### Database Import Failed

```bash
# Reset and try again
docker compose down -- volumes
docker compose up -d --build
```

### Can't Access Application

```bash
# Check if containers are running
docker compose ps

# Check PHP logs
docker compose logs hygmap-php

# Test direct connection
curl http://localhost
```

### Permission Errors

```bash
# Fix file permissions
sudo chown -R $USER:$USER .
```

## Next Steps

Once HygMap is running:

1. **Explore the interface** - See the [User Guide](user-guide.md)
2. **Customize settings** - Adjust zoom, filters, star magnitude limits
3. **Add fictional universes** - Contribute more sci-fi star names
4. **Set up production** - Deploy to a server with a domain name

## Development Setup

If you want to modify the code:

```bash
# Make changes to PHP files in hygmap-php/src/
# Rebuild and restart
docker compose down
docker compose up -d --build

# Or mount source for live editing:
# Add to docker-compose.yml:
# volumes:
#   - ./hygmap-php/src:/var/www/html
```

### Running Tests

**No local PHP installation required.** All tests run inside Docker containers.

HYGMap uses PHPUnit for testing with two test suites:

```bash
# Run all tests
make test

# Run unit tests only (fast, no database required)
make test-unit

# Run integration tests (requires running database)
make test-integration
```

### Static Analysis

PHPStan performs static analysis at level 5:

```bash
make analyse
```

### Full CI Pipeline

Run the complete CI pipeline locally:

```bash
make ci
```

This runs PHPStan analysis followed by all tests.

### Available Make Commands

```bash
make help    # Show all available commands
```

## Stopping HygMap

```bash
# Stop all containers (data is preserved)
docker compose down

# Start again later
docker compose up -d
```

## Uninstalling

```bash
# Stop and remove everything
docker compose down
docker volume rm hygmap_db_data
docker image rm hygmap_hygmap-php
```