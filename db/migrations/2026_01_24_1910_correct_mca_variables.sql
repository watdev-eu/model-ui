BEGIN;

-- Variables to remove (not part of current modal structure)
WITH to_remove AS (
    SELECT id
    FROM mca_variables
    WHERE key IN (
    'baseline_net_income_usd_ha',
    'net_income_after_usd_ha',
    'labour_cost_usd_per_hour',
    'labour_hours_per_ha',
    'include_labour_in_costs',
    'bmp_annual_benefit_usd_ha'
    )
    )
-- delete global values
DELETE FROM mca_variable_values vv
    USING to_remove r
WHERE vv.variable_id = r.id;

WITH to_remove AS (
    SELECT id
    FROM mca_variables
    WHERE key IN (
    'baseline_net_income_usd_ha',
    'net_income_after_usd_ha',
    'labour_cost_usd_per_hour',
    'labour_hours_per_ha',
    'include_labour_in_costs',
    'bmp_annual_benefit_usd_ha'
    )
    )
-- delete crop values (in case any exist)
DELETE FROM mca_variable_values_crop vvc
    USING to_remove r
WHERE vvc.variable_id = r.id;

-- finally delete the variables themselves
DELETE FROM mca_variables
WHERE key IN (
    'baseline_net_income_usd_ha',
    'net_income_after_usd_ha',
    'labour_cost_usd_per_hour',
    'labour_hours_per_ha',
    'include_labour_in_costs',
    'bmp_annual_benefit_usd_ha'
    );

COMMIT;