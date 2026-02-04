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

Then install Python dependencies (a venv is recommended):

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

The base star database (~2.5M stars) is downloaded automatically during the
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
