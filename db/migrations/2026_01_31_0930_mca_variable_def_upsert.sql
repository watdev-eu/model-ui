BEGIN;

-- 0) Ensure key uniqueness for ON CONFLICT
CREATE UNIQUE INDEX IF NOT EXISTS mca_variables_key_uq
    ON public.mca_variables ("key");

-- 1) Rename time_horizon_years -> economic_life_years (preserve variable_id + values)
-- If economic_life_years already exists, we DON'T rename to avoid key conflict.
UPDATE public.mca_variables mv
SET "key" = 'economic_life_years',
    name = 'Economic life',
    unit = 'years',
    description = 'Economic life in years; after this period reinvestment occurs.'
WHERE mv."key" = 'time_horizon_years'
  AND NOT EXISTS (
    SELECT 1 FROM public.mca_variables x WHERE x."key" = 'economic_life_years'
);

-- 2) Upsert / adjust variable definitions (ONLY what we need now)
WITH vars(key, name, unit, description, data_type) AS (
    VALUES
        -- Global defaults (study area)
        ('farm_size_ha',
         'Farm size','ha',
         'Representative farm size used in MCA computations.',
         'number'),

        -- Land rent (per study area; annual)
        ('land_rent_usd_ha_yr',
         'Land rent','USD/ha/yr',
         'Annual land rent per hectare.',
         'number'),

        -- Global crop variable
        ('crop_price_usd_per_t',
         'Crop price','USD/t',
         'Crop farm-gate price used for economic indicators.',
         'number'),

        -- Discount rate becomes PERCENT
        ('discount_rate',
         'Discount rate','%',
         'Discount rate as percentage (e.g. 10 for 10%).',
         'number'),

        -- Economic life (renamed from time_horizon_years above)
        ('economic_life_years',
         'Economic life','years',
         'Economic life in years; after this period reinvestment occurs.',
         'number'),

        -- BMP investment cost: updated description for reinvestment logic
        ('bmp_invest_cost_usd_ha',
         'BMP investment cost','USD/ha',
         'Up-front BMP investment cost per hectare; reinvested after each economic life cycle.',
         'number'),

        ('bmp_annual_om_cost_usd_ha',
         'BMP annual O&M cost','USD/ha/yr',
         'Annual operations and maintenance cost per hectare.',
         'number'),

        -- These will become per scenario later (for now we keep definitions consistent)
        ('water_cost_usd_m3',
         'Water cost','USD/m3',
         'Cost of irrigated water (purchase/pumping/delivery).',
         'number'),

        ('water_use_fee_usd_ha',
         'Water use fee','USD/ha',
         'Fee per hectare (e.g., Water Use Association membership).',
         'number'),

        -- Still used today (until replaced by labour/material breakdown)
        ('prod_cost_bmp_usd_ha',
         'Production cost (BMP)','USD/ha',
         'Per-ha production cost under BMP scenario.',
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

-- 3) Migrate land rent key values: land_rent_usd_ha -> land_rent_usd_ha_yr
WITH ids AS (
    SELECT
        (SELECT id FROM public.mca_variables WHERE "key" = 'land_rent_usd_ha')     AS old_vid,
        (SELECT id FROM public.mca_variables WHERE "key" = 'land_rent_usd_ha_yr')  AS new_vid
)
UPDATE public.mca_variable_values v
SET variable_id = ids.new_vid
    FROM ids
WHERE ids.old_vid IS NOT NULL AND ids.new_vid IS NOT NULL
  AND v.variable_id = ids.old_vid;

WITH ids AS (
    SELECT
        (SELECT id FROM public.mca_variables WHERE "key" = 'land_rent_usd_ha')     AS old_vid,
        (SELECT id FROM public.mca_variables WHERE "key" = 'land_rent_usd_ha_yr')  AS new_vid
)
UPDATE public.mca_variable_values_crop v
SET variable_id = ids.new_vid
    FROM ids
WHERE ids.old_vid IS NOT NULL AND ids.new_vid IS NOT NULL
  AND v.variable_id = ids.old_vid;

WITH ids AS (
    SELECT
        (SELECT id FROM public.mca_variables WHERE "key" = 'land_rent_usd_ha')     AS old_vid,
        (SELECT id FROM public.mca_variables WHERE "key" = 'land_rent_usd_ha_yr')  AS new_vid
)
UPDATE public.mca_variable_values_run v
SET variable_id = ids.new_vid
    FROM ids
WHERE ids.old_vid IS NOT NULL AND ids.new_vid IS NOT NULL
  AND v.variable_id = ids.old_vid;

WITH ids AS (
    SELECT
        (SELECT id FROM public.mca_variables WHERE "key" = 'land_rent_usd_ha')     AS old_vid,
        (SELECT id FROM public.mca_variables WHERE "key" = 'land_rent_usd_ha_yr')  AS new_vid
)
UPDATE public.mca_variable_values_crop_run v
SET variable_id = ids.new_vid
    FROM ids
WHERE ids.old_vid IS NOT NULL AND ids.new_vid IS NOT NULL
  AND v.variable_id = ids.old_vid;

-- Remove old land rent variable definition
DELETE FROM public.mca_variables
WHERE "key" = 'land_rent_usd_ha';

-- 4) Convert discount_rate stored values from fraction -> percent (x100)
-- (Safe even if no rows exist.)
UPDATE public.mca_variable_values
SET value_num = value_num * 100
WHERE variable_id = (SELECT id FROM public.mca_variables WHERE "key" = 'discount_rate')
  AND value_num IS NOT NULL;

