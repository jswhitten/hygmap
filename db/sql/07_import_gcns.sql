--
-- Import pre-matched GCNS (Gaia Catalogue of Nearby Stars) data
--
-- This script loads gcns.csv produced by db/scripts/match_gcns.py.
-- Matched rows UPDATE existing athyg records (backfilling Gaia IDs, distances, etc.).
-- New rows INSERT as fresh athyg entries with computed coordinates.
-- Idempotent: ON CONFLICT DO NOTHING for inserts, WHERE ... IS NULL for updates.
--

CREATE TEMP TABLE gcns_stage (
  athyg_id    INTEGER,
  match_method VARCHAR,
  source_id   VARCHAR,
  ra_j2000    DOUBLE PRECISION,
  dec_j2000   DOUBLE PRECISION,
  dist        REAL,
  mag         REAL,
  absmag      REAL,
  spect       VARCHAR,
  pm_ra       REAL,
  pm_dec      REAL,
  x_eq        REAL,
  y_eq        REAL,
  z_eq        REAL,
  prob100     REAL,
  probastr    REAL,
  bright_unmatched INTEGER
);

COPY gcns_stage
FROM '/data/gcns.csv'
WITH (FORMAT csv, HEADER true, NULL '', DELIMITER ',');

DO $$
BEGIN
  RAISE NOTICE 'Loaded % GCNS rows into staging table.',
    (SELECT COUNT(*) FROM gcns_stage);
END $$;

