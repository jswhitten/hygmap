# HYGMap Database Schema

HYGMap uses PostgreSQL to store star data from the AT-HYG database and fictional star names from various sci-fi universes.

## Tables

### `athyg` - Main Star Catalog

Contains 2.5+ million stars from multiple astronomical catalogs.

| Column | Type | Description |
|--------|------|-------------|
| `id` | INTEGER | Primary key (auto-generated) |
| `tyc` | TEXT | Tycho-2 catalog ID (e.g., "9007-5848-1") |
| `gaia` | TEXT | Gaia DR3 source ID |
| `hyg` | INTEGER | Original HYG database ID |
| `hip` | TEXT | Hipparcos catalog number |
| `hd` | TEXT | Henry Draper catalog number |
| `hr` | TEXT | Harvard Revised (Yale Bright Star) number |
| `gj` | TEXT | Gliese-Jahreiss catalog ID (e.g., "581", "667C") |
| `bayer` | TEXT | Bayer designation letter (e.g., "Alp", "Bet") |
| `flam` | TEXT | Flamsteed number (e.g., "61", "70") |
| `con` | TEXT | Constellation abbreviation (e.g., "Cyg", "Cen") |
| `proper` | TEXT | IAU proper name (e.g., "Sirius", "Vega") |
| `ra` | DOUBLE PRECISION | Right ascension (hours) |
| `dec` | DOUBLE PRECISION | Declination (degrees) |
| `pos_src` | TEXT | Position data source |
| `dist` | REAL | Distance from Sol (parsecs) |
| `x` | REAL | Galactic X coordinate (parsecs) |
| `y` | REAL | Galactic Y coordinate (parsecs) |
| `z` | REAL | Galactic Z coordinate (parsecs) |
| `x_eq` | REAL | Equatorial X coordinate |
| `y_eq` | REAL | Equatorial Y coordinate |
| `z_eq` | REAL | Equatorial Z coordinate |
| `dist_src` | TEXT | Distance data source |
| `mag` | REAL | Apparent magnitude |
| `absmag` | REAL | Absolute magnitude |
| `mag_src` | TEXT | Magnitude data source |
| `spect` | TEXT | Spectral type (e.g., "G2V", "M4.5V") |
| `spect_src` | TEXT | Spectral type data source |

### `fic_worlds` - Fictional Universes

Defines the sci-fi universes available for fictional star names.

| Column | Type | Description |
|--------|------|-------------|
| `id` | SERIAL | Primary key |
| `name` | TEXT | Universe name (e.g., "Star Trek", "Babylon 5") |

### `fic` - Fictional Star Names

Maps real stars to their fictional names in various universes.

| Column | Type | Description |
|--------|------|-------------|
| `id` | SERIAL | Primary key |
| `star_id` | INTEGER | References `athyg.id` |
| `world_id` | INTEGER | References `fic_worlds.id` |
| `name` | TEXT | Fictional name |
| `notes` | TEXT | Optional notes/description |

### `signals` - SETI Signal Data

Contains historical SETI transmissions and notable received signals.

| Column | Type | Description |
|--------|------|-------------|
| `id` | INTEGER | Primary key |
| `name` | TEXT | Signal name (e.g., "Arecibo Message", "Wow! Signal") |
| `type` | signal_type | ENUM: 'transmit' or 'receive' |
| `time` | TIMESTAMPTZ | Date/time of signal transmission or reception |
| `ra` | DOUBLE PRECISION | Right ascension (hours) |
| `dec` | DOUBLE PRECISION | Declination (degrees) |
| `frequency` | DOUBLE PRECISION | Signal frequency (MHz) |
| `notes` | TEXT | Additional description or context |
| `x` | DOUBLE PRECISION | Calculated galactic X coordinate (parsecs) |
| `y` | DOUBLE PRECISION | Calculated galactic Y coordinate (parsecs) |
| `z` | DOUBLE PRECISION | Calculated galactic Z coordinate (parsecs) |
| `last_updated` | TIMESTAMPTZ | When the galactic coordinates were last calculated |

**Note:** The `x`, `y`, `z` coordinates are calculated based on the signal's direction and the time elapsed since transmission/reception. For transmitted signals, this represents how far the signal has traveled into space. For received signals, it represents the calculated origin direction.

## Indexes

The database includes indexes optimized for spatial queries and catalog lookups:

