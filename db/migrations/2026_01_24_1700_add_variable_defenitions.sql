-- Add crop-level economic variables used by MCA.
-- Safe to re-run due to ON CONFLICT.

INSERT INTO mca_variables (key, name, unit, description, data_type)
VALUES
    ('prod_cost_bmp_usd_ha',      'Production cost (BMP)', 'USD/ha', 'Per-ha production cost under BMP scenario.', 'number'),
    ('prod_cost_ref_usd_per_t',   'Production cost (REF)', 'USD/t',  'Production cost in reference scenario per ton yield.', 'number')
ON CONFLICT (key) DO NOTHING;

-- Add farm-level inputs (non-crop).
INSERT INTO mca_variables (key, name, unit, description, data_type)
VALUES
    ('farm_size_ha',         'Farm size',       'ha',      'Representative farm size.', 'number'),
    ('land_rent_usd_ha',     'Land rent',       'USD/ha',  'Annual land rent per hectare.', 'number'),
    ('water_use_fee_usd_m3', 'Water use fee',   'USD/m³',  'Fee per cubic meter of irrigation water.', 'number')
ON CONFLICT (key) DO NOTHING;