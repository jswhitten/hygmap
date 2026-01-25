# HYGMap Docker Commands

Essential commands for managing the HYGMap application.

## Quick Start

```bash
# Clone and start
git clone https://github.com/jswhitten/hygmap.git
cd hygmap
cp .env.example .env
nano .env  # Set your password

# Build and start all 4 services
docker compose up -d --build
```

No manual data downloads required - the database container automatically downloads the AT-HYG star catalog during build.

## Daily Operations

```bash
# Start all services
docker compose up -d

# Start with rebuild (after code changes)
docker compose up -d --build

# Start production mode
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build

# Stop all services
docker compose down

# View all logs
docker compose logs -f

# View specific service logs
docker compose logs -f hygmap-db
docker compose logs -f hygmap-php
docker compose logs -f hygmap-api
docker compose logs -f hygmap-frontend
```

## Service Management

```bash
# Check container status
docker compose ps

# Restart a specific service
docker compose restart hygmap-api

# Rebuild and restart a specific service
docker compose up -d --build hygmap-php

# View resource usage
docker stats
```

## Database Management

```bash
# Connect to database
docker compose exec hygmap-db psql -U hygmap_user -d hygmap

# Reset database (⚠️ DESTROYS ALL DATA)
docker compose down --volumes
docker compose up -d --build

# Backup database
docker compose exec -T hygmap-db pg_dump -U hygmap_user hygmap > backup_$(date +%Y%m%d).sql

# Restore database
cat backup_20260124.sql | docker compose exec -T hygmap-db psql -U hygmap_user hygmap

# Check star count
docker compose exec hygmap-db psql -U hygmap_user -d hygmap -c "SELECT COUNT(*) FROM athyg;"
```

## Testing

All tests run inside Docker containers - no local installations required.

```bash
# Run all tests (PHP + API + Frontend)
make test

# PHP tests
make test-unit        # Unit tests (fast, no database)
make test-integration # Integration tests (needs running db)
make analyse          # PHPStan static analysis

# API tests
make test-api

# Frontend tests
make test-frontend
make lint-frontend    # ESLint

# Full CI pipeline
make ci
```

## Development Commands

```bash
# Show all available make commands
make help

# Docker shortcuts
make up        # Start containers
make down      # Stop containers
make logs      # View logs
make rebuild   # Rebuild and restart all
```

## Troubleshooting

```bash
# Check if services are healthy
docker compose ps

# View recent logs for errors
docker compose logs --tail=50 hygmap-db
docker compose logs --tail=50 hygmap-api

# Test service endpoints
curl -I http://localhost/           # PHP app
curl http://localhost:8000/health   # API
curl -I http://localhost:5173/      # Frontend

# Shell into a container
docker compose exec hygmap-php bash
docker compose exec hygmap-api bash
docker compose exec hygmap-db bash

# Check disk usage
docker system df
```

## Updates

```bash
# Update from git and rebuild
git pull origin main
docker compose up -d --build

# Pull latest pre-built images (production)
docker compose pull
docker compose up -d
```

## Cleanup

```bash
# Remove stopped containers
docker compose down

# Remove containers and volumes (⚠️ deletes database)
docker compose down --volumes

# Remove unused images
docker image prune

# Full cleanup (all unused Docker resources)
docker system prune -a
```
