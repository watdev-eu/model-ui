USE watdev;

ALTER TABLE swat_runs
    DROP COLUMN bmp,
    DROP COLUMN swat_version,
    DROP COLUMN print_opts,
    DROP COLUMN run_path,
    DROP COLUMN param_set_hash,
    ADD COLUMN run_date DATE NULL AFTER run_label,
    ADD COLUMN visibility ENUM('private','public') NOT NULL DEFAULT 'private' AFTER run_date,
    ADD COLUMN description TEXT NULL AFTER visibility,
    ADD COLUMN created_by BIGINT NULL AFTER description;

-- Drop old global unique index on run_label
ALTER TABLE swat_runs
    DROP INDEX uq_run_label,
    ADD UNIQUE KEY uniq_studyarea_runlabel (study_area, run_label);