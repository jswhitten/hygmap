LOAD DATA LOCAL INFILE 'GCNS_simbad.csv' INTO TABLE gcns 
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' 
LINES TERMINATED BY '\n' 
IGNORE 1 LINES 
(gaia,plx,pmRA,pmDE,Gmag,BPmag,RPmag,RV,WDprob,dist,x,y,z,U,V,W,@dummy,@dummy,@dummy,@dummy,@dummy,@dummy,@dummy,@dummy,@dummy,@dummy,@dummy,main_id,ra,`dec`,main_type,@dummy,@dummy,@dummy,sp_type,@dummy,Bmag,Vmag);


update athyg set x = -(.055 * x_eq) - (.8734 * y_eq) - (.4839 * z_eq);
update athyg set y = (.494 * x_eq) - (.4449 * y_eq) + (.747 * z_eq);
update athyg set z = -(.8677 * x_eq) - (.1979 * y_eq) + (.4560 * z_eq);

UPDATE athyg
JOIN hyg ON hyg.id = athyg.hyg
SET athyg.spect = hyg.spect, athyg.spect_src = 'HYG', athyg.altname = hyg.altname
WHERE athyg.hyg IS NOT NULL;

