BEGIN;

INSERT INTO mca_variables (key, name, unit, data_type)
VALUES
    ('discount_rate', 'Discount rate', '%', 'number'),
    ('economic_life_years', 'Economic life', 'years', 'number'),
    ('bmp_invest_cost_usd_ha', 'BMP investment cost', 'USD/ha', 'number'),
    ('bmp_annual_om_cost_usd_ha', 'BMP annual O&M cost', 'USD/ha/yr', 'number'),
    ('crop_price_usd_per_t', 'Crop price', 'USD/t', 'number'),
    ('farm_size_ha', 'Farm size', 'ha', 'number'),
    ('land_rent_usd_ha_yr', 'Land rent', 'USD/ha/yr', 'number'),
    ('water_cost_usd_m3', 'Water cost', 'USD/m3', 'number'),
    ('water_use_fee_usd_ha', 'Water use fee', 'USD/ha', 'number'),
    ('labour_day_cost_usd_per_pd', 'Labour day cost', 'USD/person-day', 'number'),

    ('bmp_labour_land_preparation_pd_ha', 'Labour: land preparation', 'person-days/ha', 'number'),
    ('bmp_labour_planting_pd_ha', 'Labour: planting', 'person-days/ha', 'number'),
    ('bmp_labour_fertilizer_application_pd_ha', 'Labour: fertilizer application', 'person-days/ha', 'number'),
    ('bmp_labour_weeding_pd_ha', 'Labour: weeding', 'person-days/ha', 'number'),
    ('bmp_labour_pest_control_pd_ha', 'Labour: pest control', 'person-days/ha', 'number'),
    ('bmp_labour_irrigation_pd_ha', 'Labour: irrigation', 'person-days/ha', 'number'),
    ('bmp_labour_harvesting_pd_ha', 'Labour: harvesting', 'person-days/ha', 'number'),
    ('bmp_labour_other_pd_ha', 'Labour: other', 'person-days/ha', 'number'),

    ('bmp_material_seeds_usd_ha', 'Materials: seeds / planting material', 'USD/ha', 'number'),
    ('bmp_material_mineral_fertilisers_usd_ha', 'Materials: mineral fertilisers', 'USD/ha', 'number'),
    ('bmp_material_organic_amendments_usd_ha', 'Materials: organic amendments', 'USD/ha', 'number'),
    ('bmp_material_pesticides_usd_ha', 'Materials: pesticides', 'USD/ha', 'number'),
    ('bmp_material_tractor_usage_usd_ha', 'Materials: tractor usage', 'USD/ha', 'number'),
    ('bmp_material_equipment_usage_usd_ha', 'Materials: equipment usage', 'USD/ha', 'number'),
    ('bmp_material_other_usd_ha', 'Materials: other', 'USD/ha', 'number')
ON CONFLICT (key) DO UPDATE
SET
    name = EXCLUDED.name,
    unit = EXCLUDED.unit,
    data_type = EXCLUDED.data_type;

-- ------------------------------------------------------------------
-- Seed MCA indicators
-- ------------------------------------------------------------------
INSERT INTO mca_indicators (code, name, calc_key, default_direction)
VALUES
    ('10.2', 'Benefit-cost ratio of BMP', 'bcr', 'pos'),
    ('10.3', 'Price-Cost Ratio', 'price_cost_ratio', 'pos'),
    ('10.4', 'Cost saving (US$) as a result of BMP adoption', 'cost_saving_usd', 'pos'),
    ('12.1', 'Total household Net farm income', 'net_farm_income_usd_ha', 'pos'),
    ('12.2', '% increase in net farm income', 'income_increase_pct', 'pos'),
    ('12.3', 'Labour use', 'labour_use', 'neg'),
    ('15.1', 'Intensity of water use by agriculture', 'water_use_intensity', 'neg'),
    ('15.2.1', 'Technical efficiency (mc) in water use', 'water_tech_eff', 'pos'),
    ('15.2.2', 'Economic efficiency ($) in water use', 'water_econ_eff', 'pos'),
    ('15.5', 'No. of community members with access to water rights or secure water resource allocations', 'water_rights_access', 'pos'),
    ('20.1.1', 'Fertilizer use efficiency (nitrogen)', 'fertiliser_use_eff_n', 'pos'),
    ('20.1.2', 'Fertilizer use efficiency (phosphorus)', 'fertiliser_use_eff_p', 'pos'),
    ('26.1', 'Carbon sequestration', 'carbon_sequestration', 'pos')
