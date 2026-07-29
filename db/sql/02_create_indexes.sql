-- Performance indexes for common queries
CREATE INDEX idx_athyg_mag ON athyg(mag) WHERE mag IS NOT NULL;
CREATE INDEX idx_athyg_galactic ON athyg(x, y, z);
CREATE INDEX idx_bbox_mag ON athyg (x, y, z, mag);
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
