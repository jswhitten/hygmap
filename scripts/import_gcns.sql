LOAD DATA LOCAL INFILE 'GCNS_simbad.csv' INTO TABLE gcns 
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' 
LINES TERMINATED BY '\n' 
IGNORE 1 LINES 
(gaia,main_id,ra,`dec`,plx,pmRA,pmDE,Gmag,BPmag,RPmag,Bmag,Vmag,RV,sp_type,WDprob,dist,x,y,z,U,V,W,main_type);
