--
-- Import pre-matched CNS5 (Fifth Catalogue of Nearby Stars) data
--
-- This script loads cns5.csv produced by db/scripts/match_cns5.py.
-- Matched rows UPDATE existing athyg records (adding GJ/CNS5/Gaia/HIP IDs).
-- New rows INSERT as fresh athyg entries with computed coordinates.
-- Idempotent: ON CONFLICT DO NOTHING for inserts, WHERE ... IS NULL for updates.
--

CREATE TEMP TABLE cns5_stage (
  athyg_id    INTEGER,
  match_method VARCHAR,
  cns5_id     INTEGER,
  gj          VARCHAR,
  gj_comp     VARCHAR,
  gaia        VARCHAR,
  hip         VARCHAR,
  ra_j2000    DOUBLE PRECISION,
  dec_j2000   DOUBLE PRECISION,
  dist        REAL,
  mag         REAL,
  absmag      REAL,
  spect       VARCHAR,
  rv          REAL,
  pm_ra       REAL,
  pm_dec      REAL,
  x_eq        REAL,
  y_eq        REAL,
  z_eq        REAL,
  bright_unmatched INTEGER
);

COPY cns5_stage
FROM '/data/cns5.csv'
WITH (FORMAT csv, HEADER true, NULL '', DELIMITER ',');

DO $$
BEGIN
  RAISE NOTICE 'Loaded % CNS5 rows into staging table.',
    (SELECT COUNT(*) FROM cns5_stage);
END $$;

--
-- UPDATE matched rows: add CNS5 ID where missing
--
UPDATE athyg a
SET    cns5 = s.cns5_id::TEXT
FROM   cns5_stage s
WHERE  s.athyg_id = a.id
  AND  s.match_method != 'new'
  AND  a.cns5 IS NULL;

DO $$
BEGIN
  RAISE NOTICE 'Updated CNS5 IDs for matched stars: % rows.',
    (SELECT COUNT(*) FROM athyg WHERE cns5 IS NOT NULL);
END $$;

--
-- UPDATE matched rows: add GJ number where missing
--
UPDATE athyg a
SET    gj = CONCAT(s.gj, s.gj_comp)
FROM   cns5_stage s
WHERE  s.athyg_id = a.id
  AND  s.match_method != 'new'
  AND  a.gj IS NULL
  AND  s.gj IS NOT NULL
  AND  s.gj != '';

DO $$
BEGIN
  RAISE NOTICE 'Backfilled GJ numbers for matched stars.';
END $$;

--
-- UPDATE matched rows: backfill Gaia ID where missing
--
UPDATE athyg a
SET    gaia = s.gaia
FROM   cns5_stage s
WHERE  s.athyg_id = a.id
  AND  s.match_method != 'new'
  AND  a.gaia IS NULL
  AND  s.gaia IS NOT NULL
  AND  s.gaia != '';

--
-- UPDATE matched rows: backfill HIP ID where missing
--
UPDATE athyg a
SET    hip = s.hip
FROM   cns5_stage s
WHERE  s.athyg_id = a.id
  AND  s.match_method != 'new'
  AND  a.hip IS NULL
  AND  s.hip IS NOT NULL
  AND  s.hip != '';

DO $$
BEGIN
  RAISE NOTICE 'Backfilled Gaia/HIP IDs for matched stars.';
END $$;

--
-- UPDATE matched rows: take CNS5's distance when ours is missing OR implausible
--
-- CNS5 is volume-complete to 25 pc, so a star it lists cannot be further than that.
-- This step used to require `a.dist IS NULL`, which meant a wrong AT-HYG value blocked a
-- correct CNS5 one -- and the correct one was already sitting in the CSV. The worst case
-- was CNS5 788 / GJ 125, an M2 dwarf: AT-HYG 97009.6 pc, CNS5 17.19 pc, a factor of 3900,
-- with absmag -9.68 instead of +7.29.
--
-- The threshold is evidence-based, not arbitrary. Comparing every CNS5-designated star
-- against AT-HYG (measured 2026-07-30):
--
--     within 2%      5,765 (97.58%)
--     2-10%          63 (1.07%)
--     10-50%         64 (1.08%)
--     50-200%        1 (0.02%)
--     over 200%      15 (0.25%)   <-- all of these are bad Gaia parallaxes
--
-- The bulk agrees to within 2% and the contradictions sit past 200% with a clear gap
-- below, so overriding above a factor of 2 is surgical: it touches the contradictions and
-- nothing else.
--
-- dist and absmag are set in ONE statement deliberately. absmag was derived from the old
-- distance, so adopting a new distance without it would leave the two inconsistent -- and
-- it cannot be done in a following statement, because by then a.dist already equals s.dist
-- and the disagreement test no longer fires. COALESCE keeps our value if CNS5 has none.
--
UPDATE athyg a
SET    dist = s.dist,
       absmag = COALESCE(s.absmag, a.absmag),
       -- Record where the adopted value came from. Without this the data cannot say
       -- whether a distance is AT-HYG's or ours, which makes the quality check unable to
       -- tell an accepted override from an unresolved contradiction.
       dist_src = 'CNS5'
