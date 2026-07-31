# Data Import Scripts

These scripts cross-match external star catalogues against the AT-HYG database
and produce CSV files for import into PostgreSQL. The output CSVs are already
committed to `db/data/`, so running these scripts is only necessary if you want
to regenerate them (e.g. after changing matching logic or spectral type estimation).

## Prerequisites

The database container must be running with the base AT-HYG data loaded:

```
docker compose up -d hygmap-db
```

Then install Python dependencies. **Create the venv locally — it is not in the repo.** It was
committed once (1,035 files, 26MB of platform-specific binaries) and untracked again on
2026-07-30; `.gitignore` now keeps it out.

```
cd db/scripts
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

## Source Data

The scripts expect fixed-width data files that are not checked into the repo.
Download them before running the scripts.

### AT-HYG (base catalogue)

The base star database (2.84M stars, AT-HYG 4.0) is downloaded automatically during the
Docker build. Source:

- https://codeberg.org/astronexus/athyg/

No manual download needed.

### CNS5 (Fifth Catalogue of Nearby Stars)

5,931 objects within 25 pc of the Sun. Based on Gaia EDR3, Hipparcos, and
ground-based parallaxes (Golovin et al. 2023, A&A 670, A19).

Download `cns5.dat` from the Heidelberg data center:

- https://dc.zah.uni-heidelberg.de/cns5/q/cone/form

Place the file in `db/scripts/cns5.dat`.

### GCNS (Gaia Catalogue of Nearby Stars)

331,312 objects within 100 pc of the Sun, published 2021 as part of Gaia EDR3.

Download `table1c.dat` from VizieR (catalog I/352):

1. Go to https://cdsarc.cds.unistra.fr/viz-bin/cat/I/352
2. Download the `table1c.dat` file

Place the file in `db/scripts/table1c.dat`.

## Running the Scripts

Run CNS5 first, then GCNS, since GCNS uses a higher ID range to avoid
collisions with CNS5 new-star IDs.

```
python match_cns5.py    # reads cns5.dat, writes ../data/cns5.csv
python match_gcns.py    # reads table1c.dat, writes ../data/gcns.csv
```

Each script prints an audit report showing match statistics when complete.

### Environment Variables

Both scripts accept environment variables for configuration:

| Variable | Default | Description |
|----------|---------|-------------|
| `DB_HOST` | `localhost` | PostgreSQL host |
| `DB_PORT` | `5432` | PostgreSQL port |
| `DB_NAME` | `hygmap` | Database name |
| `DB_USER` | `hygmap_user` | Database user |
| `DB_PASS` | `hygmap_pass` | Database password |
| `CNS5_INPUT` | `cns5.dat` | CNS5 input file path |
| `CNS5_OUTPUT` | `../data/cns5.csv` | CNS5 output file path |
| `GCNS_INPUT` | `table1c.dat` | GCNS input file path |
| `GCNS_OUTPUT` | `../data/gcns.csv` | GCNS output file path |

## Loading into the Database

After generating the CSVs, they are loaded into PostgreSQL by the init scripts
during a fresh database build:

- `db/sql/06_import_cns5.sql` loads `cns5.csv`
- `db/sql/07_import_gcns.sql` loads `gcns.csv`

To reload on an existing database, run the SQL files manually:

```
docker exec -i hygmap-db psql -U hygmap_user -d hygmap < ../sql/06_import_cns5.sql
docker exec -i hygmap-db psql -U hygmap_user -d hygmap < ../sql/07_import_gcns.sql
```

Or rebuild the database from scratch:

```
docker compose down -v
docker compose up -d --build
```

## Tests

```
python -m pytest test_match_cns5.py test_match_gcns.py -v
```

## Distance corrections

Two more steps exist beyond the catalog cross-matches, both following the same
script-then-SQL shape:

```
python fetch_gaia_distances.py   # queries VizieR I/352, writes ../data/gaia_distances.csv
python check_distance_quality.py # read-only; exits non-zero if a check regresses
```

`fetch_gaia_distances.py` supplies Bailer-Jones distances for stars AT-HYG cannot place —
every source before it stops at 100 pc, so a star with a broken parallax beyond that had no
fallback. `db/data/athyg_overrides.csv` is the last resort for stars no automated source can
reach at all (see `db/sql/09_import_overrides.sql`).

Run `check_distance_quality.py` after any import. It catches the three failure modes this
pipeline has actually had: an unknown-distance sentinel read as a measurement, a
volume-complete catalogue contradicted by AT-HYG, and physically impossible absolute
magnitudes.

## Duplicate identifiers

```
python check_duplicates.py       # read-only; exits non-zero if a check regresses
```

Reports rows sharing a Gaia DR3 source_id. The distinction it draws is the whole point:

- **Duplicates this pipeline created** must always be zero. `cns5.csv` and `gcns.csv` are
  both produced by cross-matching against the live database, and both were generated from
  the same pre-supplement snapshot — so neither could see what the other would insert, and
  2,598 stars were inserted twice in a single build. The `NOT EXISTS` guards on the two
  `new`-star inserts prevent that, and `07_import_gcns.sql` fails the build if it recurs.
- **Duplicates inherited from AT-HYG** are legitimate and are kept. Around 1,166 groups are
  real close binaries — Tycho-2 resolves both components, Gaia DR3 records one source, and
  88% carry distinct Tycho ids or explicit component designations (GJ 314A / GJ 314B).
  Merging them would delete real stars.
- **Rows sharing an id but more than a degree apart** are neither: the source_id is attached
  to the wrong star. AT-HYG 4.0 has one such case. Decode the id's HEALPix pixel
  (`source_id // 2**35`, nside 4096) to find which row it belongs to, then retract the other
  with `clear_gaia` in `db/data/athyg_overrides.csv`.

If a new AT-HYG release genuinely brings more binaries, raise `KNOWN_INHERITED_BASELINE` —
after confirming that is what they are.

## Constellations

```
python compute_constellations.py --verify   # self-check against known stars, no DB needed
python compute_constellations.py            # writes ../data/constellations.csv
```

Fills `con` for stars that arrived with positions but no constellation. Computed offline from
RA/Dec, not fetched — see docs/database.md for why the B1875/FK4 detail matters. `--verify`
checks both implementations against ten known stars and exits without touching the database.

This is the only script here that needs astropy. It runs once and commits its output, so the
dependency never reaches the application.

## A note on regenerating the cross-match CSVs

Both matcher scripts now refuse to write a CSV containing a duplicate `athyg_id`, and both
SQL imports refuse to run against a stale one. Those guards exist because CNS5 designation
723 was silently lost for months when `athyg_supplement.csv` was renumbered and `cns5.csv`
was not regenerated. If a matcher aborts with a duplicate-id error, that is the guard
working — do not remove it.
