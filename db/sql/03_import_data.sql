CREATE TEMP TABLE athyg_stage (
  id INT,
  tyc VARCHAR,
  gaia VARCHAR,
  hyg INT,
  hip VARCHAR,
  hd VARCHAR,
  hr VARCHAR,
  gl VARCHAR,
  bayer VARCHAR,
  flam INT,
  con VARCHAR,
  proper VARCHAR,
  ra DOUBLE PRECISION,
  dec DOUBLE PRECISION,
  pos_src VARCHAR,
  dist REAL,
  x_eq REAL,
  y_eq REAL,
  z_eq REAL,
  dist_src VARCHAR,
  mag REAL,
  absmag REAL,
  ci REAL,
  mag_src VARCHAR,
  rv REAL,
  rv_src VARCHAR,
  pm_ra REAL,
  pm_dec REAL,
  pm_src VARCHAR,
  vx REAL,
  vy REAL,
  vz REAL,
  spect VARCHAR,
  spect_src VARCHAR
);

COPY athyg_stage
FROM '/data/athyg_v32-1.csv'
WITH (FORMAT csv, HEADER true, NULL '', DELIMITER ',');

COPY athyg_stage
FROM '/data/athyg_v32-2.csv'
WITH (FORMAT csv, HEADER false, NULL '', DELIMITER ',');

DO $$
BEGIN
  RAISE NOTICE 'Loaded CSV files into staging table.';
END $$;

--
-- Import custom star data from the supplemental file into the staging table
--
COPY athyg_stage (id,tyc,gaia,hyg,hip,hd,hr,gl,bayer,flam,con,proper,ra,dec,pos_src,dist,x_eq,y_eq,z_eq,dist_src,mag,absmag,ci,mag_src,rv,rv_src,pm_ra,pm_dec,pm_src,vx,vy,vz,spect,spect_src)
FROM '/data/athyg_supplement.csv'
WITH (FORMAT CSV, HEADER, NULL '', DELIMITER ',');

DO $$
BEGIN
  RAISE NOTICE 'Loaded supplemental CSV file into staging table.';
END $$;

-- Strip the leading “Gl ” / “GJ ” (any case, any spaces) from the gl column
UPDATE athyg_stage
SET    gl =
       NULLIF(                        -- turn empty string into NULL
         regexp_replace(gl,
                        '^\s*(gl|gj)\s*',  -- leading prefix + spaces
                        '',
                        'i'),              -- case-insensitive
         ''
       );

DO $$
BEGIN
  RAISE NOTICE 'Cleaned gl prefixes (Gl/GJ) in staging table.';
END $$;

INSERT INTO athyg (
  id, tyc, gaia, hyg, hip, hd, hr, gj, bayer, flam, con, proper,
  ra, dec, pos_src, dist, x, y, z, x_eq, y_eq, z_eq,
  dist_src, mag, absmag, mag_src, spect, spect_src
)
SELECT
  id, tyc, gaia, hyg, hip, hd, hr, gl, bayer, flam, con, proper,
  ra, dec, pos_src, dist,
  NULL, NULL, NULL,  -- x, y, z will be computed later
  x_eq, y_eq, z_eq,
  dist_src, mag, absmag, mag_src, spect, spect_src
FROM athyg_stage;

DO $$
BEGIN
  RAISE NOTICE 'Copied % rows into athyg.', (SELECT COUNT(*) FROM athyg);
END $$;

--
-- Calculate equatorial coordinates for stars missing them (e.g., from addendum file)
--
UPDATE athyg
SET
  x_eq = dist * cos(radians(dec)) * cos(radians(ra * 15)),
  y_eq = dist * cos(radians(dec)) * sin(radians(ra * 15)),
  z_eq = dist * sin(radians(dec))
WHERE x_eq IS NULL OR y_eq IS NULL OR z_eq IS NULL;

--
-- Calculate galactic coordinates for all stars
--

UPDATE athyg
SET
  x = -0.055 * x_eq - 0.8734 * y_eq - 0.4839 * z_eq,
  y =  0.494 * x_eq - 0.4449 * y_eq + 0.747  * z_eq,
  z = -0.8677 * x_eq - 0.1979 * y_eq + 0.4560 * z_eq;

DO $$
BEGIN
  RAISE NOTICE 'Converted equatorial to galactic coordinates.';
END $$;

-- Reset the sequence for auto-increment
SELECT setval('athyg_id_seq', (SELECT COALESCE(MAX(id), 1) FROM athyg));

-- Update statistics for query optimization
ANALYZE athyg;

-- Display import summary
DO $$
DECLARE
    star_count INTEGER;
    named_count INTEGER;
    coordinate_count INTEGER;
    spectral_count INTEGER;
    rec RECORD;
BEGIN
    SELECT COUNT(*) INTO star_count FROM athyg;
    SELECT COUNT(*) INTO named_count FROM athyg WHERE proper IS NOT NULL;
    SELECT COUNT(*) INTO coordinate_count FROM athyg WHERE x IS NOT NULL AND y IS NOT NULL AND z IS NOT NULL;
    SELECT COUNT(*) INTO spectral_count FROM athyg WHERE spect IS NOT NULL;
    
    RAISE NOTICE '=== ATHYG Import Summary ===';
    RAISE NOTICE 'Total stars imported: %', star_count;
    RAISE NOTICE 'Named stars: %', named_count;
    RAISE NOTICE 'Stars with galactic coordinates: %', coordinate_count;
    RAISE NOTICE 'Stars with spectral types: %', spectral_count;
    
    -- Show sample data
    RAISE NOTICE '';
    RAISE NOTICE 'Sample brightest named stars:';
    FOR rec IN 
        SELECT proper, mag, dist, con, spect, x, y, z
        FROM athyg 
        WHERE proper IS NOT NULL AND mag IS NOT NULL
        ORDER BY mag 
        LIMIT 5
    LOOP
        RAISE NOTICE '  % (mag: %, dist: % pc, %, xyz: %, %, %)', 
                     rec.proper, rec.mag, rec.dist, rec.spect,
                     ROUND(rec.x::numeric, 2), ROUND(rec.y::numeric, 2), ROUND(rec.z::numeric, 2);
    END LOOP;
END $$;