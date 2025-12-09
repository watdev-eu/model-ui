ALTER TABLE study_area_subbasins
    ALTER COLUMN geom
    TYPE geometry(MultiPolygon, 3857)
    USING ST_Transform(geom, 3857);

ALTER TABLE study_area_reaches
    ALTER COLUMN geom
    TYPE geometry(MultiLineString, 3857)
    USING ST_Transform(geom, 3857);

ALTER TABLE study_areas
    ALTER COLUMN geom
    TYPE geometry(MultiPolygon, 3857)
    USING ST_Transform(geom, 3857);