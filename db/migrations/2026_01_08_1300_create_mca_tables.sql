-- Create MCA indicator catalog + preset sets + preset items + scenario mapping + results cache

-- 1) Indicator catalog
CREATE TABLE IF NOT EXISTS mca_indicators (
                                              id                BIGSERIAL PRIMARY KEY,
                                              code              VARCHAR(32) UNIQUE NOT NULL, -- e.g. '15.1'
                                              name              TEXT NOT NULL,
                                              unit              TEXT NULL,
                                              default_direction TEXT NOT NULL CHECK (default_direction IN ('pos','neg')),
                                              description       TEXT NULL,
                                              calc_key          VARCHAR(64) NOT NULL, -- used by PHP calculator switch/registry
                                              created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- 2) Preset collections (default + user)
CREATE TABLE IF NOT EXISTS mca_preset_sets (
                                               id            BIGSERIAL PRIMARY KEY,
                                               study_area_id INTEGER NOT NULL REFERENCES study_areas(id) ON DELETE CASCADE,
                                               user_id       BIGINT NULL, -- NULL = default set for the area
                                               name          VARCHAR(160) NOT NULL,
                                               is_default    BOOLEAN NOT NULL DEFAULT FALSE,
                                               created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                                               updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                                               UNIQUE (study_area_id, user_id, name)
);

-- One default per study area for global (user_id IS NULL) presets
CREATE UNIQUE INDEX IF NOT EXISTS mca_preset_sets_one_default_per_area
    ON mca_preset_sets(study_area_id)
    WHERE user_id IS NULL AND is_default = TRUE;

-- 3) Preset items: weights and optional direction override per indicator
CREATE TABLE IF NOT EXISTS mca_preset_items (
                                                preset_set_id BIGINT NOT NULL REFERENCES mca_preset_sets(id) ON DELETE CASCADE,
                                                indicator_id  BIGINT NOT NULL REFERENCES mca_indicators(id) ON DELETE RESTRICT,
                                                weight        DOUBLE PRECISION NOT NULL CHECK (weight >= 0),
                                                direction     TEXT NULL CHECK (direction IN ('pos','neg')), -- NULL = use indicator.default_direction
                                                is_enabled    BOOLEAN NOT NULL DEFAULT TRUE,
                                                PRIMARY KEY (preset_set_id, indicator_id)
);

-- 4) Scenarios inside a preset set: baseline/compost/bmp2 linked to a swat_run
CREATE TABLE IF NOT EXISTS mca_scenarios (
                                             id            BIGSERIAL PRIMARY KEY,
                                             preset_set_id BIGINT NOT NULL REFERENCES mca_preset_sets(id) ON DELETE CASCADE,
                                             scenario_key  VARCHAR(32) NOT NULL,   -- 'baseline', 'compost', 'bmp2'
                                             label         VARCHAR(64) NOT NULL,   -- display name
                                             run_id        BIGINT NULL REFERENCES swat_runs(id) ON DELETE SET NULL,
                                             sort_order    INTEGER NOT NULL DEFAULT 0,
                                             UNIQUE (preset_set_id, scenario_key)
);

-- 5) Results cache (raw + normalized + weighted)
CREATE TABLE IF NOT EXISTS mca_results (
                                           id               BIGSERIAL PRIMARY KEY,
                                           preset_set_id    BIGINT NOT NULL REFERENCES mca_preset_sets(id) ON DELETE CASCADE,
                                           scenario_id      BIGINT NOT NULL REFERENCES mca_scenarios(id) ON DELETE CASCADE,
                                           indicator_id     BIGINT NOT NULL REFERENCES mca_indicators(id) ON DELETE RESTRICT,
                                           crop_code        VARCHAR(8) NULL REFERENCES crops(code) ON DELETE SET NULL,
                                           raw_value        DOUBLE PRECISION NULL,
                                           normalized_score DOUBLE PRECISION NULL,
                                           weighted_score   DOUBLE PRECISION NULL,
                                           computed_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                                           UNIQUE (preset_set_id, scenario_id, indicator_id, crop_code)
);

CREATE INDEX IF NOT EXISTS mca_results_lookup
    ON mca_results(preset_set_id, scenario_id, crop_code);

-- 6) Helpful view: total per scenario
CREATE OR REPLACE VIEW mca_totals AS
SELECT
    r.preset_set_id,
    r.scenario_id,
    s.run_id,
    s.scenario_key,
    s.label,
    r.crop_code,
    SUM(r.weighted_score) AS total_weighted_score
FROM mca_results r
         JOIN mca_scenarios s ON s.id = r.scenario_id
GROUP BY
    r.preset_set_id,
    r.scenario_id,
    s.run_id,
    s.scenario_key,
    s.label,
    r.crop_code;