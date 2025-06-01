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
# Edit .env with your desired database credentials. Make sure you at least change the value of POSTGRES_PASSWORD.
vi .env
# Download AT-HYG CSV files to db/data directory
cd db/data
curl -L -O https://codeberg.org/astronexus/athyg/raw/branch/main/data/athyg_v32-{1..2}.csv.gz
gunzip *.csv.gz
# Build and start the containers. If the database doesn't exist it will automatically be created by the scripts in the db/sql directory.
docker compose up -d --build
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

- **[AT-HYG Database](https://codeberg.org/astronexus/athyg)** - Compiled by David Nash
  - Tycho-2, compiled by the Hipparcos mission
  - Gaia Data Release 3
  - The Hipparcos Catalog
  - The Yale Bright Star Catalog
  - The Gliese-Jahreiss Catalog
  - Star names from the IAU's official list of names.

The AT-HYG database includes over 2.5 million stars; essentially all known stars within 25 parsecs or brighter than magnitude 11.

## Documentation

- **[Setup Guide](docs/setup.md)** - Installation and configuration
- **[User Guide](docs/user-guide.md)** - How to navigate and use the star map
- **[Docker Commands](docs/docker-commands.md)** - Container management

## Technology Stack

- **Frontend:** PHP (GD library for image generation) and Apache
- **Backend:** PostgreSQL
- **Deployment:** Docker containers

## Requirements

- Docker
- 2GB RAM minimum
- 4GB disk space for star database + cache

## Contributing

Contributions welcome! Areas of interest:
- Additional sci-fi universe star names
- UI improvements  
- Performance optimizations
- Documentation improvements
