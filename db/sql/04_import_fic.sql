DROP TABLE IF EXISTS fic CASCADE;
DROP TABLE IF EXISTS fic_worlds CASCADE;

-- Fictional worlds/universes table
CREATE TABLE fic_worlds (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL
);

-- Fictional star names table
CREATE TABLE fic (
    id SERIAL PRIMARY KEY,
    star_id INTEGER NOT NULL,     -- References athyg.id
    world_id INTEGER NOT NULL,    -- References fic_worlds.id
    name TEXT NOT NULL,           -- Fictional name
    FOREIGN KEY (world_id) REFERENCES fic_worlds(id)
);

-- Insert fictional worlds
INSERT INTO fic_worlds (id, name) VALUES 
(1, 'Star Trek'),
(2, 'Babylon 5');

-- Import Trek data
CREATE TEMP TABLE temp_trek_import (
    tyc_id TEXT,
    trek_name TEXT
);

\COPY temp_trek_import(tyc_id, trek_name) FROM '/data/athyg_tycho_fic.csv' WITH (FORMAT csv, HEADER false, DELIMITER ',', NULL '');

INSERT INTO fic (star_id, world_id, name)
SELECT athyg.id, 1, temp_trek_import.trek_name
FROM temp_trek_import
JOIN athyg ON athyg.tyc = temp_trek_import.tyc_id
WHERE athyg.tyc IS NOT NULL;

DROP TABLE temp_trek_import;