LOAD DATA LOCAL INFILE 'hygdata_v3.csv.txt' INTO TABLE hyg FIELDS TERMINATED BY ','
OPTIONALLY ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(id,hip,hd,hr,gl,bf,proper,ra,`dec`,dist,pmra,pmdec,rv,mag,absmag,spect,ci,x,y,z,vx,vy,vz,rarad,decrad,pmrarad,pmdecrad,bayer,flam,con,comp,comp_primary,base,lum,var,var_min,var_max)
SET hip = nullif(hip,''),
	hd = nullif(hd,''),
    hr = nullif(hr,''),
    flam = nullif(flam,''),
    base = nullif(base,'')
;

update hyg set x = -(.055 * x_eq) - (.8734 * y_eq) - (.4839 * z_eq) where id < 200000;
update hyg set y = (.494 * x_eq) - (.4449 * y_eq) + (.747 * z_eq) where id < 200000;
update hyg set z = -(.8677 * x_eq) - (.1979 * y_eq) + (.4560 * z_eq) where id < 200000;