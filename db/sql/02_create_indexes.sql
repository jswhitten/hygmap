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