USE watdev;

ALTER TABLE swat_rch_kpi
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (run_id, rch, period_date);

ALTER TABLE swat_rch_kpi
    CHANGE COLUMN gis sub INT DEFAULT NULL;