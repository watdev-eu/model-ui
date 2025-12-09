-- 1) Allow duplicate RCH per study area: drop the unique CONSTRAINT
ALTER TABLE study_area_reaches
    DROP CONSTRAINT IF EXISTS uq_rch_per_area;

-- 2) Ensure Subbasin is unique per study area (this is what we want)
CREATE UNIQUE INDEX IF NOT EXISTS uq_sub_per_area
    ON study_area_subbasins (study_area_id, sub);