LOAD DATA LOCAL INFILE 'athyg_v10.csv' INTO TABLE athyg FIELDS TERMINATED BY ','
OPTIONALLY ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(id,tyc,gaia,hyg,hip,hd,hr,gl,bayer,flam,con,proper,ra,`dec`,pos_src,dist,x_eq,y_eq,z_eq,dist_src,mag,absmag,mag_src)
SET 
    tyc = nullif(tyc,''),
    gaia = nullif(gaia,''),
    hyg = nullif(hyg,''),
    hip = nullif(hip,''),
    hd = nullif(hd,''),
    hr = nullif(hr,''),
    gl = nullif(gl,''),
    bayer = nullif(bayer,''),
    flam = nullif(flam,''),
    proper = nullif(proper,'')
;

update athyg set x = -(.055 * x_eq) - (.8734 * y_eq) - (.4839 * z_eq);
update athyg set y = (.494 * x_eq) - (.4449 * y_eq) + (.747 * z_eq);
update athyg set z = -(.8677 * x_eq) - (.1979 * y_eq) + (.4560 * z_eq);

UPDATE athyg
JOIN hyg ON hyg.id = athyg.hyg
SET athyg.spect = hyg.spect, athyg.spect_src = 'HYG'
WHERE athyg.hyg IS NOT NULL;