FROM   cns5_stage s
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
FROM   cns5_stage s
WHERE  s.athyg_id = a.id
  AND  s.match_method != 'new'
  AND  a.absmag IS NULL
  AND  s.absmag IS NOT NULL;

--
-- UPDATE matched rows: backfill spectral type where missing
--
UPDATE athyg a
SET    spect = s.spect
FROM   cns5_stage s
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
WHERE cns5 IS NOT NULL AND id < 5000000
  AND (x_eq IS NULL OR y_eq IS NULL OR z_eq IS NULL)
  AND ra IS NOT NULL AND dec IS NOT NULL AND dist IS NOT NULL;

UPDATE athyg
SET
  x = -0.055  * x_eq - 0.8734 * y_eq - 0.4839 * z_eq,
  y =  0.494  * x_eq - 0.4449 * y_eq + 0.747  * z_eq,
  z = -0.8677 * x_eq - 0.1979 * y_eq + 0.4560 * z_eq
WHERE cns5 IS NOT NULL AND id < 5000000
  AND x_eq IS NOT NULL AND y_eq IS NOT NULL AND z_eq IS NOT NULL
  AND (x IS NULL OR y IS NULL OR z IS NULL);

--
-- INSERT new stars (match_method = 'new')
--
INSERT INTO athyg (id, cns5, gj, gaia, hip, ra, dec, dist, mag, absmag, spect, x_eq, y_eq, z_eq)
SELECT
  s.athyg_id,
  s.cns5_id::TEXT,
  NULLIF(CONCAT(s.gj, s.gj_comp), ''),
  NULLIF(s.gaia, ''),
  NULLIF(s.hip, ''),
  s.ra_j2000,
  s.dec_j2000,
  s.dist,
  s.mag,
  s.absmag,
  NULLIF(s.spect, ''),
  s.x_eq,
  s.y_eq,
  s.z_eq
FROM cns5_stage s
WHERE s.match_method = 'new'
ON CONFLICT (id) DO NOTHING;

DO $$
DECLARE
  new_count INTEGER;
BEGIN
  SELECT COUNT(*) INTO new_count FROM athyg WHERE id >= 5000000;
  RAISE NOTICE 'Inserted % new CNS5 stars.', new_count;
END $$;

--
-- Compute equatorial coordinates for new rows missing them
--
UPDATE athyg
SET
  x_eq = dist * cos(radians(dec)) * cos(radians(ra * 15)),
  y_eq = dist * cos(radians(dec)) * sin(radians(ra * 15)),
  z_eq = dist * sin(radians(dec))
WHERE id >= 5000000
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
WHERE id >= 5000000
  AND x_eq IS NOT NULL AND y_eq IS NOT NULL AND z_eq IS NOT NULL
  AND (x IS NULL OR y IS NULL OR z IS NULL);

DO $$
DECLARE
  coord_count INTEGER;
BEGIN
  SELECT COUNT(*) INTO coord_count
  FROM athyg WHERE id >= 5000000 AND x IS NOT NULL;
  RAISE NOTICE 'New stars with galactic coordinates: %', coord_count;
END $$;

-- Reset the sequence for auto-increment
SELECT setval('athyg_id_seq', (SELECT COALESCE(MAX(id), 1) FROM athyg));

-- Update statistics for query optimization
ANALYZE athyg;

-- Display CNS5 import summary
DO $$
DECLARE
  total_cns5 INTEGER;
  matched INTEGER;
  new_stars INTEGER;
  with_gj INTEGER;
  with_coords INTEGER;
  new_with_spect INTEGER;
  matched_with_spect INTEGER;
BEGIN
  SELECT COUNT(*) INTO total_cns5 FROM athyg WHERE cns5 IS NOT NULL;
  SELECT COUNT(*) INTO matched FROM cns5_stage WHERE match_method != 'new';
  SELECT COUNT(*) INTO new_stars FROM cns5_stage WHERE match_method = 'new';
  SELECT COUNT(*) INTO with_gj FROM athyg WHERE gj IS NOT NULL;
  SELECT COUNT(*) INTO with_coords
    FROM athyg WHERE id >= 5000000 AND x IS NOT NULL;
  SELECT COUNT(*) INTO new_with_spect
    FROM athyg WHERE id >= 5000000 AND spect IS NOT NULL;
  SELECT COUNT(*) INTO matched_with_spect
    FROM athyg WHERE cns5 IS NOT NULL AND id < 5000000 AND spect IS NOT NULL;

  RAISE NOTICE '';
  RAISE NOTICE '=== CNS5 Import Summary ===';
  RAISE NOTICE 'Stars with CNS5 IDs:      %', total_cns5;
  RAISE NOTICE 'Matched (updated):        %', matched;
  RAISE NOTICE 'New (inserted):           %', new_stars;
  RAISE NOTICE 'Total stars with GJ IDs:  %', with_gj;
  RAISE NOTICE 'New stars with galactic coords: %', with_coords;
  RAISE NOTICE 'New stars with spect type: %', new_with_spect;
  RAISE NOTICE 'Matched stars with spect:  %', matched_with_spect;
  RAISE NOTICE 'Total athyg rows:         %', (SELECT COUNT(*) FROM athyg);
END $$;
