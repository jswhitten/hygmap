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
-- Refuse to proceed on a stale CSV.
--
-- A matched row whose athyg_id resolves to no star means gcns.csv was generated against a
-- different catalog state than the one loaded. That is precisely what lost CNS5 designation
-- 723: the supplement file was renumbered and gcns.csv was never regenerated, so matched
-- updates silently hit nothing. Nothing downstream can detect it, so check here.
--
DO $$
DECLARE
  stale INTEGER;
BEGIN
  SELECT COUNT(*) INTO stale
  FROM   gcns_stage s
  WHERE  s.match_method != 'new'
    AND  NOT EXISTS (SELECT 1 FROM athyg a WHERE a.id = s.athyg_id);

  IF stale > 0 THEN
    RAISE EXCEPTION 'gcns.csv is stale: % matched row(s) reference an athyg_id that does not '
                    'exist. Regenerate it with db/scripts/match_%s.py against the current '
                    'catalog.', stale, lower('GCNS');
  END IF;
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
-- Recompute coordinates for matched stars whose distance this script just changed
--
-- Same fix, and the same reasoning, as the equivalent block in 06_import_cns5.sql: the
-- gate tests whether the stored position still agrees with dist, not whether it is
-- missing. The old "missing" gate only fired for rows this script INSERTs, so 2 GCNS
-- stars kept positions derived from the distance that had just been overridden. Read the
-- longer note in 06_import_cns5.sql for why the invariant is tested instead of the
-- trigger; 0.001 matches COORD_TOLERANCE_FRACTION in
-- db/scripts/check_distance_quality.py.
--
UPDATE athyg
SET
  x_eq = dist * cos(radians(dec)) * cos(radians(ra * 15)),
  y_eq = dist * cos(radians(dec)) * sin(radians(ra * 15)),
  z_eq = dist * sin(radians(dec))
WHERE gaia IS NOT NULL AND id < 6000000
  AND ra IS NOT NULL AND dec IS NOT NULL AND dist IS NOT NULL
  AND (
        x_eq IS NULL OR y_eq IS NULL OR z_eq IS NULL
        OR abs(sqrt(x_eq*x_eq + y_eq*y_eq + z_eq*z_eq) - dist) > 0.001 * dist
      );

-- Must run after the equatorial update above, and use the same staleness test.
UPDATE athyg
SET
  x = -0.055  * x_eq - 0.8734 * y_eq - 0.4839 * z_eq,
  y =  0.494  * x_eq - 0.4449 * y_eq + 0.747  * z_eq,
  z = -0.8677 * x_eq - 0.1979 * y_eq + 0.4560 * z_eq
WHERE gaia IS NOT NULL AND id < 6000000
  AND x_eq IS NOT NULL AND y_eq IS NOT NULL AND z_eq IS NOT NULL
  AND (
        x IS NULL OR y IS NULL OR z IS NULL
        OR (dist IS NOT NULL AND dist > 0
            AND abs(sqrt(x*x + y*y + z*z) - dist) > 0.001 * dist)
      );

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
  -- Do not insert a "new" star whose Gaia source_id is already in the table.
  --
  -- This was the single largest source of duplicate Gaia rows: 2,598 of the 2,665 stars
  -- CNS5 introduces as new were introduced as new by GCNS too, so the same physical star
  -- was inserted twice, ~30 minutes apart in the same build. Measured example: gaia
  -- 1005873614080407296 as ids 5000426 and 6069717, RA agreeing to nine decimal places and
  -- identical spectral type and magnitude.
  --
  -- The cause is not in either matcher's logic but in how they are run: both cross-match
  -- against the live database, and both CSVs were generated from the same pre-supplement
  -- snapshot, so neither could see what the other would insert. Regenerating gcns.csv
  -- after applying CNS5 would also fix it, but only until the next time someone regenerates
  -- them in the other order or in parallel. Testing the invariant here -- "is this Gaia
  -- source already present?" -- is order-independent and idempotent, which is the same
  -- lesson COORD-RECOMPUTE-FIX learned about gating on state rather than on sequence.
  --
  -- ON CONFLICT (id) below does not cover this: the two rows have different ids. It is the
  -- Gaia identity that collides, not the primary key.
  AND NOT EXISTS (
    SELECT 1 FROM athyg a WHERE a.gaia = NULLIF(s.source_id, '')
  )
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

--
-- Guard: no duplicate Gaia group may contain a row this pipeline inserted.
--
-- A Gaia DR3 source_id names one physical source, so two rows sharing one are the same
-- star twice -- with one important exception, which is why this guard is shaped the way it
-- is rather than simply asserting a count of zero.
--
-- AT-HYG itself legitimately carries ~1,167 groups where two rows share a Gaia id. Those
-- are real close binaries: Tycho-2 resolved both components, Gaia DR3 recorded a single
-- source, and AT-HYG kept both. Measured, 88% of them carry hard evidence of being
-- genuine -- distinct Tycho-2 identifiers and/or explicit component designations such as
-- GJ 314A / GJ 314B, or Graffias / Graffias B. Merging those would delete real stars, so
-- the project's decision (2026-07-31) is to keep them.
--
-- What is NOT legitimate is this pipeline manufacturing new duplicates, which is exactly
-- what it did: 2,598 stars inserted twice because cns5.csv and gcns.csv were generated
-- from the same pre-supplement snapshot and each independently called the star "new".
--
-- So the invariant is not "no duplicates" but "no duplicate we created". That distinction
-- is what lets this guard survive an AT-HYG upgrade that changes the binary count, while
-- still failing loudly the moment an import reintroduces the bug.
--
DO $$
DECLARE
  bad_groups INTEGER;
  example    TEXT;
  native     INTEGER;
BEGIN
  SELECT COUNT(*) INTO bad_groups FROM (
    SELECT gaia FROM athyg WHERE gaia IS NOT NULL
    GROUP BY gaia HAVING COUNT(*) > 1 AND COUNT(*) FILTER (WHERE id >= 5000000) > 0
  ) t;

  IF bad_groups > 0 THEN
    SELECT string_agg(gaia, ', ') INTO example FROM (
      SELECT gaia FROM athyg WHERE gaia IS NOT NULL
      GROUP BY gaia HAVING COUNT(*) > 1 AND COUNT(*) FILTER (WHERE id >= 5000000) > 0
      LIMIT 3
    ) s;
    RAISE EXCEPTION 'GAIA-DUPLICATES: % Gaia source_id(s) are duplicated by a row this '
                    'pipeline inserted (ids >= 5000000). A supplement import created a star '
                    'that already existed. Example source_id(s): %. '
                    'Check the NOT EXISTS guards on the "new" inserts in 06_import_cns5.sql '
                    'and 07_import_gcns.sql.', bad_groups, example;
  END IF;

  SELECT COUNT(*) INTO native FROM (
    SELECT gaia FROM athyg WHERE gaia IS NOT NULL GROUP BY gaia HAVING COUNT(*) > 1
  ) t;
  -- Counted here, before 09_import_overrides.sql retracts any wrong Gaia identification,
  -- so this figure is one higher than the finished database reports. Expect 1167 here and
  -- 1166 from db/scripts/check_duplicates.py; the difference is the AT-HYG cross-match
  -- error on tyc 3986-3499-1, which is corrected two steps later.
  RAISE NOTICE 'Gaia duplicate groups: % inherited from AT-HYG, 0 created by import '
               '(counted pre-override; the final database has one fewer)', native;
END $$;
