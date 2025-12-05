-- Make sure PostGIS is available
CREATE EXTENSION IF NOT EXISTS postgis;

------------------------------------------------------------
-- 1) Study areas
------------------------------------------------------------

CREATE TABLE IF NOT EXISTS study_areas (
                                           id      SERIAL PRIMARY KEY,
                                           name    TEXT NOT NULL UNIQUE,               -- e.g. 'Egypt', 'Ethiopia'
                                           geom    geometry(MultiPolygon, 4326),       -- CRS for web maps (WGS84)
                                           enabled BOOLEAN NOT NULL DEFAULT TRUE
);

------------------------------------------------------------
-- 2) Crops table
------------------------------------------------------------

CREATE TABLE IF NOT EXISTS crops (
                                     code VARCHAR(8) PRIMARY KEY,   -- SWAT plant code / abbreviation
                                     name TEXT NOT NULL             -- common name
);

-- Prefill crops (from SWAT plant table: Common Name ↔ Plant Code)
INSERT INTO crops (code, name) VALUES
                                   ('SWGR', 'Slender wheatgrass'),
                                   ('RYEG', 'Italian (annual) ryegrass'),
                                   ('RYER', 'Russian wildrye'),
                                   ('RYEA', 'Altai wildrye'),
                                   ('SIDE', 'Sideoats grama'),
                                   ('BBLS', 'Big bluestem'),
                                   ('LBLS', 'Little bluestem'),
                                   ('SWCH', 'Alamo switchgrass'),
                                   ('INDN', 'Indiangrass'),
                                   ('ALFA', 'Alfalfa'),

                                   ('CLVS', 'Sweetclover'),
                                   ('CLVR', 'Red clover'),
                                   ('CLVA', 'Alsike clover'),
                                   ('SOYB', 'Soybean'),
                                   ('CWPS', 'Cowpeas'),

                                   ('MUNG', 'Mung bean'),
                                   ('LIMA', 'Lima beans'),
                                   ('LENT', 'Lentils'),
                                   ('PNUT', 'Peanut'),
                                   ('FPEA', 'Field peas'),
                                   ('PEAS', 'Garden or canning peas'),
                                   ('SESB', 'Sesbania'),
                                   ('FLAX', 'Flax'),

                                   ('COTS', 'Upland cotton (harvested with stripper)'),
                                   ('COTP', 'Upland cotton (harvested with picker)'),
                                   ('TOBC', 'Tobacco'),
                                   ('SGBT', 'Sugarbeet'),
                                   ('POTA', 'Potato'),
                                   ('SPOT', 'Sweetpotato'),
                                   ('CRRT', 'Carrot'),

                                   ('ONIO', 'Onion'),
                                   ('SUNF', 'Sunflower'),
                                   ('CANP', 'Spring canola-Polish'),
                                   ('CANA', 'Spring canola-Argentine'),
                                   ('ASPR', 'Asparagus'),

                                   ('BROC', 'Broccoli'),
                                   ('CABG', 'Cabbage'),
                                   ('CAUF', 'Cauliflower'),
                                   ('CELR', 'Celery'),
                                   ('LETT', 'Head lettuce'),
                                   ('SPIN', 'Spinach'),
                                   ('GRBN', 'Green beans'),
                                   ('CUCM', 'Cucumber'),
                                   ('EGGP', 'Eggplant'),
                                   ('CANT', 'Cantaloupe'),
                                   ('HMEL', 'Honeydew melon'),
                                   ('WMEL', 'Watermelon'),
                                   ('PEPR', 'Bell pepper'),
                                   ('STRW', 'Strawberry'),
                                   ('TOMA', 'Tomato'),
                                   ('APPL', 'Apple'),

                                   ('PINE', 'Pine'),
                                   ('OAK',  'Oak'),
                                   ('POPL', 'Poplar'),
                                   ('MESQ', 'Honey mesquite')
ON CONFLICT (code) DO NOTHING;  -- safe to re-run migration

------------------------------------------------------------
-- 3) swat_runs: add default flag
------------------------------------------------------------

ALTER TABLE swat_runs
    ADD COLUMN IF NOT EXISTS is_default BOOLEAN NOT NULL DEFAULT FALSE;