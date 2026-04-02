BEGIN;

CREATE TABLE IF NOT EXISTS swat_crop_area_context (
                                                      run_id BIGINT NOT NULL REFERENCES swat_runs(id) ON DELETE CASCADE,
                                                      sub INTEGER NOT NULL,
                                                      crop VARCHAR(16) NOT NULL,
                                                      area_ha DOUBLE PRECISION NOT NULL,
                                                      PRIMARY KEY (run_id, sub, crop)
);

CREATE INDEX IF NOT EXISTS idx_swat_crop_area_context_run
    ON swat_crop_area_context (run_id);

CREATE INDEX IF NOT EXISTS idx_swat_crop_area_context_run_sub
    ON swat_crop_area_context (run_id, sub);


CREATE TABLE IF NOT EXISTS swat_irrigation_area_context (
                                                            run_id BIGINT NOT NULL REFERENCES swat_runs(id) ON DELETE CASCADE,
                                                            sub INTEGER NOT NULL,
                                                            year INTEGER NOT NULL,
                                                            month INTEGER NOT NULL,
                                                            irrigated_area_ha DOUBLE PRECISION NOT NULL,
                                                            PRIMARY KEY (run_id, sub, year, month)
);

CREATE INDEX IF NOT EXISTS idx_swat_irrigation_area_context_run
    ON swat_irrigation_area_context (run_id);

CREATE INDEX IF NOT EXISTS idx_swat_irrigation_area_context_run_year_month
    ON swat_irrigation_area_context (run_id, year, month);


INSERT INTO swat_crop_area_context (run_id, sub, crop, area_ha)
SELECT
    x.run_id,
    x.sub,
    x.crop,
    SUM(x.area_km2) * 100.0 AS area_ha
FROM (
         SELECT
             h.run_id,
             h.sub,
             h.lulc AS crop,
             h.gis,
             MAX(COALESCE(h.area_km2, 0)) AS area_km2
         FROM swat_hru_kpi h
         WHERE h.lulc IS NOT NULL
           AND h.lulc <> ''
         GROUP BY h.run_id, h.sub, h.lulc, h.gis
     ) x
GROUP BY x.run_id, x.sub, x.crop
ON CONFLICT (run_id, sub, crop)
DO UPDATE SET area_ha = EXCLUDED.area_ha;


INSERT INTO swat_irrigation_area_context (run_id, sub, year, month, irrigated_area_ha)
SELECT
    y.run_id,
    y.sub,
    y.year,
    y.month,
    SUM(y.area_km2) * 100.0 AS irrigated_area_ha
FROM (
         SELECT
             h.run_id,
             h.sub,
             EXTRACT(YEAR FROM h.period_date)::int AS year,
             EXTRACT(MONTH FROM h.period_date)::int AS month,
             h.gis,
             h.lulc,
             MAX(COALESCE(h.area_km2, 0)) AS area_km2
         FROM swat_hru_kpi h
         WHERE h.period_res = 'MONTHLY'
           AND COALESCE(h.irr_mm, 0) > 0
           AND h.lulc IS NOT NULL
           AND h.lulc <> ''
         GROUP BY
             h.run_id,
             h.sub,
             EXTRACT(YEAR FROM h.period_date),
             EXTRACT(MONTH FROM h.period_date),
             h.gis,
             h.lulc
     ) y
GROUP BY y.run_id, y.sub, y.year, y.month
ON CONFLICT (run_id, sub, year, month)
DO UPDATE SET irrigated_area_ha = EXCLUDED.irrigated_area_ha;

COMMIT;