```sql
-- Spatial queries (bounding box + magnitude)
CREATE INDEX idx_athyg_galactic ON athyg(x, y, z);
CREATE INDEX idx_bbox_mag ON athyg (x, y, z, mag);

-- Catalog ID lookups
CREATE INDEX idx_athyg_hip ON athyg(hip) WHERE hip IS NOT NULL;
CREATE INDEX idx_athyg_hd ON athyg(hd) WHERE hd IS NOT NULL;
CREATE INDEX idx_athyg_gaia ON athyg(gaia) WHERE gaia IS NOT NULL;

-- Name lookups
CREATE INDEX idx_athyg_proper_lower ON athyg(LOWER(proper)) WHERE proper IS NOT NULL;
CREATE INDEX idx_athyg_bayer_con ON athyg (bayer, con);
CREATE INDEX idx_athyg_flam_con ON athyg (flam, con);
```

## Coordinate System

HYGMap uses galactic coordinates centered on Sol:

- **Origin (0, 0, 0):** Our Sun's position
- **X-axis:** Points toward galactic center (~26,700 ly away)
- **Y-axis:** Points toward 90° galactic longitude (Cygnus direction)
- **Z-axis:** Points "up" perpendicular to galactic plane

All coordinates are stored in **parsecs**. To convert:
- 1 parsec = 3.26156 light-years
- 1 parsec = 206,265 AU

## Common Queries

### Find a star by name

```sql
-- By proper name
SELECT * FROM athyg WHERE proper ILIKE 'sirius';

-- By Bayer designation
SELECT * FROM athyg WHERE bayer = 'Alp' AND con = 'CMa';

-- By Flamsteed number
SELECT * FROM athyg WHERE flam = '61' AND con = 'Cyg';

-- By catalog ID
SELECT * FROM athyg WHERE hip = '32349';
SELECT * FROM athyg WHERE hd = '48915';
SELECT * FROM athyg WHERE gj = '581';
```

### Query stars in a region

```sql
-- Stars within 10 parsecs of Sol, brighter than magnitude 10
SELECT * FROM athyg
WHERE x BETWEEN -10 AND 10
  AND y BETWEEN -10 AND 10
  AND z BETWEEN -10 AND 10
  AND absmag <= 10
ORDER BY absmag
LIMIT 1000;
```

### Get fictional names for a star

```sql
-- All fictional names for a star
SELECT f.name, w.name as universe
FROM fic f
JOIN fic_worlds w ON f.world_id = w.id
WHERE f.star_id = 12345;

-- Stars with fictional names from Star Trek
SELECT a.proper, a.con, f.name
FROM athyg a
JOIN fic f ON a.id = f.star_id
WHERE f.world_id = 1
ORDER BY f.name;
```

### Statistics

```sql
-- Total star count
SELECT COUNT(*) FROM athyg;

-- Stars by spectral class
SELECT LEFT(spect, 1) as class, COUNT(*)
FROM athyg
WHERE spect IS NOT NULL
GROUP BY LEFT(spect, 1)
ORDER BY COUNT(*) DESC;

-- Nearest stars
SELECT proper, dist, spect
FROM athyg
WHERE dist IS NOT NULL
ORDER BY dist
LIMIT 20;
```

### Query signals

```sql
-- All signals
SELECT name, type, time, frequency, notes FROM signals;

-- Transmitted signals only
SELECT name, time, frequency, x, y, z
FROM signals
WHERE type = 'transmit'
ORDER BY time;

-- Signals within a spatial region
SELECT name, type, time, x, y, z
FROM signals
WHERE x BETWEEN -100 AND 100
  AND y BETWEEN -100 AND 100
  AND z BETWEEN -100 AND 100;
```

## Connecting to the Database

```bash
# From the host (development)
docker compose exec hygmap-db psql -U hygmap_user -d hygmap

# Direct connection (if PostgreSQL client installed)
psql -h localhost -p 5432 -U hygmap_user -d hygmap
```

## Data Sources

The AT-HYG database combines data from:
- **Tycho-2 Catalog** - Positions and proper motions (Hipparcos mission)
- **Gaia Data Release 3** - High-precision astrometry
- **Hipparcos Catalog** - Parallaxes and photometry
- **Yale Bright Star Catalog** - Bright star data
- **Gliese-Jahreiss Catalog** - Nearby star census
- **IAU Star Names** - Official proper names

Source: [AT-HYG Database](https://codeberg.org/astronexus/athyg)
