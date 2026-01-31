BEGIN;

-- Safety: ensure variable key is unique (required for ON CONFLICT usage elsewhere)
CREATE UNIQUE INDEX IF NOT EXISTS mca_variables_key_uq
    ON public.mca_variables ("key");

-- Delete all stored values for prod_cost_bmp_usd_ha first (FK-safe)
WITH vid AS (
    SELECT id
    FROM public.mca_variables
    WHERE "key" = 'prod_cost_bmp_usd_ha'
)
DELETE FROM public.mca_variable_values_crop_run vv
    USING vid
WHERE vv.variable_id = vid.id;

WITH vid AS (
    SELECT id
    FROM public.mca_variables
    WHERE "key" = 'prod_cost_bmp_usd_ha'
)
DELETE FROM public.mca_variable_values_run vv
    USING vid
WHERE vv.variable_id = vid.id;

WITH vid AS (
    SELECT id
    FROM public.mca_variables
    WHERE "key" = 'prod_cost_bmp_usd_ha'
)
DELETE FROM public.mca_variable_values_crop vv
    USING vid
WHERE vv.variable_id = vid.id;

WITH vid AS (
    SELECT id
    FROM public.mca_variable_values vv
             JOIN public.mca_variables v ON v.id = vv.variable_id
    WHERE v."key" = 'prod_cost_bmp_usd_ha'
)
DELETE FROM public.mca_variable_values
WHERE variable_id IN (SELECT id FROM public.mca_variables WHERE "key"='prod_cost_bmp_usd_ha');

-- Finally delete the variable definition
DELETE FROM public.mca_variables
WHERE "key" = 'prod_cost_bmp_usd_ha';

COMMIT;