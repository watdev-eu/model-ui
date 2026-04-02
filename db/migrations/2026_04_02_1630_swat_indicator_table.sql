ALTER TYPE time_step_enum ADD VALUE IF NOT EXISTS 'YEARLY';

CREATE TABLE swat_indicator_yearly (
                                       id BIGSERIAL PRIMARY KEY,
                                       run_id BIGSERIAL NOT NULL REFERENCES swat_runs(id) ON DELETE CASCADE,
                                       indicator_code VARCHAR(64) NOT NULL,
                                       year INTEGER NOT NULL,
                                       sub INTEGER NOT NULL,
                                       crop VARCHAR(16) NOT NULL DEFAULT '',
                                       value DOUBLE PRECISION NULL,
                                       UNIQUE (run_id, indicator_code, year, sub, crop)
);

CREATE INDEX idx_swat_indicator_yearly_run_code
    ON swat_indicator_yearly (run_id, indicator_code);

CREATE INDEX idx_swat_indicator_yearly_run_year
    ON swat_indicator_yearly (run_id, year);