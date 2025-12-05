USE watdev;

-- Rename columns and add new fields
ALTER TABLE swat_runs
    CHANGE COLUMN project study_area VARCHAR(32) NOT NULL,
    CHANGE COLUMN scenario_name run_label VARCHAR(160) NOT NULL,
    ADD COLUMN bmp ENUM('baseline','composting','intercropping','water_management')
        NOT NULL DEFAULT 'baseline' AFTER study_area;

-- Drop old unique, add new ones
ALTER TABLE swat_runs
    DROP INDEX uq_project_scenario,
    ADD UNIQUE KEY uq_run_label (run_label),
    ADD KEY idx_area_bmp_created (study_area, bmp, created_at);