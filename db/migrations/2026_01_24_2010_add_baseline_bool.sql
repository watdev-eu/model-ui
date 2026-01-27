ALTER TABLE swat_runs
    ADD COLUMN IF NOT EXISTS is_baseline BOOLEAN NOT NULL DEFAULT FALSE;

-- One baseline per study area (partial unique index)
CREATE UNIQUE INDEX IF NOT EXISTS ux_swat_runs_baseline_per_area
    ON swat_runs (study_area)
    WHERE is_baseline = TRUE;