UPDATE public.mca_variable_values_run
SET value_num = value_num * 100
WHERE variable_id = (SELECT id FROM public.mca_variables WHERE "key" = 'discount_rate')
  AND value_num IS NOT NULL;

UPDATE public.mca_variable_values_crop
SET value_num = value_num * 100
WHERE variable_id = (SELECT id FROM public.mca_variables WHERE "key" = 'discount_rate')
  AND value_num IS NOT NULL;

UPDATE public.mca_variable_values_crop_run
SET value_num = value_num * 100
WHERE variable_id = (SELECT id FROM public.mca_variables WHERE "key" = 'discount_rate')
  AND value_num IS NOT NULL;

-- 5) Remove variables you said are no longer used
-- (Delete dependent values first to avoid FK violations)
WITH del AS (
    SELECT id
    FROM public.mca_variables
    WHERE "key" IN (
                    'prod_cost_ref_usd_per_t',
                    'prod_cost_ref_usd_ha',
                    'bmp_annual_cost_usd_ha',
                    'bmp_prod_cost_usd_ha',
                    'water_use_fee_usd_m3'
        )
)
DELETE FROM public.mca_variable_values
WHERE variable_id IN (SELECT id FROM del);

WITH del AS (
    SELECT id
    FROM public.mca_variables
    WHERE "key" IN (
                    'prod_cost_ref_usd_per_t',
                    'prod_cost_ref_usd_ha',
                    'bmp_annual_cost_usd_ha',
                    'bmp_prod_cost_usd_ha',
                    'water_use_fee_usd_m3'
        )
)
DELETE FROM public.mca_variable_values_run
WHERE variable_id IN (SELECT id FROM del);

WITH del AS (
    SELECT id
    FROM public.mca_variables
    WHERE "key" IN (
                    'prod_cost_ref_usd_per_t',
                    'prod_cost_ref_usd_ha',
                    'bmp_annual_cost_usd_ha',
                    'bmp_prod_cost_usd_ha',
                    'water_use_fee_usd_m3'
        )
)
DELETE FROM public.mca_variable_values_crop
WHERE variable_id IN (SELECT id FROM del);

WITH del AS (
    SELECT id
    FROM public.mca_variables
    WHERE "key" IN (
                    'prod_cost_ref_usd_per_t',
                    'prod_cost_ref_usd_ha',
                    'bmp_annual_cost_usd_ha',
                    'bmp_prod_cost_usd_ha',
                    'water_use_fee_usd_m3'
        )
)
DELETE FROM public.mca_variable_values_crop_run
WHERE variable_id IN (SELECT id FROM del);

DELETE FROM public.mca_variables
WHERE "key" IN (
                'prod_cost_ref_usd_per_t',
                'prod_cost_ref_usd_ha',
                'bmp_annual_cost_usd_ha',
                'bmp_prod_cost_usd_ha',
                'water_use_fee_usd_m3'
    );

COMMIT;