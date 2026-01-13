# Contributing to HYGMap

Thank you for your interest in contributing to HYGMap! This guide will help you get started.

## Getting Started

1. Fork the repository on GitHub
2. Clone your fork locally
3. Follow the [Setup Guide](docs/setup.md) to get the application running
4. Create a branch for your changes

## Development Workflow

### Running Tests

Before submitting changes, ensure all tests pass:

```bash
# Install dependencies
make install

# Run static analysis
make analyse

# Run all tests
make test

# Or run the full CI pipeline
make ci
```

### Code Style

- Follow existing code patterns in the codebase
- Keep PHP files consistent with the existing style
- Use meaningful variable and function names
- Add comments for complex logic

## Types of Contributions

### Bug Fixes

1. Check existing issues to see if the bug is already reported
2. Create a branch: `git checkout -b fix/bug-description`
3. Write a test that reproduces the bug (if applicable)
4. Fix the bug
5. Ensure all tests pass
6. Submit a pull request

### New Features

1. Open an issue first to discuss the feature
2. Create a branch: `git checkout -b feature/feature-name`
3. Implement the feature with tests
4. Update documentation if needed
5. Submit a pull request

### Adding Fictional Universes

HYGMap supports star names from various sci-fi universes. To add a new universe:

#### 1. Prepare Your Data

Create a CSV file with three columns (no header row):
- `tyc_id` - Tycho-2 catalog ID (format: `XXXX-XXXXX-X`)
- `name` - Fictional star/system name
- `notes` - Optional notes or description

Example (`athyg_tycho_yourworld.csv`):
```csv
360-1226-1,New Terra,Colony world
7563-1016-1,Starbase Prime,Federation outpost
```

#### 2. Find Tycho IDs for Your Stars

To find the Tycho ID for a real star:

```sql
-- Connect to database
docker compose exec hygmap-db psql -U hygmap_user -d hygmap

-- Search by common name
SELECT tyc, proper, con FROM athyg WHERE proper ILIKE '%vega%';

-- Search by Bayer designation
SELECT tyc, bf, con FROM athyg WHERE bf ILIKE 'alp lyr%';

-- Search by coordinates (approximate)
SELECT tyc, proper, x, y, z FROM athyg
WHERE x BETWEEN -5 AND 5 AND y BETWEEN -5 AND 5 AND z BETWEEN -5 AND 5
ORDER BY mag LIMIT 20;
```

#### 3. Add the Import Script

Edit `db/sql/04_import_fic.sql`:

```sql
-- Add your world to fic_worlds (pick the next available ID)
INSERT INTO fic_worlds (id, name) VALUES
(1, 'Star Trek'),
(2, 'Babylon 5'),
(3, 'Your Universe');  -- Add this line

-- Add import section for your universe
CREATE TEMP TABLE temp_yourworld_import (
    tyc_id TEXT,
    name TEXT,
    notes TEXT
);

\COPY temp_yourworld_import(tyc_id, name, notes) FROM '/data/athyg_tycho_yourworld.csv' WITH (FORMAT csv, HEADER false, DELIMITER ',', NULL '');

INSERT INTO fic (star_id, world_id, name, notes)
SELECT athyg.id, 3, temp_yourworld_import.name, temp_yourworld_import.notes
FROM temp_yourworld_import
JOIN athyg ON athyg.tyc = temp_yourworld_import.tyc_id
WHERE athyg.tyc IS NOT NULL;

DROP TABLE temp_yourworld_import;
```

#### 4. Place Your CSV File

Put your CSV file in `db/data/athyg_tycho_yourworld.csv`

#### 5. Rebuild the Database

```bash
docker compose down --volumes
docker compose up -d --build
```

#### 6. Test Your Changes

1. Open HYGMap in your browser
2. Go to Configure
3. Select your new universe from the "Fictional names" dropdown
4. Verify your star names appear on the map

### Documentation Improvements

Documentation lives in the `docs/` directory. Feel free to:
- Fix typos or clarify confusing sections
- Add examples
- Improve the user guide
- Add missing documentation

## Pull Request Guidelines

1. Keep PRs focused on a single change
2. Write clear commit messages
3. Update documentation if your change affects user-facing features
4. Ensure all tests pass
5. Reference any related issues in your PR description

## Questions?

If you have questions about contributing, feel free to open an issue for discussion.
