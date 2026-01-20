CREATE TABLE IF NOT EXISTS mca_variables (
     id          BIGSERIAL PRIMARY KEY,
     key         VARCHAR(64) UNIQUE NOT NULL,     -- e.g. 'discount_rate'
     name        TEXT NOT NULL,                   -- 'Discount rate'
     unit        TEXT NULL,                       -- '%', 'USD/t'
     description TEXT NULL,
     data_type   TEXT NOT NULL DEFAULT 'number' CHECK (data_type IN ('number','text','bool')),
     created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS mca_variable_sets (
     id            BIGSERIAL PRIMARY KEY,
     study_area_id INTEGER NOT NULL REFERENCES study_areas(id) ON DELETE CASCADE,
     user_id       BIGINT NULL, -- NULL = default
     name          VARCHAR(160) NOT NULL,
     is_default    BOOLEAN NOT NULL DEFAULT FALSE,
     created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
     updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
     UNIQUE (study_area_id, user_id, name)
);

CREATE UNIQUE INDEX IF NOT EXISTS mca_variable_sets_one_default_per_area
    ON mca_variable_sets(study_area_id)
    WHERE user_id IS NULL AND is_default = TRUE;

CREATE TABLE IF NOT EXISTS mca_variable_values (
   variable_set_id BIGINT NOT NULL REFERENCES mca_variable_sets(id) ON DELETE CASCADE,
   variable_id     BIGINT NOT NULL REFERENCES mca_variables(id) ON DELETE RESTRICT,
   value_num       DOUBLE PRECISION NULL,
   value_text      TEXT NULL,
   value_bool      BOOLEAN NULL,
   PRIMARY KEY (variable_set_id, variable_id)
);

-- ---- Seed MCA post-processing variables catalog ----
INSERT INTO mca_variables (key, name, unit, description, data_type)
VALUES
    ('discount_rate', 'Discount rate', 'fraction', 'Discount rate used for NPV/BCR calculations (e.g. 0.10)', 'number'),
    ('time_horizon_years', 'Time horizon', 'years', 'Number of years used in discounted calculations', 'number'),

    -- Basic economics (adjust to your Excel)
    ('baseline_net_income_usd_ha', 'Baseline net income', 'USD/ha', 'Baseline net farm income used for % increase indicator', 'number'),
    ('labour_cost_usd_per_hour', 'Labour cost', 'USD/hour', 'Used when converting labour hours to costs (if applicable)', 'number'),

    -- BMP / intervention costs (placeholders until Excel mapping is finalized)
    ('bmp_invest_cost_usd_ha', 'BMP investment cost', 'USD/ha', 'One-time investment cost per hectare', 'number'),
    ('bmp_annual_om_cost_usd_ha', 'BMP annual O&M cost', 'USD/ha/year', 'Annual operations and maintenance cost per hectare', 'number'),

    -- Optional switch flags
    ('include_labour_in_costs', 'Include labour in costs', NULL, 'If true, labour hours can be monetized and included in costs', 'bool')
ON CONFLICT (key) DO NOTHING;

-- ---- Create one default variable set per study area (global/default) ----
INSERT INTO mca_variable_sets (study_area_id, user_id, name, is_default)
SELECT sa.id, NULL, 'Default MCA variables', TRUE
FROM study_areas sa
WHERE NOT EXISTS (
    SELECT 1
    FROM mca_variable_sets vs
    WHERE vs.study_area_id = sa.id
      AND vs.user_id IS NULL
      AND vs.is_default = TRUE
);

-- ---- Seed default values into every default variable set (only missing) ----
WITH default_sets AS (
    SELECT id AS variable_set_id
    FROM mca_variable_sets
    WHERE user_id IS NULL AND is_default = TRUE
),
     vars AS (
         SELECT id, key, data_type
FROM mca_variables
    ),
    defaults AS (
SELECT
    ds.variable_set_id,
    v.id AS variable_id,

    -- numeric defaults
    CASE v.key
    WHEN 'discount_rate' THEN 0.10
    WHEN 'time_horizon_years' THEN 10
    WHEN 'baseline_net_income_usd_ha' THEN 0.0
    WHEN 'labour_cost_usd_per_hour' THEN 0.0
    WHEN 'bmp_invest_cost_usd_ha' THEN 0.0
    WHEN 'bmp_annual_om_cost_usd_ha' THEN 0.0
    ELSE NULL
    END::double precision AS value_num,

    -- text defaults (none yet)
    NULL::text AS value_text,

    -- boolean defaults
    CASE v.key
    WHEN 'include_labour_in_costs' THEN FALSE
    ELSE NULL
    END AS value_bool

FROM default_sets ds
    CROSS JOIN vars v
    )
INSERT INTO mca_variable_values (variable_set_id, variable_id, value_num, value_text, value_bool)
SELECT
    d.variable_set_id,
    d.variable_id,
    d.value_num,
    d.value_text,
    d.value_bool
FROM defaults d
WHERE NOT EXISTS (
    SELECT 1
    FROM mca_variable_values vv
    WHERE vv.variable_set_id = d.variable_set_id
      AND vv.variable_id = d.variable_id
);