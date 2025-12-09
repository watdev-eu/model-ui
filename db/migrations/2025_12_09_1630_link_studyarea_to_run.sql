-- 1) Keep old string for now (for safety) and get it out of the way
ALTER TABLE swat_runs
    RENAME COLUMN study_area TO study_area_code;

-- 2) Add new integer column that will hold the FK
ALTER TABLE swat_runs
    ADD COLUMN study_area INTEGER;

-- 3) Backfill all existing runs to the study area named 'Egypt'
UPDATE swat_runs r
SET study_area = sa.id
    FROM study_areas sa
WHERE sa.name = 'Egypt';

-- 4) Make study_area mandatory
ALTER TABLE swat_runs
    ALTER COLUMN study_area SET NOT NULL;

-- 5) Add the foreign key to study_areas(id)
ALTER TABLE swat_runs
    ADD CONSTRAINT swat_runs_study_area_fk
        FOREIGN KEY (study_area) REFERENCES study_areas(id)
            ON DELETE RESTRICT;

-- 6) Enforce uniqueness of run_label within a study area
ALTER TABLE swat_runs
    ADD CONSTRAINT swat_runs_area_runlabel_uniq
        UNIQUE (study_area, run_label);

-- 7) (Optional but recommended) drop old string column
ALTER TABLE swat_runs
    DROP COLUMN study_area_code;