--
-- UPDATE matched rows: add Gaia source_id where missing
-- (This backfills athyg.gaia for stars matched by position that didn't have one)
--
UPDATE athyg a
SET    gaia = s.source_id
FROM   gcns_stage s
WHERE  s.athyg_id = a.id
  AND  s.match_method != 'new'
  AND  a.gaia IS NULL
  AND  s.source_id IS NOT NULL
  AND  s.source_id != '';

DO $$
BEGIN
  RAISE NOTICE 'Backfilled Gaia IDs for matched stars.';
END $$;

--
-- UPDATE matched rows: take GCNS's distance when ours is missing OR implausible
--
-- GCNS is volume-complete to 100 pc, so a star it lists cannot be further than that.
-- This step used to require `a.dist IS NULL`, which meant a wrong AT-HYG value blocked a
-- correct GCNS one -- and the correct one was already sitting in the CSV. The worst case
-- was CNS5 788 / GJ 125, an M2 dwarf: AT-HYG 97009.6 pc, CNS5 17.19 pc, a factor of 3900,
-- with absmag -9.68 instead of +7.29.
--
-- The threshold is evidence-based, not arbitrary. Comparing every GCNS-designated star
-- against AT-HYG (measured 2026-07-30):
--
--     within 2%      329,605 (99.98%)
--     2-10%          24
--     10-50%         30
--     50-200%        3
--     over 200%      1   <-- all of these are bad Gaia parallaxes
--
-- The bulk agrees to within 2% and the contradictions sit past 200% with a clear gap
-- below, so overriding above a factor of 2 is surgical: it touches the contradictions and
-- nothing else.
--
-- dist and absmag are set in ONE statement deliberately. absmag was derived from the old
-- distance, so adopting a new distance without it would leave the two inconsistent -- and
-- it cannot be done in a following statement, because by then a.dist already equals s.dist
-- and the disagreement test no longer fires. COALESCE keeps our value if GCNS has none.
--
UPDATE athyg a
SET    dist = s.dist,
       absmag = COALESCE(s.absmag, a.absmag),
       -- Record where the adopted value came from. Without this the data cannot say
       -- whether a distance is AT-HYG's or ours, which makes the quality check unable to
       -- tell an accepted override from an unresolved contradiction.
       dist_src = 'GCNS'
FROM   gcns_stage s
WHERE  s.athyg_id = a.id
  AND  s.match_method != 'new'
  AND  s.dist IS NOT NULL
  AND  s.dist > 0
  AND  (
         a.dist IS NULL
         OR abs(a.dist - s.dist) / s.dist > 1.0   -- disagrees by more than a factor of 2
       );

--
-- UPDATE matched rows: backfill absolute magnitude where missing
--
UPDATE athyg a
SET    absmag = s.absmag
FROM   gcns_stage s
WHERE  s.athyg_id = a.id
  AND  s.match_method != 'new'
  AND  a.absmag IS NULL
  AND  s.absmag IS NOT NULL;

--
-- UPDATE matched rows: backfill spectral type where missing
--
UPDATE athyg a
SET    spect = s.spect
FROM   gcns_stage s
WHERE  s.athyg_id = a.id
  AND  s.match_method != 'new'
  AND  a.spect IS NULL
  AND  s.spect IS NOT NULL
  AND  s.spect != '';

DO $$
BEGIN
  RAISE NOTICE 'Backfilled dist/absmag/spect for matched stars.';
END $$;

--
-- Recompute coordinates for matched stars that just got distance backfilled
--
UPDATE athyg
SET
  x_eq = dist * cos(radians(dec)) * cos(radians(ra * 15)),
  y_eq = dist * cos(radians(dec)) * sin(radians(ra * 15)),
  z_eq = dist * sin(radians(dec))
WHERE gaia IS NOT NULL AND id < 6000000
  AND (x_eq IS NULL OR y_eq IS NULL OR z_eq IS NULL)
  AND ra IS NOT NULL AND dec IS NOT NULL AND dist IS NOT NULL;

UPDATE athyg
SET
  x = -0.055  * x_eq - 0.8734 * y_eq - 0.4839 * z_eq,
  y =  0.494  * x_eq - 0.4449 * y_eq + 0.747  * z_eq,
  z = -0.8677 * x_eq - 0.1979 * y_eq + 0.4560 * z_eq
WHERE gaia IS NOT NULL AND id < 6000000
  AND x_eq IS NOT NULL AND y_eq IS NOT NULL AND z_eq IS NOT NULL
  AND (x IS NULL OR y IS NULL OR z IS NULL);

--
-- INSERT new stars (match_method = 'new')
-- These are GCNS stars not matched to any existing athyg record
--
-- dist_src set explicitly; see the note on the equivalent INSERT in 06_import_cns5.sql.
INSERT INTO athyg (id, gaia, ra, dec, dist, dist_src, mag, absmag, spect, x_eq, y_eq, z_eq)
SELECT
  s.athyg_id,
  NULLIF(s.source_id, ''),
  s.ra_j2000,
  s.dec_j2000,
  s.dist,
  'GCNS',
  s.mag,
  s.absmag,
  NULLIF(s.spect, ''),
  s.x_eq,
  s.y_eq,
  s.z_eq
FROM gcns_stage s
WHERE s.match_method = 'new'
ON CONFLICT (id) DO NOTHING;

DO $$
DECLARE
  new_count INTEGER;
BEGIN
  SELECT COUNT(*) INTO new_count FROM athyg WHERE id >= 6000000;
  RAISE NOTICE 'Inserted % new GCNS stars.', new_count;
END $$;

--
-- Compute equatorial coordinates for new rows missing them
--
UPDATE athyg
SET
  x_eq = dist * cos(radians(dec)) * cos(radians(ra * 15)),
  y_eq = dist * cos(radians(dec)) * sin(radians(ra * 15)),
  z_eq = dist * sin(radians(dec))
WHERE id >= 6000000
  AND (x_eq IS NULL OR y_eq IS NULL OR z_eq IS NULL)
  AND ra IS NOT NULL AND dec IS NOT NULL AND dist IS NOT NULL;

--
-- Compute galactic coordinates for new rows
-- Same rotation matrix as 03_import_data.sql
--
UPDATE athyg
SET
  x = -0.055  * x_eq - 0.8734 * y_eq - 0.4839 * z_eq,
  y =  0.494  * x_eq - 0.4449 * y_eq + 0.747  * z_eq,
  z = -0.8677 * x_eq - 0.1979 * y_eq + 0.4560 * z_eq
WHERE id >= 6000000
  AND x_eq IS NOT NULL AND y_eq IS NOT NULL AND z_eq IS NOT NULL
  AND (x IS NULL OR y IS NULL OR z IS NULL);

DO $$
DECLARE
  coord_count INTEGER;
BEGIN
  SELECT COUNT(*) INTO coord_count
  FROM athyg WHERE id >= 6000000 AND x IS NOT NULL;
  RAISE NOTICE 'New stars with galactic coordinates: %', coord_count;
END $$;

-- Reset the sequence for auto-increment
SELECT setval('athyg_id_seq', (SELECT COALESCE(MAX(id), 1) FROM athyg));

-- Update statistics for query optimization
ANALYZE athyg;

-- Display GCNS import summary
DO $$
DECLARE
  total_gaia INTEGER;
  matched INTEGER;
  new_stars INTEGER;
  with_coords INTEGER;
  new_with_spect INTEGER;
  matched_with_spect INTEGER;
  bright_unmatched_new INTEGER;
BEGIN
  SELECT COUNT(*) INTO total_gaia FROM athyg WHERE gaia IS NOT NULL;
  SELECT COUNT(*) INTO matched FROM gcns_stage WHERE match_method != 'new';
  SELECT COUNT(*) INTO new_stars FROM gcns_stage WHERE match_method = 'new';
  SELECT COUNT(*) INTO with_coords
    FROM athyg WHERE id >= 6000000 AND x IS NOT NULL;
  SELECT COUNT(*) INTO new_with_spect
    FROM athyg WHERE id >= 6000000 AND spect IS NOT NULL;
  SELECT COUNT(*) INTO matched_with_spect
    FROM athyg WHERE gaia IS NOT NULL AND id < 6000000 AND spect IS NOT NULL;
  SELECT COUNT(*) INTO bright_unmatched_new
    FROM gcns_stage WHERE match_method = 'new' AND bright_unmatched = 1;

  RAISE NOTICE '';
  RAISE NOTICE '=== GCNS Import Summary ===';
  RAISE NOTICE 'Stars with Gaia IDs:        %', total_gaia;
  RAISE NOTICE 'Matched (updated):          %', matched;
  RAISE NOTICE 'New (inserted):             %', new_stars;
  RAISE NOTICE 'Bright unmatched (new):     %', bright_unmatched_new;
  RAISE NOTICE 'New stars with galactic coords: %', with_coords;
  RAISE NOTICE 'New stars with spect type:  %', new_with_spect;
  RAISE NOTICE 'Matched stars with spect:   %', matched_with_spect;
  RAISE NOTICE 'Total athyg rows:           %', (SELECT COUNT(*) FROM athyg);
END $$;
