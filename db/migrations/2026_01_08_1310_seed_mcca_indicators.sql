INSERT INTO mca_indicators (code, name, unit, default_direction, description, calc_key)
VALUES
    ('10.2', 'Benefit–Cost Ratio (BCR) of BMP', 'ratio', 'pos', 'Discounted benefits / discounted costs', 'bcr'),
    ('12.2', '% increase in net farm income', '%', 'pos', '(after - before) / before', 'income_increase_pct'),
    ('12.3', 'Labour use', 'hours/ha', 'neg', 'Labour required per hectare', 'labour_use'),
    ('15.1', 'Intensity of water use by agriculture', 'm3/ha', 'neg', 'Irrigation water use per ha', 'water_use_intensity'),
    ('15.2.1', 'Technical efficiency in water use', 'kg/m3', 'pos', 'Yield / water use', 'water_tech_eff')
ON CONFLICT (code) DO NOTHING;