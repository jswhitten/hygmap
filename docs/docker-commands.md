# HYGMap Docker Commands

Essential commands for managing the HYGMap application.

## Downloading HYGMap

```bash
# Check out the repo and prepare it to be deployed
git clone https://github.com/jswhitten/hygmap.git
cd hygmap
cp .env.example .env
# Edit .env with your desired credentials. The username and password you select will be created the first time you build the database.
vi .env
# Download AT-HYG CSV files to db/data directory
cd db/data
curl -L -O https://codeberg.org/astronexus/athyg/raw/branch/main/data/athyg_v32-{1..2}.csv.gz
gunzip *.csv.gz
# Now you are ready to build and start the application and database containers
docker compose up -d --build
```

## Daily Operations

```bash
# Start application (--build may be omitted if there have been no code changes since it last ran)
docker compose up -d --build

# Start application (prod)
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build

# Stop application  
docker compose down

# View logs
docker compose logs -f
```

## Database Management

```bash
# Reset database (⚠️ DESTROYS ALL DATA - next time you bring up the db container the database will be recreated)
docker compose down --volumes

# Reset database if the container is already down
docker volume rm hygmap_hygmap_data

# Backup database
docker compose exec -T hygmap-db pg_dump -U hygmap_user hygmap > backup.sql

# Connect to database
docker compose exec hygmap-db psql -U hygmap_user -d hygmap
```

## Troubleshooting

```bash
# Check container status
docker compose ps

# View specific service logs
docker compose logs hygmap-php
docker compose logs hygmap-db

# Connect to PHP container
docker compose exec hygmap-php bash
```

## Updates

```bash
# Update from git
git pull origin main
docker compose up -d --build
```