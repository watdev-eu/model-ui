USE watdev;

-- ------------------------------------------------------------------
-- Extend HRU KPI primary key to include SUB and GIS
-- ------------------------------------------------------------------
ALTER TABLE swat_hru_kpi
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (run_id, hru, sub, gis, period_date);

ALTER TABLE swat_rch_kpi
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (run_id, rch, gis, period_date);

ALTER TABLE swat_snu_kpi
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (run_id, gisnum, period_date);