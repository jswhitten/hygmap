# HYGMap Database Schema

HYGMap uses PostgreSQL to store star data from the AT-HYG database and fictional star names from various sci-fi universes.

## Tables

### `athyg` - Main Star Catalog

Contains 2.84 million stars (2,839,957 as of AT-HYG 4.0) from multiple astronomical catalogs.

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
| `star_id` | INTEGER | References `athyg.id` (foreign key, `ON DELETE CASCADE`) |
| `world_id` | INTEGER | References `fic_worlds.id` |
| `name` | TEXT | Fictional name |
| `notes` | TEXT | Optional notes/description |

### How fictional names survive a catalog upgrade

`fic.star_id` points at `athyg.id`, which looks fragile because catalog ids are reassigned
wholesale on every AT-HYG release — 100% of comparable stars were renumbered between v3.3
and 4.0. Fictional names are nonetheless safe, for a reason worth knowing before changing
anything here: **`star_id` is derived, not stored.** `04_import_fic.sql` drops and rebuilds
the table on every image build, resolving each id by joining `athyg` on the **Tycho-2 id**
held in `db/data/athyg_tycho_*.csv`. Tycho ids do not move.

The foreign key added 2026-07-30 guards a different failure — a partial or reordered import
leaving names attached to the wrong stars — not the renumbering. Verified after a clean
rebuild: 191 rows, zero orphans, and Vulcan → Keid (40 Eridani), Babylon 5 → Ran
(Epsilon Eridani).

### Catalog import safety

