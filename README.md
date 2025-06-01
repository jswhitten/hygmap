# HYGMap

Interactive 3D star mapping application showing nearby stars with real astronomical data and fictional sci-fi locations.

![HygMap Screenshot](screenshot.png)

## Features

- **2,500,000+ stars** from the AT-HYG Database
- **3D galactic visualization** with zoom, pan, and filter controls
- **Fictional star names** from sci-fi universes (Star Trek, Babylon 5, etc.)
- **Real astronomical data** including spectral types, magnitudes, distances
- **Multiple view modes** - 2D galactic plane, 3D stereoscopic, printable
- **Fast PostgreSQL backend** with spatial indexing
- **Dockerized deployment** - runs anywhere

## Quick Start

```bash
git clone https://github.com/jswhitten/hygmap.git
cd hygmap
cp .env.example .env
# Edit .env with your desired database credentials
# Download AT-HYG CSV files to db/data directory
docker-compose up -d --build
```

Open http://localhost to start exploring the galaxy!

## How It Works

HYGMap displays stars in **galactic coordinates** centered on our solar system:

- **X-axis:** Points toward galactic center (~26,700 light years away)
- **Y-axis:** Points toward 90° galactic longitude (Cygnus direction) 
- **Z-axis:** Points "up" from the galactic plane
- **Origin (0,0,0):** Our Sun's position

The interface shows an overhead view of the galactic plane, with stars colored by spectral type and sized by brightness.

## Data Sources

- **[HYG Database](https://github.com/astronexus/HYG-Database)** - Compiled by David Nash
  - Hipparcos Catalog (satellite measurements)
  - Yale Bright Star Catalog (5th Edition)
  - Gliese Catalog of Nearby Stars (3rd Edition)

  I am using the new AT-HYG version of the database which includes over 2.5 million stars from the Tycho catalog and distances from Gaia

## Documentation

- **[Setup Guide](docs/setup.md)** - Installation and configuration
- **[User Guide](docs/user-guide.md)** - How to navigate and use the star map
- **[Docker Commands](docs/docker-commands.md)** - Container management

## Technology Stack

- **Frontend:** PHP (GD library for image generation)
- **Backend:** PostgreSQL with spatial indexing
- **Deployment:** Docker containers

## Requirements

- Docker and Docker Compose
- 2GB RAM minimum
- 5GB disk space for star database

## Contributing

Contributions welcome! Areas of interest:
- Additional sci-fi universe star names
- UI improvements  
- Performance optimizations
- Documentation improvements
