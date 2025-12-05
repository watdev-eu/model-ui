USE watdev;

CREATE OR REPLACE VIEW v_kpi_subbasin_monthly AS
SELECT
    r.id          AS run_id,
    r.study_area,
    r.bmp,
    r.run_label,
    h.sub         AS subbasin_id,
    h.period_date,
    h.period_res,

    -- total HRU area (km2)
    SUM(h.area_km2) AS area_km2,

    -- Area-weighted averages (mm or t/ha or kg/ha)
    SUM(h.irr_mm      * h.area_km2) / NULLIF(SUM(h.area_km2), 0) AS irr_mm_avg,
    SUM(h.irr_sa_mm   * h.area_km2) / NULLIF(SUM(h.area_km2), 0) AS irr_sa_mm_avg,
    SUM(h.irr_da_mm   * h.area_km2) / NULLIF(SUM(h.area_km2), 0) AS irr_da_mm_avg,
    SUM(h.yld_t_ha    * h.area_km2) / NULLIF(SUM(h.area_km2), 0) AS yld_t_ha_avg,
    SUM(h.biom_t_ha   * h.area_km2) / NULLIF(SUM(h.area_km2), 0) AS biom_t_ha_avg,
    SUM(h.syld_t_ha   * h.area_km2) / NULLIF(SUM(h.area_km2), 0) AS syld_t_ha_avg,
    SUM(h.nup_kg_ha   * h.area_km2) / NULLIF(SUM(h.area_km2), 0) AS nup_kg_ha_avg,
    SUM(h.pup_kg_ha   * h.area_km2) / NULLIF(SUM(h.area_km2), 0) AS pup_kg_ha_avg,
    SUM(h.no3l_kg_ha  * h.area_km2) / NULLIF(SUM(h.area_km2), 0) AS no3l_kg_ha_avg,

    -- Area-weighted totals for application-related indicators (kg)
    SUM(h.n_app_kg_ha  * h.area_km2) AS n_app_kg,
    SUM(h.p_app_kg_ha  * h.area_km2) AS p_app_kg,
    SUM(h.nauto_kg_ha   * h.area_km2) AS nauto_kg,
    SUM(h.pauto_kg_ha   * h.area_km2) AS pauto_kg,
    SUM(h.ngraz_kg_ha   * h.area_km2) AS ngraz_kg,
    SUM(h.pgraz_kg_ha   * h.area_km2) AS pgraz_kg,
    SUM(h.cfertn_kg_ha  * h.area_km2) AS cfertn_kg,
    SUM(h.cfertp_kg_ha  * h.area_km2) AS cfertp_kg

FROM swat_runs     AS r
         JOIN swat_hru_kpi AS h ON h.run_id = r.id
WHERE h.period_res = 'MONTHLY'
GROUP BY
    r.id, r.study_area, r.bmp, r.run_label,
    h.sub, h.period_date, h.period_res;