-- 1. Create a specific ENUM type for the signal direction
DROP TYPE IF EXISTS signal_type CASCADE;
CREATE TYPE signal_type AS ENUM ('receive', 'transmit');

-- 2. Create the final 'signals' table
DROP TABLE IF EXISTS signals;
CREATE TABLE signals (
    id            INTEGER PRIMARY KEY,
    name          TEXT,
    type          signal_type,
    time          TIMESTAMPTZ,
    ra            DOUBLE PRECISION,
    dec           DOUBLE PRECISION,
    frequency     DOUBLE PRECISION,
    notes         TEXT,
    x             DOUBLE PRECISION,
    y             DOUBLE PRECISION,
    z             DOUBLE PRECISION,
    last_updated  TIMESTAMPTZ
);

-- 3. Create a temporary staging table to load the raw CSV data
DROP TABLE IF EXISTS signals_stage;
CREATE TABLE signals_stage (
    id        INTEGER,
    name      TEXT,
    type      TEXT,
    time      TIMESTAMPTZ,
    ra        DOUBLE PRECISION,
    dec       DOUBLE PRECISION,
    frequency DOUBLE PRECISION,
    notes     TEXT
);

-- 4. Copy the CSV data into the staging table
COPY signals_stage
FROM '/data/signals.csv'
WITH (FORMAT CSV, HEADER);

-- 5. Insert from the staging table into the final table, with calculations
INSERT INTO signals (
    id, name, type, time, ra, dec, frequency, notes, x, y, z, last_updated
)
WITH calculated_equatorial AS (
    -- This CTE calculates the signal's position in equatorial coordinates
    SELECT
        s.*,
        (EXTRACT(EPOCH FROM (NOW() AT TIME ZONE 'UTC' - s.time)) / (365.25 * 24 * 60 * 60)) / 3.26156 AS dist_pc,
        CASE
            WHEN s.type = 'transmit' THEN radians(s.ra * 15.0)
            WHEN s.type = 'receive' THEN radians(MOD((s.ra + 12.0)::numeric, 24.0) * 15.0)
        END AS ra_rad,
        CASE
            WHEN s.type = 'transmit' THEN radians(s.dec)
            WHEN s.type = 'receive' THEN radians(-s.dec)
        END AS dec_rad
    FROM
        signals_stage s
),
galactic_coords AS (
    -- This CTE calculates the initial equatorial x,y,z
    SELECT
        *,
        dist_pc * cos(dec_rad) * cos(ra_rad) AS x_eq,
        dist_pc * cos(dec_rad) * sin(ra_rad) AS y_eq,
        dist_pc * sin(dec_rad) AS z_eq
    FROM
        calculated_equatorial
)
SELECT
    id,
    name,
    CASE WHEN type = 'transmit' THEN 'transmit'::signal_type ELSE 'receive'::signal_type END,
    time,
    ra,
    dec,
    frequency,
    notes,
    -- Apply the conversion matrix to get final galactic coordinates
    (-0.055 * x_eq - 0.8734 * y_eq - 0.4839 * z_eq) AS x,
    ( 0.494 * x_eq - 0.4449 * y_eq + 0.747  * z_eq) AS y,
    (-0.8677 * x_eq - 0.1979 * y_eq + 0.4560 * z_eq) AS z,
    NOW() AT TIME ZONE 'UTC' AS last_updated
FROM
    galactic_coords;

-- 6. Clean up the staging table
DROP TABLE signals_stage;

DO $$
BEGIN
  RAISE NOTICE 'Imported signals.';
END $$;