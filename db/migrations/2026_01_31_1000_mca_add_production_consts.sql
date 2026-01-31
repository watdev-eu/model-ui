BEGIN;

-- Ensure unique key index exists (safe if already there)
CREATE UNIQUE INDEX IF NOT EXISTS mca_variables_key_uq
    ON public.mca_variables ("key");

-- Upsert new BMP production cost factor variables
WITH vars(key, name, unit, description, data_type) AS (
    VALUES
        -- ------------------------------------------------------------
        -- Study-area global: cost per labour day
        -- ------------------------------------------------------------
        ('labour_day_cost_usd_per_pd',
         'Labour day cost', 'USD/person-day',
         'Cost of 1 person-day of labour.',
         'number'),

        -- ------------------------------------------------------------
        -- Labour inputs (person-days/ha) — per crop per scenario (run)
        -- ------------------------------------------------------------
        ('bmp_labour_land_preparation_pd_ha',
         'Labour: land preparation', 'person-days/ha',
         'Person-days of labour per hectare for land preparation.',
         'number'),

        ('bmp_labour_planting_pd_ha',
         'Labour: planting', 'person-days/ha',
         'Person-days of labour per hectare for planting.',
         'number'),

        ('bmp_labour_fertilizer_application_pd_ha',
         'Labour: fertilizer application', 'person-days/ha',
         'Person-days of labour per hectare for fertilizer application.',
         'number'),

        ('bmp_labour_weeding_pd_ha',
         'Labour: weeding', 'person-days/ha',
         'Person-days of labour per hectare for weeding.',
         'number'),

        ('bmp_labour_pest_control_pd_ha',
         'Labour: pest control', 'person-days/ha',
         'Person-days of labour per hectare for pest control (BMP scenario; crop+run specific).',
         'number'),

        ('bmp_labour_irrigation_pd_ha',
         'Labour: irrigation', 'person-days/ha',
         'Person-days of labour per hectare for irrigation.',
         'number'),

        ('bmp_labour_harvesting_pd_ha',
         'Labour: harvesting', 'person-days/ha',
         'Person-days of labour per hectare for harvesting.',
         'number'),

        ('bmp_labour_other_pd_ha',
         'Labour: other', 'person-days/ha',
         'Other person-days of labour per hectare.',
         'number'),

        -- ------------------------------------------------------------
        -- Material inputs (USD/ha) — per crop per scenario (run)
        -- ------------------------------------------------------------
        ('bmp_material_seeds_usd_ha',
         'Materials: seeds / planting material', 'USD/ha',
         'Cost per hectare for seeds / planting material.',
         'number'),

        ('bmp_material_mineral_fertilisers_usd_ha',
         'Materials: mineral fertilisers', 'USD/ha',
         'Cost per hectare for mineral fertilisers.',
         'number'),

        ('bmp_material_organic_amendments_usd_ha',
         'Materials: organic amendments', 'USD/ha',
         'Cost per hectare for organic amendments.',
         'number'),

        ('bmp_material_pesticides_usd_ha',
         'Materials: pesticides', 'USD/ha',
         'Cost per hectare for pesticides.',
         'number'),

        ('bmp_material_tractor_usage_usd_ha',
         'Materials: tractor usage', 'USD/ha',
         'Cost per hectare for tractor usage / fuel / tractor services.',
         'number'),

        ('bmp_material_equipment_usage_usd_ha',
         'Materials: equipment usage', 'USD/ha',
         'Cost per hectare for equipment usage / machinery services.',
         'number'),

        ('bmp_material_other_usd_ha',
         'Materials: other', 'USD/ha',
         'Other material costs per hectare.',
         'number')
)
INSERT INTO public.mca_variables ("key", name, unit, description, data_type)
SELECT v.key, v.name, v.unit, v.description, v.data_type
FROM vars v
ON CONFLICT ("key") DO UPDATE SET
  name        = EXCLUDED.name,
  unit        = EXCLUDED.unit,
  description = EXCLUDED.description,
  data_type   = EXCLUDED.data_type;

COMMIT;