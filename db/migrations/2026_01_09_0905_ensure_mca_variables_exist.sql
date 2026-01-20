-- 1) Add missing MCA variables (only if they do not exist yet)
INSERT INTO mca_variables (key, name, unit, description, data_type)
SELECT * FROM (VALUES
                   ('net_income_after_usd_ha', 'Net income after (scenario)', 'USD/ha',
                    'Net farm income after implementing the scenario/BMP. Used for % income increase.', 'number'),

                   ('labour_hours_per_ha', 'Labour hours per hectare', 'hours/ha',
                    'Labour required per hectare (scenario/BMP). Used for labour use indicator.', 'number'),

                   ('bmp_annual_benefit_usd_ha', 'Annual benefit of BMP', 'USD/ha/year',
                    'Annual monetary benefit per hectare used for BCR. If omitted, it can be derived from income delta.', 'number')
              ) AS v(key, name, unit, description, data_type)
WHERE NOT EXISTS (
    SELECT 1 FROM mca_variables mv WHERE mv.key = v.key
);

-- 2) Seed values into ALL default variable sets (per study_area) if missing
INSERT INTO mca_variable_values (variable_set_id, variable_id, value_num, value_text, value_bool)
SELECT
    vs.id AS variable_set_id,
    v.id  AS variable_id,
    0     AS value_num,
    NULL  AS value_text,
    NULL  AS value_bool
FROM mca_variable_sets vs
         JOIN mca_variables v
              ON v.key IN ('net_income_after_usd_ha', 'labour_hours_per_ha', 'bmp_annual_benefit_usd_ha')
WHERE vs.is_default = TRUE
  AND NOT EXISTS (
    SELECT 1
    FROM mca_variable_values vv
    WHERE vv.variable_set_id = vs.id
      AND vv.variable_id = v.id
);