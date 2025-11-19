-- =====================================================================
--  WATDEV model database schema
--  Initializes core tables for model runs and KPI outputs
--  This file runs automatically when the DB is empty.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS watdev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE watdev;

-- ---------------------------------------------------------------------
--  1. Model runs metadata
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS swat_runs (
                                         id               BIGINT AUTO_INCREMENT PRIMARY KEY,
                                         project          VARCHAR(120) NOT NULL,
                                         scenario_name    VARCHAR(160) NOT NULL,
                                         swat_version     VARCHAR(32)  DEFAULT NULL,
                                         period_start     DATE         DEFAULT NULL,
                                         period_end       DATE         DEFAULT NULL,
                                         time_step        ENUM('DAILY','MONTHLY','ANNUAL') NOT NULL DEFAULT 'MONTHLY',
                                         print_opts       TEXT         DEFAULT NULL,
                                         run_path         TEXT         DEFAULT NULL,
                                         param_set_hash   CHAR(40)     DEFAULT NULL,
                                         created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                         UNIQUE KEY uq_project_scenario (project, scenario_name, swat_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  2. HRU-level KPIs
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS swat_hru_kpi (
                                            run_id           BIGINT       NOT NULL,
                                            hru              INT          NOT NULL,
                                            sub              INT          NOT NULL,
                                            gis              INT          DEFAULT NULL,
                                            lulc             VARCHAR(16)  DEFAULT NULL,
                                            period_date      DATE         NOT NULL,
                                            period_res       ENUM('DAILY','MONTHLY') NOT NULL DEFAULT 'MONTHLY',

                                            area_km2         DOUBLE       DEFAULT NULL,
                                            irr_mm           DOUBLE       DEFAULT NULL,
                                            irr_sa_mm        DOUBLE       DEFAULT NULL,
                                            irr_da_mm        DOUBLE       DEFAULT NULL,
                                            yld_t_ha         DOUBLE       DEFAULT NULL,
                                            biom_t_ha        DOUBLE       DEFAULT NULL,
                                            syld_t_ha        DOUBLE       DEFAULT NULL,
                                            nup_kg_ha        DOUBLE       DEFAULT NULL,
                                            pup_kg_ha        DOUBLE       DEFAULT NULL,
                                            no3l_kg_ha       DOUBLE       DEFAULT NULL,

                                            n_app_kg_ha      DOUBLE       DEFAULT NULL,
                                            p_app_kg_ha      DOUBLE       DEFAULT NULL,
                                            nauto_kg_ha      DOUBLE       DEFAULT NULL,
                                            pauto_kg_ha      DOUBLE       DEFAULT NULL,
                                            ngraz_kg_ha      DOUBLE       DEFAULT NULL,
                                            pgraz_kg_ha      DOUBLE       DEFAULT NULL,
                                            cfertn_kg_ha     DOUBLE       DEFAULT NULL,
                                            cfertp_kg_ha     DOUBLE       DEFAULT NULL,

                                            PRIMARY KEY (run_id, hru, period_date),
                                            KEY idx_hru_sub (run_id, sub, period_date),
                                            CONSTRAINT fk_hru_run FOREIGN KEY (run_id)
                                                REFERENCES swat_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  3. Reach-level KPIs (streamflow, nitrate loads, etc.)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS swat_rch_kpi (
                                            run_id           BIGINT       NOT NULL,
                                            rch              INT          NOT NULL,
                                            gis              INT          DEFAULT NULL,
                                            area_km2         DOUBLE       DEFAULT NULL,
                                            period_date      DATE         NOT NULL,
                                            period_res       ENUM('DAILY','MONTHLY') NOT NULL DEFAULT 'MONTHLY',

                                            flow_out_cms     DOUBLE       DEFAULT NULL,
                                            no3_out_kg       DOUBLE       DEFAULT NULL,
                                            sed_out_t        DOUBLE       DEFAULT NULL,

                                            PRIMARY KEY (run_id, rch, period_date),
                                            KEY idx_rch (run_id, period_date),
                                            CONSTRAINT fk_rch_run FOREIGN KEY (run_id)
                                                REFERENCES swat_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  4. Soil-nutrient KPIs (SNU)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS swat_snu_kpi (
                                            run_id           BIGINT       NOT NULL,
                                            gisnum           INT          NOT NULL,
                                            period_date      DATE         NOT NULL,
                                            period_res       ENUM('DAILY','MONTHLY') NOT NULL DEFAULT 'MONTHLY',

                                            sol_p            DOUBLE       DEFAULT NULL,
                                            no3              DOUBLE       DEFAULT NULL,
                                            org_n            DOUBLE       DEFAULT NULL,
                                            org_p            DOUBLE       DEFAULT NULL,
                                            cn               DOUBLE       DEFAULT NULL,
                                            sol_rsd          DOUBLE       DEFAULT NULL,

                                            PRIMARY KEY (run_id, gisnum, period_date),
                                            KEY idx_snu (run_id, period_date),
                                            CONSTRAINT fk_snu_run FOREIGN KEY (run_id)
                                                REFERENCES swat_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
--  5. Migration tracking (optional future use)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS migrations (
                                          id        INT AUTO_INCREMENT PRIMARY KEY,
                                          filename  VARCHAR(255) NOT NULL,
                                          applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                          UNIQUE KEY uq_filename (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Done
-- ---------------------------------------------------------------------