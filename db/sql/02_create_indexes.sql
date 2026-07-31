-- Performance indexes for common queries
CREATE INDEX idx_athyg_mag ON athyg(mag) WHERE mag IS NOT NULL;
CREATE INDEX idx_athyg_galactic ON athyg(x, y, z);
CREATE INDEX idx_bbox_mag ON athyg (x, y, z, mag);

-- Wide-zoom bounding-box queries (/api/stars, the default absmag ordering).
--
-- The spatial indexes above cannot help at wide zoom, and it is worth being precise about
-- why rather than adding another one: the box simply contains most of the catalog.
-- Measured share of all 2,839,957 rows inside the box, by half-width:
--   ±20 pc 0.27%   ±50 2.80%   ±100 12.72%   ±250 28.68%
--   ±500 55.25%    ±1000 81.57%   ±1500 92.11%
-- At ±1500 the planner chose a sequential scan because that is the correct plan for 92%
-- selectivity. The cost was never the filter; it was `ORDER BY absmag LIMIT`, which had to
-- sort ~2.6M rows and spilled to a disk-based external merge (~211MB across 3 workers).
--
-- Column order is load-bearing:
--   absmag  leading, so the index supplies the sort order and the LIMIT can stop early
--           instead of sorting the whole box.
--   id      the tiebreaker, immediately after absmag so (absmag, id) satisfies the
--           ORDER BY exactly and no sort node is needed at all.
--   x,y,z   applied as Index Cond, so non-matching rows are rejected inside the index
--           without a heap fetch. This is what makes mid-zoom fast; an index on absmag
--           alone was 5.2x SLOWER than the sequential scan at ±100, because it did a
--           random heap fetch per candidate row.
--
-- Measured medians, before -> after: ±100 574->161ms, ±250 743->39ms, ±500 924->54ms,
-- ±1000 1038->23ms, ±1500/limit 50000 1590->217ms with the disk sort gone. Narrow zoom is
-- unchanged and still uses idx_athyg_galactic. 109MB against an 806MB table.
CREATE INDEX idx_athyg_absmag_bbox ON athyg (absmag, id, x, y, z);
CREATE INDEX idx_athyg_hyg ON athyg(hyg) WHERE hyg IS NOT NULL;
CREATE INDEX idx_athyg_hip ON athyg(hip) WHERE hip IS NOT NULL;
CREATE INDEX idx_athyg_hd ON athyg(hd) WHERE hd IS NOT NULL;
CREATE INDEX idx_athyg_gaia ON athyg(gaia) WHERE gaia IS NOT NULL;
CREATE INDEX idx_athyg_con ON athyg(con) WHERE con IS NOT NULL;
CREATE INDEX idx_athyg_spect ON athyg(spect) WHERE spect IS NOT NULL;
CREATE INDEX idx_athyg_proper_lower ON athyg(LOWER(proper)) WHERE proper IS NOT NULL;
CREATE INDEX idx_athyg_bayer_con ON athyg (bayer, con);
CREATE INDEX idx_athyg_flam_con  ON athyg (flam,  con);
CREATE INDEX idx_athyg_gj ON athyg(gj) WHERE gj IS NOT NULL;
CREATE INDEX idx_athyg_cns5 ON athyg(cns5) WHERE cns5 IS NOT NULL;

-- Trigram indexes for name search (/api/stars/search).
--
-- The name search matches with LIKE '%term%'. A leading wildcard makes every B-tree
-- index above unusable, so before these existed the search fell back to a parallel
-- sequential scan of all 2.8M rows: 1.2-3.4s per query, measured. With them: 0.04-0.07s.
--
-- The indexed expressions must match the predicates in hygmap-api/app/api/stars.py
-- exactly, character for character, or the planner will ignore them. If you change one,
-- change both and re-check with EXPLAIN ANALYZE.
--
-- Cost, measured on 2.83M rows: ~16s total build time and 48MB on disk (15MB each for
-- the three concatenated-expression indexes, 3MB for proper) against a 1795MB table.
-- Acceptable because writes happen only at import.
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE INDEX idx_athyg_proper_trgm
  ON athyg USING gin ((LOWER(COALESCE(proper, ''))) gin_trgm_ops);
CREATE INDEX idx_athyg_bayer_con_trgm
  ON athyg USING gin ((LOWER(COALESCE(bayer, '') || ' ' || COALESCE(con, ''))) gin_trgm_ops);
CREATE INDEX idx_athyg_flam_con_trgm
  ON athyg USING gin ((LOWER(COALESCE(flam, '') || ' ' || COALESCE(con, ''))) gin_trgm_ops);
CREATE INDEX idx_athyg_con_trgm
  ON athyg USING gin ((LOWER(COALESCE(con, ''))) gin_trgm_ops);