ON CONFLICT (code) DO UPDATE
SET
    name = EXCLUDED.name,
    calc_key = EXCLUDED.calc_key,
    default_direction = EXCLUDED.default_direction;

-- ------------------------------------------------------------------
-- Seed default preset weights per study area
-- ------------------------------------------------------------------
WITH weights AS (
    SELECT * FROM (VALUES
                       ('Egypt',    '10.2',   5.0,  true),
                       ('Egypt',    '10.3',   5.0,  true),
                       ('Egypt',    '10.4',   5.0,  true),
                       ('Egypt',    '12.1',   8.5,  true),
                       ('Egypt',    '12.2',   8.5,  true),
                       ('Egypt',    '12.3',   8.5,  true),
                       ('Egypt',    '15.1',   7.5,  true),
                       ('Egypt',    '15.2.1', 7.5,  true),
                       ('Egypt',    '15.2.2', 7.5,  true),
                       ('Egypt',    '15.5',   7.5,  true),
                       ('Egypt',    '20.1.1', 7.5,  true),
                       ('Egypt',    '20.1.2', 7.5,  true),
                       ('Egypt',    '26.1',  14.5,  true),

                       ('Ethiopia', '10.2',   6.5,  true),
                       ('Ethiopia', '10.3',   6.5,  true),
                       ('Ethiopia', '10.4',   6.5,  true),
                       ('Ethiopia', '12.1',   6.5,  true),
                       ('Ethiopia', '12.2',   6.5,  true),
                       ('Ethiopia', '12.3',   6.5,  true),
                       ('Ethiopia', '15.1',   5.5,  true),
                       ('Ethiopia', '15.2.1', 5.5,  true),
                       ('Ethiopia', '15.2.2', 5.5,  true),
                       ('Ethiopia', '15.5',   5.5,  true),
                       ('Ethiopia', '20.1.1', 7.0,  true),
                       ('Ethiopia', '20.1.2', 7.0,  true),
                       ('Ethiopia', '26.1',  25.0,  true),

                       ('Kenya',    '10.2',   9.0,  true),
                       ('Kenya',    '10.3',   9.0,  true),
                       ('Kenya',    '10.4',   9.0,  true),
                       ('Kenya',    '12.1',   8.5,  true),
                       ('Kenya',    '12.2',   8.5,  true),
                       ('Kenya',    '12.3',   8.5,  true),
                       ('Kenya',    '15.1',   2.5,  true),
                       ('Kenya',    '15.2.1', 2.5,  true),
                       ('Kenya',    '15.2.2', 2.5,  true),
                       ('Kenya',    '15.5',   2.5,  true),
                       ('Kenya',    '20.1.1',11.0,  true),
                       ('Kenya',    '20.1.2',11.0,  true),
                       ('Kenya',    '26.1',  15.5,  true),

                       ('Sudan',    '10.2',  12.0,  true),
                       ('Sudan',    '10.3',  12.0,  true),
                       ('Sudan',    '10.4',  12.0,  true),
                       ('Sudan',    '12.1',  13.0,  true),
                       ('Sudan',    '12.2',  13.0,  true),
                       ('Sudan',    '12.3',  13.0,  true),
                       ('Sudan',    '15.1',   0.0,  true),
                       ('Sudan',    '15.2.1', 0.0,  true),
                       ('Sudan',    '15.2.2', 0.0,  true),
                       ('Sudan',    '15.5',   0.0,  true),
                       ('Sudan',    '20.1.1', 5.5,  true),
                       ('Sudan',    '20.1.2', 5.5,  true),
                       ('Sudan',    '26.1',  14.0,  true)
                  ) AS t(study_area_name, indicator_code, weight, is_enabled)
)
INSERT INTO mca_preset_items (preset_set_id, indicator_id, weight, direction, is_enabled)
SELECT
    ps.id,
    i.id,
    w.weight,
    i.default_direction,
    w.is_enabled
FROM weights w
         JOIN study_areas sa
              ON sa.name = w.study_area_name
         JOIN mca_preset_sets ps
              ON ps.study_area_id = sa.id
                  AND ps.user_id IS NULL
                  AND ps.is_default = TRUE
         JOIN mca_indicators i
              ON i.code = w.indicator_code
ON CONFLICT (preset_set_id, indicator_id) DO UPDATE
SET
    weight = EXCLUDED.weight,
    direction = EXCLUDED.direction,
    is_enabled = EXCLUDED.is_enabled;

COMMIT;