--
-- Import Bailer-Jones geometric distances for stars AT-HYG cannot place
--
-- Loads db/data/gaia_distances.csv, produced by db/scripts/fetch_gaia_distances.py from
-- VizieR I/352 (Bailer-Jones+ 2021, distances from Gaia EDR3 parallaxes).
--
-- Every distance source before this one stops at 100 pc: CNS5 is volume-complete to 25 pc,
-- GCNS to 100 pc, and past that AT-HYG is the only authority. Where its chosen parallax was
-- unusable there was no fallback, which left stars with either no distance at all or an
-- absolute magnitude brighter than anything real. Because /api/stars/search has no bounding
-- box and orders brightest-first, those artifacts came back first -- searching "Cen" used to
-- land the classic UI on one and return 503.
--
-- UPDATE-only, deliberately. Every target already exists in athyg, matched on the Gaia
-- source_id we already store, so this script never INSERTs and therefore cannot repeat the
-- new-ID collision bug that silently dropped CNS5 designations (see CATALOG-ID-INTEGRITY).
--

CREATE TEMP TABLE gaia_dist_stage (
  athyg_id     INTEGER,
  gaia         TEXT,
  dist         REAL,
  rpgeo        REAL,
  old_dist     REAL,
  old_absmag   REAL,
  old_dist_src TEXT,
  mag          REAL
);

\COPY gaia_dist_stage FROM '/data/gaia_distances.csv' WITH (FORMAT csv, HEADER true, NULL '');

--
-- Adopt the distance, and recompute everything derived from it in the same statement.
--
-- absmag must move with dist or the two contradict each other -- that inconsistency is the
-- whole defect being fixed here. The equatorial coordinates are rebuilt from the new
-- distance because they were cleared (unknown-distance stars) or computed from a bogus one.
--
-- dist_src gets its own code rather than reusing AT-HYG's 'G_R3'. Both are Gaia-derived, but
-- 'G_R3' means "AT-HYG's own value" and conflating them would make it impossible to tell
-- whose number is on screen.
--
UPDATE athyg a
SET    dist     = s.dist,
       dist_src = 'GAIA_BJ',
       absmag   = a.mag - 5 * log(s.dist) + 5,          -- absmag = mag - 5*log10(d/10)
       x_eq     = s.dist * cos(radians(a.dec)) * cos(radians(a.ra * 15)),
       y_eq     = s.dist * cos(radians(a.dec)) * sin(radians(a.ra * 15)),
       z_eq     = s.dist * sin(radians(a.dec))
FROM   gaia_dist_stage s
WHERE  s.athyg_id = a.id
  AND  s.dist IS NOT NULL
  AND  s.dist > 0
  AND  a.mag IS NOT NULL       -- no apparent magnitude, no derivable absolute magnitude
  AND  a.ra IS NOT NULL
  AND  a.dec IS NOT NULL;

DO $$
BEGIN
  RAISE NOTICE 'Adopted Bailer-Jones distances for % stars.',
    (SELECT COUNT(*) FROM athyg WHERE dist_src = 'GAIA_BJ');
END $$;

--
-- Rebuild galactic coordinates from the new equatorial ones. Same matrix as
-- 03_import_data.sql; if that transform ever changes, change it here too.
--
UPDATE athyg
SET
  x = -0.055 * x_eq - 0.8734 * y_eq - 0.4839 * z_eq,
  y =  0.494 * x_eq - 0.4449 * y_eq + 0.747  * z_eq,
  z = -0.8677 * x_eq - 0.1979 * y_eq + 0.4560 * z_eq
WHERE dist_src = 'GAIA_BJ';

DROP TABLE gaia_dist_stage;

ANALYZE athyg;

DO $$
DECLARE
  corrected INTEGER;
  still_implausible INTEGER;
BEGIN
  SELECT COUNT(*) INTO corrected FROM athyg WHERE dist_src = 'GAIA_BJ';
  SELECT COUNT(*) INTO still_implausible FROM athyg WHERE absmag < -10;
  RAISE NOTICE 'Gaia distances: % corrected, % stars still implausibly luminous.',
    corrected, still_implausible;
  RAISE NOTICE 'Remaining cases have no Gaia parallax at all (bright stars such as Polis);';
  RAISE NOTICE 'they need a literature or association distance, not an astrometric one.';
END $$;
