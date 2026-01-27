INSERT INTO mca_variables (key, name, unit, description, data_type)
SELECT
    'water_cost_usd_m3',
    'Water cost (USD/m³)',
    'USD/m³',
    'Cost of irrigated water (purchase / pumping / delivery, depending on context).',
    'number'
WHERE NOT EXISTS (
    SELECT 1 FROM mca_variables WHERE key = 'water_cost_usd_m3'
);

INSERT INTO mca_variables (key, name, unit, description, data_type)
SELECT
    'bmp_prod_cost_usd_ha',
    'BMP production cost (USD/ha)',
    'USD/ha',
    'Scenario-level production cost for BMP implementation (not crop-specific).',
    'number'
WHERE NOT EXISTS (
    SELECT 1 FROM mca_variables WHERE key = 'bmp_prod_cost_usd_ha'
);

INSERT INTO mca_variables (key, name, unit, description, data_type)
SELECT
    'bmp_annual_cost_usd_ha',
    'BMP annual cost (USD/ha/year)',
    'USD/ha/yr',
    'Scenario-level annual cost (O&M + recurring).',
    'number'
WHERE NOT EXISTS (
    SELECT 1 FROM mca_variables WHERE key = 'bmp_annual_cost_usd_ha'
);