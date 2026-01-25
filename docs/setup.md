# HYGMap Setup Guide

Complete installation guide for setting up HYGMap on your system.

## System Requirements

- **Docker** and **Docker Compose** installed
- **2GB RAM** minimum (4GB recommended)
- **1GB disk space** for containers and database
- **Git** for downloading the code

## Quick Start

```bash
# Clone the repository
git clone https://github.com/jswhitten/hygmap.git
cd hygmap

# Create environment file
cp .env.example .env
nano .env  # Edit with your desired password

# Start all services
docker compose up -d --build
```

The first startup takes 2-3 minutes as the database imports 2.5 million stars.

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

### 2. Download HYGMap

```bash
git clone https://github.com/jswhitten/hygmap.git
cd hygmap
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
# Database (shared by all services)
POSTGRES_DB=hygmap
POSTGRES_USER=hygmap_user
POSTGRES_PASSWORD=your_secure_password_here

# API settings
DEBUG=True
CORS_ORIGINS=http://localhost:5173,http://localhost:3000,http://localhost:80

# Frontend settings
VITE_API_URL=http://localhost:8000
```

**Important:** The database credentials are created automatically on first startup.

### 4. Start HYGMap

```bash
# Build and start all containers
docker compose up -d --build
```

This will:
- Build 4 containers (database, PHP app, API, frontend)
- Download AT-HYG star catalog (~100MB compressed)
- Create the PostgreSQL database
- Import ~2.5 million stars
- Import fictional star names (Star Trek, Babylon 5)
- Import signal data
- Start all web services

### 5. Access HYGMap

Open your web browser:

| Service | URL | Description |
|---------|-----|-------------|
| PHP App | http://localhost | Classic star map interface |
| React Frontend | http://localhost:5173 | Modern 3D interface |
| API Docs | http://localhost:8000/docs | Swagger API documentation |

The initial database import takes 2-3 minutes. Monitor progress with:
```bash
docker compose logs -f hygmap-db
```

## Verification

### Check All Services Running

```bash
# View container status
docker compose ps

# Should show 4 containers as "Up" or "healthy"
```

### Test Each Service

```bash
# Test PHP app
curl -I http://localhost/

# Test API
curl http://localhost:8000/health

# Test Frontend
curl -I http://localhost:5173/
```

### Test the Database

```bash
# Connect to database
docker compose exec hygmap-db psql -U hygmap_user -d hygmap

# Check star count (should be ~2.5M)
SELECT COUNT(*) FROM athyg;

# Check all tables exist
\dt
```

## Services Overview

HYGMap runs 4 Docker containers:

| Container | Port | Description |
|-----------|------|-------------|
| `hygmap-db` | 5432 | PostgreSQL with star data |
| `hygmap-php` | 80 | Classic PHP/Apache interface |
| `hygmap-api` | 8000 | FastAPI REST backend |
| `hygmap-frontend` | 5173 | React/Three.js 3D interface |

## Troubleshooting

### Containers Won't Start

```bash
# Check logs for errors
docker compose logs hygmap-db
docker compose logs hygmap-php
docker compose logs hygmap-api
docker compose logs hygmap-frontend

# Common issues:
# - Port 80 already in use (stop Apache/nginx)
# - Port 5432 already in use (stop PostgreSQL)
# - Port 8000 already in use
# - Insufficient memory
```

### Database Import Failed

```bash
# Reset and try again
docker compose down --volumes
docker compose up -d --build
```

### Can't Access Application

```bash
# Check if containers are running
docker compose ps

# Check specific service logs
docker compose logs hygmap-php

# Test direct connection
curl http://localhost
curl http://localhost:8000/health
```

### Permission Errors

```bash
# Fix file permissions
sudo chown -R $USER:$USER .
```

## Development Setup

### Running Tests

**No local installations required.** All tests run inside Docker containers.

```bash
# Run all tests (PHP + API + Frontend)
make test

# Run specific test suites
make test-unit        # PHP unit tests
make test-integration # PHP integration tests (needs running db)
make test-api         # FastAPI backend tests
make test-frontend    # React frontend tests

# Run static analysis
make analyse          # PHPStan for PHP
make lint-frontend    # ESLint for frontend

# Full CI pipeline
make ci
```

### Available Make Commands

```bash
make help    # Show all available commands
```

### Live Code Editing

The docker-compose.yml mounts source directories for live editing:
- PHP: Changes to `hygmap-php/src/` are reflected immediately
- API: Changes to `hygmap-api/` trigger auto-reload
- Frontend: Changes to `hygmap-frontend/src/` trigger hot reload

## Stopping HYGMap

```bash
# Stop all containers (data is preserved)
docker compose down

# Start again later
docker compose up -d
```

## Uninstalling

```bash
# Stop and remove everything including data
docker compose down --volumes

# Remove images
docker image rm hygmap-hygmap-db
docker image rm hygmap-hygmap-php
docker image rm hygmap-hygmap-api
docker image rm hygmap-hygmap-frontend
```

## Next Steps

- **Explore the interfaces** - See the [User Guide](user-guide.md)
- **Learn the API** - See the [API Reference](api.md)
- **Deploy to production** - See the [DigitalOcean Deployment Guide](digitalocean-deployment.md)
- **Contribute** - Add more fictional star names from your favorite sci-fi universes
