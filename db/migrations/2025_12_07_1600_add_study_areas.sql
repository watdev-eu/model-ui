------------------------------------------------------------
-- Subbasins per study area
------------------------------------------------------------
CREATE TABLE IF NOT EXISTS study_area_subbasins (
    id            BIGSERIAL PRIMARY KEY,
    study_area_id INTEGER NOT NULL
        REFERENCES study_areas(id) ON DELETE CASCADE,

    sub           INTEGER NOT NULL,                      -- SWAT subbasin number
    geom          geometry(MultiPolygon, 4326) NOT NULL, -- polygon(s)

    properties    JSONB DEFAULT '{}'::jsonb,             -- all GeoJSON properties

    CONSTRAINT uq_sub_per_area UNIQUE (study_area_id, sub)
);

CREATE INDEX IF NOT EXISTS idx_subbasins_geom
    ON study_area_subbasins USING GIST (geom);

------------------------------------------------------------
-- Reaches per study area
------------------------------------------------------------
CREATE TABLE IF NOT EXISTS study_area_reaches (
    id            BIGSERIAL PRIMARY KEY,
    study_area_id INTEGER NOT NULL
      REFERENCES study_areas(id) ON DELETE CASCADE,

    rch           INTEGER NOT NULL,                          -- SWAT reach number
    sub           INTEGER NULL,                              -- optional link to subbasin number
    geom          geometry(MultiLineString, 4326) NOT NULL,  -- river segments

    properties    JSONB DEFAULT '{}'::jsonb,

    CONSTRAINT uq_rch_per_area UNIQUE (study_area_id, rch)
);

CREATE INDEX IF NOT EXISTS idx_reaches_geom
    ON study_area_reaches USING GIST (geom);