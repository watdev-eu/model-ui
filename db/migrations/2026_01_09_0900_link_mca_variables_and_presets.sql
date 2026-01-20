ALTER TABLE mca_variable_sets
    ADD COLUMN preset_set_id INTEGER;

ALTER TABLE mca_variable_sets
    ADD CONSTRAINT mca_variable_sets_preset_fk
        FOREIGN KEY (preset_set_id) REFERENCES mca_preset_sets(id)
            ON DELETE CASCADE;

-- Only one default set per (preset_set_id, user_id)
CREATE UNIQUE INDEX ux_mca_varsets_default_per_preset_user
    ON mca_variable_sets (preset_set_id, user_id)
    WHERE is_default = TRUE;

CREATE UNIQUE INDEX ux_mca_varsets_name_per_preset_user
    ON mca_variable_sets (preset_set_id, user_id, COALESCE(name,''));

-- Attach variable sets to the default preset_set per study_area
WITH default_preset AS (
    SELECT DISTINCT ON (study_area_id)
    id AS preset_set_id, study_area_id
FROM mca_preset_sets
WHERE is_default = TRUE AND user_id IS NULL
ORDER BY study_area_id, id ASC
    )
UPDATE mca_variable_sets vs
SET preset_set_id = dp.preset_set_id
    FROM default_preset dp
WHERE vs.study_area_id = dp.study_area_id
  AND vs.preset_set_id IS NULL;

ALTER TABLE mca_variable_sets
    ALTER COLUMN preset_set_id SET NOT NULL;

INSERT INTO mca_variables (key, name, unit, description, data_type)
VALUES
    ('bmp_annual_benefit_usd_ha', 'BMP annual benefit', 'USD/ha/year', 'Annual benefit per hectare used for BCR', 'number'),
    ('labour_hours_per_ha', 'Labour use', 'hours/ha', 'Labour required per hectare (for labour indicator)', 'number'),
    ('net_income_after_usd_ha', 'Net farm income (after)', 'USD/ha', 'Net income after BMP used for % increase indicator', 'number');

-- Add default values for new variables to all default sets
INSERT INTO mca_variable_values (variable_set_id, variable_id, value_num, value_text, value_bool)
SELECT
    vs.id,
    v.id,
    CASE v.key
        WHEN 'bmp_annual_benefit_usd_ha' THEN 0
        WHEN 'labour_hours_per_ha' THEN 0
        WHEN 'net_income_after_usd_ha' THEN 0
        ELSE NULL
        END AS value_num,
    NULL AS value_text,
    NULL AS value_bool
FROM mca_variable_sets vs
         JOIN mca_variables v ON v.key IN ('bmp_annual_benefit_usd_ha','labour_hours_per_ha','net_income_after_usd_ha')
         LEFT JOIN mca_variable_values vv
                   ON vv.variable_set_id = vs.id AND vv.variable_id = v.id
WHERE vv.variable_id IS NULL;