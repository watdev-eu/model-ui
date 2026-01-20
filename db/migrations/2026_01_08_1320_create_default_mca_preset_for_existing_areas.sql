-- Create one default preset set per study area (if not exists)
INSERT INTO mca_preset_sets (study_area_id, user_id, name, is_default)
SELECT sa.id, NULL, 'Default MCA', TRUE
FROM study_areas sa
WHERE NOT EXISTS (
    SELECT 1 FROM mca_preset_sets ps
    WHERE ps.study_area_id = sa.id AND ps.user_id IS NULL AND ps.is_default = TRUE
);

-- Add default scenarios (baseline/compost/bmp2) to each default set if missing
INSERT INTO mca_scenarios (preset_set_id, scenario_key, label, sort_order)
SELECT ps.id, v.scenario_key, v.label, v.sort_order
FROM mca_preset_sets ps
         CROSS JOIN (
    VALUES
        ('baseline','Baseline', 10),
        ('compost','Compost',  20),
        ('bmp2',   'BMP2',     30)
) AS v(scenario_key, label, sort_order)
WHERE ps.user_id IS NULL
  AND ps.is_default = TRUE
  AND NOT EXISTS (
    SELECT 1 FROM mca_scenarios s
    WHERE s.preset_set_id = ps.id AND s.scenario_key = v.scenario_key
);

-- Add preset items with equal weights across all indicators for each default set (if missing)
-- NOTE: equal weights = 1/N for seeded indicators. We compute N dynamically.
WITH n AS (
    SELECT COUNT(*)::double precision AS cnt FROM mca_indicators
    ),
    defaults AS (
SELECT id AS preset_set_id FROM mca_preset_sets WHERE user_id IS NULL AND is_default = TRUE
    )
INSERT INTO mca_preset_items (preset_set_id, indicator_id, weight, direction, is_enabled)
SELECT d.preset_set_id, i.id, (1.0 / n.cnt), NULL, TRUE
FROM defaults d
         CROSS JOIN mca_indicators i
         CROSS JOIN n
WHERE NOT EXISTS (
    SELECT 1 FROM mca_preset_items pi
    WHERE pi.preset_set_id = d.preset_set_id AND pi.indicator_id = i.id
);