Two protections exist because the pipeline has silently lost a designation before. CNS5 723
(Teegarden's Star) went missing for months when `athyg_supplement.csv` was renumbered and
`cns5.csv` was not regenerated: matched updates then targeted rows that did not exist, and an
unrelated new star overwrote the id.

- **The matcher scripts refuse to emit a CSV containing a duplicate `athyg_id`**, and their
  new-id allocators step over ids already claimed by a real match or already present in
  `athyg`. Regression tests in `db/scripts/test_match_cns5.py` cover the exact historical
  collision.
- **The import scripts refuse to run against a stale CSV.** If a matched row references an
  `athyg_id` that does not exist, `06`/`07` raise rather than silently applying nothing.

Neither condition is detectable after the fact — the import and the application both behave
normally — so both checks fail loudly by design.

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

-- Name search (trigram; see below)
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE INDEX idx_athyg_proper_trgm
  ON athyg USING gin ((LOWER(COALESCE(proper, ''))) gin_trgm_ops);
CREATE INDEX idx_athyg_bayer_con_trgm
  ON athyg USING gin ((LOWER(COALESCE(bayer, '') || ' ' || COALESCE(con, ''))) gin_trgm_ops);
CREATE INDEX idx_athyg_flam_con_trgm
  ON athyg USING gin ((LOWER(COALESCE(flam, '') || ' ' || COALESCE(con, ''))) gin_trgm_ops);
CREATE INDEX idx_athyg_con_trgm
  ON athyg USING gin ((LOWER(COALESCE(con, ''))) gin_trgm_ops);
```

### Trigram indexes for name search

`/api/stars/search` matches names with `LIKE '%term%'`. A leading wildcard makes every
B-tree index unusable, including `idx_athyg_proper_lower`, so the query fell back to a
parallel sequential scan of the whole table — **1.2–3.4 seconds per search**, measured
at 2.83M rows. The `pg_trgm` GIN indexes above bring that to **0.04–0.07 seconds** with
identical result sets.

Two things to know before changing them:

- **The indexed expression must match the query predicate exactly.** These mirror the
  `WHERE` clause in `hygmap-api/app/api/stars.py` character for character. Change one
  and you must change the other, then confirm with `EXPLAIN ANALYZE` that the planner
  still picks the index — a mismatched expression is silently ignored, and the only
  symptom is that search gets slow again.
- **Trigrams need three characters.** A `'%xx%'` pattern shorter than that produces no
  usable trigram, so every bitmap index scan returns all ~2.8M rows and the query ends
  up *slower* than the scan the index replaced. The API therefore anchors terms under
  three characters to a prefix (`'xx%'`), which does yield an indexable trigram. That is
  why a two-letter search matches the start of a name rather than anywhere inside it.

Cost, measured at 2.83M rows: about 16 seconds of build time and 48MB on disk (15MB each
for the three concatenated-expression indexes, 3MB for `proper`) against a 1795MB table.
Writes happen only at import, so the trade is one-sided here.

### Known limits of `dist` and `absmag`

Distances come from the source catalog and are not uniformly trustworthy. Three things to
know before relying on them:

**An unusable parallax used to arrive as a distance of 100,000 pc.** HYG floors a
non-positive or missing parallax at 0.01 mas, which inverts to exactly 100000 pc. It is a
placeholder, not a measurement, and it is documented nowhere upstream. 143 rows carried it,
all with `dist_src = 'H'`. The import now clears `dist`, `absmag` and the coordinates for
these — a star with no distance has no position on a 3D map — while keeping `ra`/`dec`, so
the direction is still on record. Such stars are excluded from list and search results
because they cannot be drawn; a direct `/api/stars/{id}` request still returns them with
nulls.

Left as data this did real damage: `absmag` is derived from the distance, so these stars
had absolute magnitudes down to −16.16, and because `/api/stars/search` has no bounding box
and orders brightest-first, they came back *first*. Searching "Cen" landed the classic UI on
one of them, and since its coordinates were ~100 kpc out the resulting map request exceeded
the API's coordinate limit and the page returned 503.

**CNS5 and GCNS override an implausible AT-HYG distance.** Both are volume-complete (25 pc
and 100 pc), so a star they list cannot be further away than that. Where AT-HYG disagrees by
more than a factor of two, the catalogue's value wins and `dist_src` records which one
supplied it. Comparing every CNS5 star against AT-HYG: 97.6% agree within 2%, and the 15
contradictions past 200% were all bad Gaia DR3 parallaxes — the worst being an M2 dwarf
AT-HYG placed at 97,010 pc that CNS5 puts at 17.19 pc.

**Bailer-Jones distances fill the gap beyond 100 pc.** `08_import_gaia_distances.sql` adopts
median geometric distances from VizieR I/352 (Bailer-Jones+ 2021) for stars AT-HYG could not
place, marking them `dist_src = 'GAIA_BJ'` — deliberately distinct from AT-HYG's own
`'G_R3'`, so it is always clear whose number is on screen. `absmag` and the coordinates are
recomputed from the adopted distance in the same step. 77 stars corrected; the implausible
count fell from 53 to 1.

The geometric distance (`rgeo`) is used rather than the photogeometric one (`rpgeo`), which
is generally more precise but assumes the star lies where the colour-magnitude diagram
expects. This target list is made of exactly the stars that do not — bright supergiants,
binaries and astrometrically difficult objects. `rpgeo` is recorded in the CSV for reference.

**What still cannot be fixed.** Bailer-Jones needs a Gaia parallax, and 87 of the 164
candidates have none. These are mostly bright Hipparcos stars: Gaia's astrometry degrades
above roughly magnitude 3–4. Polis (μ Sagittarii) is the type case — Gaia DR3 lists it with
photometry and an **empty** parallax, and SIMBAD carries only the Hipparcos value of
0.09 ± 0.28 mas, a parallax smaller than its own error. Such stars need a literature or
association distance, which nothing in this pipeline supplies. One further star (id 364061)
has a Gaia DR2-derived distance with no entry in the EDR3-based catalogue.

Stars left without a distance keep their `ra`/`dec` but have no position, so they are
excluded from list and search results. That means a handful of real, named stars — Polis
among them — are currently not findable by search.

Run `db/scripts/check_distance_quality.py` after an import to verify all of the above; it
exits non-zero if any check regresses.

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
