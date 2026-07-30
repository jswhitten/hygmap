--
-- Fill in missing constellations
--
-- Loads db/data/constellations.csv, produced by db/scripts/compute_constellations.py.
--
-- 281,301 stars arrived from the CNS5 and GCNS imports with positions but no constellation,
-- which made them unfindable by a constellation search: searching "Cyg" cannot return a star
-- the database does not know is in Cygnus. Since a constellation is a pure function of sky
-- position, this is computed offline and exactly rather than looked up from a service.
--
-- UPDATE-only. Every target already exists, so this can never INSERT, which also makes it
-- structurally incapable of the ID-collision failure described in CATALOG-ID-INTEGRITY.
--

CREATE TEMP TABLE constellation_stage (
  athyg_id INTEGER,
  con      TEXT
);

\COPY constellation_stage FROM '/data/constellations.csv' WITH (FORMAT csv, HEADER true, NULL '');

--
-- Refuse a stale CSV, as 06 and 07 now do.
--
-- A row referencing an athyg_id that does not exist means the CSV was generated against a
-- different catalog state. Silence here would mean constellations quietly not being applied,
-- which is exactly how a CNS5 designation went missing for months.
--
DO $$
DECLARE
  stale INTEGER;
BEGIN
  SELECT COUNT(*) INTO stale
  FROM   constellation_stage s
  WHERE  NOT EXISTS (SELECT 1 FROM athyg a WHERE a.id = s.athyg_id);

  IF stale > 0 THEN
    RAISE EXCEPTION 'constellations.csv is stale: % row(s) reference an athyg_id that does '
                    'not exist. Regenerate it with db/scripts/compute_constellations.py.', stale;
  END IF;
END $$;

--
-- Only fill gaps. A constellation already present came from the source catalog, and this step
-- has no business overruling it: spot-checking found AT-HYG and astropy disagree on roughly
-- 0.007% of stars, all within about an arcsecond of a boundary, where the answer is arbitrary
-- at the precision the boundaries are defined to. Not worth churning 2.5M rows over.
--
UPDATE athyg a
SET    con = s.con
FROM   constellation_stage s
WHERE  s.athyg_id = a.id
  AND  a.con IS NULL
  AND  s.con IS NOT NULL;

DO $$
BEGIN
  RAISE NOTICE 'Filled constellations for % stars; % still missing one.',
    (SELECT COUNT(*) FROM constellation_stage),
    (SELECT COUNT(*) FROM athyg WHERE con IS NULL);
END $$;

DROP TABLE constellation_stage;

ANALYZE athyg;
