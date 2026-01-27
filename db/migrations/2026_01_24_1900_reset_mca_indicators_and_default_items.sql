BEGIN;

DELETE FROM mca_results;

-- 1) Clear preset items first (FK -> indicators)
DELETE FROM mca_preset_items;

-- 2) Reset indicators
DELETE FROM mca_indicators;

-- 3) Insert canonical indicator list
INSERT INTO mca_indicators (code, name, unit, default_direction, description, calc_key)
VALUES
    -- Economic
    ('10.2',   'Benefit-cost ratio of BMP',                              'ratio',   'pos', 'Discounted benefits / discounted costs.',                              'bcr'),
    ('10.3',   'Price-Cost Ratio',                                       'ratio',   'pos', 'Price-cost ratio (definition to be implemented).',                         'price_cost_ratio'),
    ('10.4',   'Cost saving (US$) as a result of BMP adoption',          'USD',     'pos', 'Cost saving due to BMP adoption (definition to be implemented).',           'cost_saving_usd'),
    ('12.1',   'Total household Net farm income',                        'USD/ha',  'pos', 'Total household net farm income (definition to be implemented).',           'net_farm_income_usd_ha'),
    ('12.2',   '% increase in net farm income',                          '%',       'pos', '(after - before) / before.',                                            'income_increase_pct'),

    -- Social
    ('12.3',   'Labour use',                                             'hours/ha','neg', 'Labour required per hectare.',                                            'labour_use'),
    ('15.5',   'No. of community members with access to water rights or secure water resource allocations',
     'count',   'pos', 'Access to water rights / secure allocations (to be implemented).',          'water_rights_access'),

    -- Environmental
    ('15.1',   'Intensity of water use by agriculture',                  'm3/ha',   'neg', 'Irrigation water use per hectare.',                                      'water_use_intensity'),
    ('15.2.1', 'Technical efficiency (mc) in water use',                 'kg/m3',   'pos', 'Yield / water use.',                                                      'water_tech_eff'),
    ('15.2.2', 'Economic efficiency ($) in water use',                   'USD/m3',  'pos', 'Economic value / water use.',                                            'water_econ_eff'),
    ('26.1',   'Carbon sequestration',                                   'tC/ha',   'pos', 'Carbon sequestration (to be implemented).',                                'carbon_sequestration'),
    ('20.1.1', 'Fertilizer use efficiency (nitrogen)',                   'kg/kg',   'pos', 'Nitrogen fertilizer use efficiency (to be implemented).',                  'fertiliser_use_eff_n'),
    ('20.1.2', 'Fertilizer use efficiency (phosphorus)',                 'kg/kg',   'pos', 'Phosphorus fertilizer use efficiency (to be implemented).',                'fertiliser_use_eff_p');

-- 4) Rebuild preset items for all default global preset sets (equal weights)
INSERT INTO mca_preset_items (preset_set_id, indicator_id, weight, direction, is_enabled)
SELECT
    ps.id,
    i.id,
    1.0 / (SELECT COUNT(*) FROM mca_indicators),
    NULL::text,
    TRUE
FROM mca_preset_sets ps
         CROSS JOIN mca_indicators i
WHERE ps.user_id IS NULL
  AND ps.is_default = TRUE;

COMMIT;