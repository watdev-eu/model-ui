BEGIN;

WITH new_weights AS (
    SELECT *
    FROM (VALUES
              -- Kenya
              ('Kenya','bcr',9.0),
              ('Kenya','price_cost_ratio',9.0),
              ('Kenya','cost_saving_usd',9.0),
              ('Kenya','net_farm_income_usd_ha',8.5),
              ('Kenya','income_increase_pct',8.5),
              ('Kenya','labour_use',8.5),
              ('Kenya','water_use_intensity',2.5),
              ('Kenya','water_tech_eff',2.5),
              ('Kenya','water_econ_eff',2.5),
              ('Kenya','water_rights_access',2.5),
              ('Kenya','fertiliser_use_eff_n',11.0),
              ('Kenya','fertiliser_use_eff_p',11.0),
              ('Kenya','carbon_sequestration',15.5),

              -- Sudan
              ('Sudan','bcr',12.0),
              ('Sudan','price_cost_ratio',12.0),
              ('Sudan','cost_saving_usd',12.0),
              ('Sudan','net_farm_income_usd_ha',13.0),
              ('Sudan','income_increase_pct',13.0),
              ('Sudan','labour_use',13.0),
              ('Sudan','water_use_intensity',0.0),
              ('Sudan','water_tech_eff',0.0),
              ('Sudan','water_econ_eff',0.0),
              ('Sudan','water_rights_access',0.0),
              ('Sudan','fertiliser_use_eff_n',5.5),
              ('Sudan','fertiliser_use_eff_p',5.5),
              ('Sudan','carbon_sequestration',14.0),

              -- Egypt
              ('Egypt','bcr',5.0),
              ('Egypt','price_cost_ratio',5.0),
              ('Egypt','cost_saving_usd',5.0),
              ('Egypt','net_farm_income_usd_ha',8.5),
              ('Egypt','income_increase_pct',8.5),
              ('Egypt','labour_use',8.5),
              ('Egypt','water_use_intensity',7.5),
              ('Egypt','water_tech_eff',7.5),
              ('Egypt','water_econ_eff',7.5),
              ('Egypt','water_rights_access',7.5),
              ('Egypt','fertiliser_use_eff_n',7.5),
              ('Egypt','fertiliser_use_eff_p',7.5),
              ('Egypt','carbon_sequestration',14.5),

              -- Ethiopia
              ('Ethiopia','bcr',6.5),
              ('Ethiopia','price_cost_ratio',6.5),
              ('Ethiopia','cost_saving_usd',6.5),
              ('Ethiopia','net_farm_income_usd_ha',6.5),
              ('Ethiopia','income_increase_pct',6.5),
              ('Ethiopia','labour_use',6.5),
              ('Ethiopia','water_use_intensity',5.5),
              ('Ethiopia','water_tech_eff',5.5),
              ('Ethiopia','water_econ_eff',5.5),
              ('Ethiopia','water_rights_access',5.5),
              ('Ethiopia','fertiliser_use_eff_n',7.0),
              ('Ethiopia','fertiliser_use_eff_p',7.0),
              ('Ethiopia','carbon_sequestration',25.0)
         ) AS v(study_area_name, calc_key, weight)
),
     target_sets AS (
         SELECT
             ps.id AS preset_set_id,
             sa.name AS study_area_name
         FROM mca_preset_sets ps
                  JOIN study_areas sa ON sa.id = ps.study_area_id
         WHERE ps.is_default = true
           AND sa.name IN ('Kenya','Sudan','Egypt','Ethiopia')
     )
UPDATE mca_preset_items pi
SET weight = nw.weight
    FROM target_sets ts
JOIN new_weights nw
ON nw.study_area_name = ts.study_area_name
    JOIN mca_indicators mi
    ON mi.calc_key = nw.calc_key
WHERE pi.preset_set_id = ts.preset_set_id
  AND pi.indicator_id = mi.id;

COMMIT;