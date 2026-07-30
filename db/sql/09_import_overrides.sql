--
-- Manual per-star overrides
--
-- Applies db/data/athyg_overrides.csv, the escape hatch for stars where every automated
-- source is wrong or silent. Runs LAST, after AT-HYG, CNS5, GCNS and Bailer-Jones, so it
-- genuinely wins.
--
-- The motivating case is Polis (mu Sagittarii). Gaia DR3 lists it with photometry and an
-- empty parallax -- normal above roughly magnitude 3-4 -- and Hipparcos gives a parallax
-- smaller than its own error, so no astrometric distance exists at all. Before this file
-- there was nowhere to put a known value: athyg_supplement.csv is additive, and reusing an
-- existing id there aborts the build on a primary-key violation.
--
-- THREE RULES, each of them a lesson from a defect this repo has already had:
--
-- 1. KEYED ON A STABLE IDENTIFIER, NEVER ON athyg.id. Ids come from the source catalog and
--    are reassigned wholesale on every release -- measured: 100% of comparable stars were
--    renumbered between AT-HYG v3.3 and 4.0. An override keyed on id would silently drift
--    onto a different star. Gaia source_id and HIP number do not move.
--
-- 2. EVERY ROW CARRIES ITS SOURCE. Hand-edited data with no provenance is exactly how a
--    CNS5 designation was silently lost (see CATALOG-ID-INTEGRITY). The source column is
--    for humans; it is not read by this script, but a row without one should not be added.
--
-- 3. IT FAILS LOUDLY. If a key matches nothing, or matches a star whose proper name is not
--    the one expected, the import aborts. A silently-ineffective override is how a stale
--    CSV went unnoticed for months. expect_proper is the tripwire: it makes a drifted or
--    mistyped key impossible to miss.
--
-- To add a field: extend the staging table and the UPDATE below. Leave a cell empty to mean
-- "do not touch".
--

CREATE TEMP TABLE override_stage (
  gaia          TEXT,
  hip           TEXT,
  expect_proper TEXT,
  dist          REAL,
  source        TEXT
);

\COPY override_stage FROM '/data/athyg_overrides.csv' WITH (FORMAT csv, HEADER true, NULL '');

-- Resolve each override to exactly one star, by Gaia source_id when present, else HIP.
CREATE TEMP TABLE override_resolved AS
SELECT o.*, a.id AS athyg_id, a.proper AS actual_proper
FROM   override_stage o
JOIN   athyg a
  ON   (o.gaia IS NOT NULL AND a.gaia = o.gaia)
    OR (o.gaia IS NULL AND o.hip IS NOT NULL AND a.hip = o.hip);

-- Rule 3: nothing may be silently ignored.
DO $$
DECLARE
  unmatched INTEGER;
  mismatched TEXT;
BEGIN
  SELECT COUNT(*) INTO unmatched
  FROM override_stage o
  WHERE NOT EXISTS (SELECT 1 FROM override_resolved r
                    WHERE r.gaia IS NOT DISTINCT FROM o.gaia
                      AND r.hip IS NOT DISTINCT FROM o.hip);
  IF unmatched > 0 THEN
    RAISE EXCEPTION 'athyg_overrides.csv: % row(s) matched no star. A stale or mistyped '
                    'identifier must not pass silently.', unmatched;
  END IF;

  SELECT string_agg(format('expected "%s" but id %s is "%s"',
                           expect_proper, athyg_id, COALESCE(actual_proper, '(unnamed)')), '; ')
  INTO   mismatched
  FROM   override_resolved
  WHERE  expect_proper IS NOT NULL
    AND  COALESCE(actual_proper, '') <> expect_proper;
  IF mismatched IS NOT NULL THEN
    RAISE EXCEPTION 'athyg_overrides.csv: identifier resolved to the wrong star -- %',
                    mismatched;
  END IF;
END $$;

--
-- Apply. dist_src marks the value as manual so it is never mistaken for a measurement, and
-- absmag and the coordinates are recomputed from it in the same statement -- a distance that
-- moves without them just recreates the inconsistency this project spent a day removing.
--
UPDATE athyg a
SET    dist     = r.dist,
       dist_src = 'OVERRIDE',
       absmag   = CASE WHEN a.mag IS NOT NULL THEN a.mag - 5 * log(r.dist) + 5 ELSE a.absmag END,
       x_eq     = r.dist * cos(radians(a.dec)) * cos(radians(a.ra * 15)),
       y_eq     = r.dist * cos(radians(a.dec)) * sin(radians(a.ra * 15)),
       z_eq     = r.dist * sin(radians(a.dec))
FROM   override_resolved r
WHERE  a.id = r.athyg_id
  AND  r.dist IS NOT NULL
  AND  r.dist > 0
  AND  a.ra IS NOT NULL
  AND  a.dec IS NOT NULL;

-- Same galactic matrix as 03_import_data.sql; change both together.
UPDATE athyg
SET
  x = -0.055 * x_eq - 0.8734 * y_eq - 0.4839 * z_eq,
  y =  0.494 * x_eq - 0.4449 * y_eq + 0.747  * z_eq,
  z = -0.8677 * x_eq - 0.1979 * y_eq + 0.4560 * z_eq
WHERE dist_src = 'OVERRIDE';

DO $$
BEGIN
  RAISE NOTICE 'Applied % manual star override(s).',
    (SELECT COUNT(*) FROM athyg WHERE dist_src = 'OVERRIDE');
END $$;

DROP TABLE override_resolved;
DROP TABLE override_stage;

ANALYZE athyg;
