DROP TABLE IF EXISTS fic CASCADE;
DROP TABLE IF EXISTS fic_worlds CASCADE;

-- Fictional worlds/universes table
CREATE TABLE fic_worlds (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL
);

-- Fictional star names table
CREATE TABLE fic (
    id SERIAL PRIMARY KEY,
    star_id INTEGER NOT NULL,     -- References athyg.id
    world_id INTEGER NOT NULL,    -- References fic_worlds.id
    name TEXT NOT NULL,           -- Fictional name
    notes TEXT,
    FOREIGN KEY (world_id) REFERENCES fic_worlds(id)
);

-- Insert fictional worlds
INSERT INTO fic_worlds (id, name) VALUES 
(1, 'Star Trek'),
(2, 'Babylon 5');

-- Import Trek data
CREATE TEMP TABLE temp_trek_import (
    tyc_id TEXT,
    name TEXT,
    notes TEXT
);

\COPY temp_trek_import(tyc_id, name, notes) FROM '/data/athyg_tycho_trek.csv' WITH (FORMAT csv, HEADER false, DELIMITER ',', NULL '');

INSERT INTO fic (star_id, world_id, name, notes)
SELECT athyg.id, 1, temp_trek_import.name, temp_trek_import.notes
FROM temp_trek_import
JOIN athyg ON athyg.tyc = temp_trek_import.tyc_id
WHERE athyg.tyc IS NOT NULL;

DROP TABLE temp_trek_import;

-- Import Babylon 5 data
CREATE TEMP TABLE temp_b5_import (
    tyc_id TEXT,
    name TEXT,
    notes TEXT
);

\COPY temp_b5_import(tyc_id, name, notes) FROM '/data/athyg_tycho_b5.csv' WITH (FORMAT csv, HEADER false, DELIMITER ',', NULL '');

INSERT INTO fic (star_id, world_id, name, notes)
SELECT athyg.id, 2, temp_b5_import.name, temp_b5_import.notes
FROM temp_b5_import
JOIN athyg ON athyg.tyc = temp_b5_import.tyc_id
WHERE athyg.tyc IS NOT NULL;

DROP TABLE temp_b5_import;

DO $$
BEGIN
  RAISE NOTICE 'Imported fictional labels.';
END $$;