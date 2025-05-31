-- Performance indexes for common queries
CREATE INDEX idx_athyg_mag ON athyg(mag) WHERE mag IS NOT NULL;
CREATE INDEX idx_athyg_dist ON athyg(dist) WHERE dist IS NOT NULL;
CREATE INDEX idx_athyg_coords ON athyg(ra, dec);
CREATE INDEX idx_athyg_galactic ON athyg(x, y, z);
CREATE INDEX idx_athyg_equatorial ON athyg(x_eq, y_eq, z_eq);
CREATE INDEX idx_athyg_hyg ON athyg(hyg) WHERE hyg IS NOT NULL;
CREATE INDEX idx_athyg_hip ON athyg(hip) WHERE hip IS NOT NULL;
CREATE INDEX idx_athyg_hd ON athyg(hd) WHERE hd IS NOT NULL;
CREATE INDEX idx_athyg_gaia ON athyg(gaia) WHERE gaia IS NOT NULL;
CREATE INDEX idx_athyg_con ON athyg(con) WHERE con IS NOT NULL;
CREATE INDEX idx_athyg_spect ON athyg(spect) WHERE spect IS NOT NULL;

-- Text search indexes for star names
-- Assuming you plan to add 'altname' later; otherwise skip this
-- CREATE INDEX idx_athyg_altname_lower ON athyg(LOWER(altname)) WHERE altname IS NOT NULL;
CREATE INDEX idx_athyg_proper_lower ON athyg(LOWER(proper)) WHERE proper IS NOT NULL;