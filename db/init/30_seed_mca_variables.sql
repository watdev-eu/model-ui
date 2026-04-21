-- Ensure only one global default variable set exists per study area
CREATE UNIQUE INDEX IF NOT EXISTS uniq_mca_variable_sets_default_global
    ON mca_variable_sets (study_area_id)
    WHERE user_id IS NULL AND is_default = TRUE;

-- Ensure only one global default preset set exists per study area
CREATE UNIQUE INDEX IF NOT EXISTS uniq_mca_preset_sets_default_global
    ON mca_preset_sets (study_area_id)
    WHERE user_id IS NULL AND is_default = TRUE;

-- Seed missing default variable sets
INSERT INTO mca_variable_sets (study_area_id, user_id, name, is_default)
SELECT sa.id, NULL, 'Default', TRUE
FROM study_areas sa
ON CONFLICT DO NOTHING;

-- Seed missing default preset sets
INSERT INTO mca_preset_sets (study_area_id, user_id, name, is_default)
SELECT sa.id, NULL, 'Default', TRUE
FROM study_areas sa
ON CONFLICT DO NOTHING;