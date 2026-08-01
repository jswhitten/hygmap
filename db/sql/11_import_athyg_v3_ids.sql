--
-- Record each star's AT-HYG v3.3 id, so links saved before the AT-HYG 4 migration can be
-- recognised instead of silently resolving to a different star.
--
-- Loads db/data/athyg_v3_ids.csv, produced by db/scripts/match_athyg_v3.py.
--
-- `athyg.id` is the source catalog's row id, not ours. AT-HYG 4 renumbered it: of the
-- 2,552,143 v3.3 stars that still exist, only 636 kept the same id. So every star link
-- anyone saved before that migration now points somewhere else, and 99.99% of v3 ids are
-- also a valid *different* v4 id, which is why it fails silently rather than 404ing.
-- Worked example: ?select_star=7301 meant GJ 1 (mag 8.755, Sculptor) and now shows an
-- anonymous mag 11.5 star in Cepheus.
--
-- This step only *records* the old id. It does not alias, renumber or re-key anything;
-- resolving an ambiguous incoming link is a UI decision, made in the app.
--
-- WHY A TABLE AND NOT A COLUMN ON athyg
--
-- The first version of this added `athyg.athyg_v3_id`. That is the wrong shape: the
-- mapping is many-to-one. **5 current stars are each claimed by two v3 ids**, because
-- AT-HYG 4 merged two v3.3 rows into one star. A single column can hold only one of them,
-- so five legacy links would silently fail to resolve -- and worse, `UPDATE ... FROM` with
-- two matching source rows picks a winner *nondeterministically*, which is the same class
-- of bug WIDE-ZOOM-QUERY fixed in ORDER BY. A lookup table states the real cardinality:
-- v3_id is unique, athyg_id is not.
--
-- It is also far cheaper: no ALTER + 2.5M-row UPDATE against the 806MB athyg table.
--

DROP TABLE IF EXISTS athyg_v3_ids;

CREATE TABLE athyg_v3_ids (
  v3_id        INTEGER PRIMARY KEY,
  athyg_id     INTEGER NOT NULL REFERENCES athyg(id),
  match_method TEXT    NOT NULL
);

\COPY athyg_v3_ids FROM '/data/athyg_v3_ids.csv' WITH (FORMAT csv, HEADER true, NULL '');

--
-- Look-ups go both ways: v3 -> current when resolving an old link, and current -> v3 when
-- a page wants to know whether the star it is showing has a legacy id at all.
--
CREATE INDEX IF NOT EXISTS idx_athyg_v3_ids_athyg_id ON athyg_v3_ids (athyg_id);

--
-- Refuse a stale CSV, as 06, 07 and 10 do.
--
-- The REFERENCES constraint above already rejects a row pointing at a star that does not
-- exist, which is the stale case -- but it aborts with a foreign-key error that says
-- nothing about which file is wrong or how to fix it. This turns that into an instruction.
-- Silence here would mean legacy ids quietly not being applied, and the symptom would be
-- old links reporting "no legacy match", which looks like a correct answer rather than a
-- missing import.
--
DO $$
DECLARE
  n INTEGER;
BEGIN
  SELECT COUNT(*) INTO n FROM athyg_v3_ids;
  IF n = 0 THEN
    RAISE EXCEPTION 'athyg_v3_ids.csv loaded 0 rows. Regenerate it with '
                    'db/scripts/match_athyg_v3.py.';
  END IF;

  --
  -- The property the whole feature rests on: a v3 id names at most one current star. The
  -- PRIMARY KEY enforces it, so this is really a check on the *other* direction being
  -- allowed -- assert the known many-to-one count so that if it changes, someone looks.
  --
  SELECT COUNT(*) INTO n FROM (
    SELECT athyg_id FROM athyg_v3_ids GROUP BY athyg_id HAVING COUNT(*) > 1
  ) d;
  RAISE NOTICE 'athyg_v3_ids: % star(s) are named by more than one v3 id '
               '(AT-HYG 4 merged v3.3 rows). This is expected.', n;
END $$;

ANALYZE athyg_v3_ids;
