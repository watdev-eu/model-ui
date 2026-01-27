BEGIN;

CREATE TABLE IF NOT EXISTS mca_variable_values_crop_run (
    variable_set_id BIGINT NOT NULL REFERENCES mca_variable_sets(id) ON DELETE CASCADE,
    run_id BIGINT NOT NULL REFERENCES swat_runs(id) ON DELETE CASCADE,
    crop_code VARCHAR(8) NOT NULL REFERENCES crops(code) ON DELETE CASCADE,
    variable_id BIGINT NOT NULL REFERENCES mca_variables(id) ON DELETE CASCADE,
    value_num DOUBLE PRECISION,
    value_text TEXT,
    value_bool BOOLEAN,
    PRIMARY KEY (variable_set_id, run_id, crop_code, variable_id)
);

CREATE INDEX IF NOT EXISTS idx_mca_var_values_crop_run_run
    ON mca_variable_values_crop_run (run_id);

COMMIT;