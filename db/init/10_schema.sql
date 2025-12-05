-- =====================================================================
--  WATDEV model database schema (PostgreSQL)
--  Initializes core tables for model runs and KPI outputs
--  Runs automatically when the DB is empty.
-- =====================================================================

-- We assume the database already exists as POSTGRES_DB=watdev.
-- No CREATE DATABASE or USE statements needed in PostgreSQL.

-- ---------------------------------------------------------------------
--  0. Enum types (idempotent)
-- ---------------------------------------------------------------------
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'time_step_enum') THEN
CREATE TYPE time_step_enum AS ENUM ('DAILY','MONTHLY','ANNUAL');
END IF;

IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'period_res_enum') THEN
        CREATE TYPE period_res_enum AS ENUM ('DAILY','MONTHLY');
END IF;

IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'visibility_enum') THEN
        CREATE TYPE visibility_enum AS ENUM ('private','public');
END IF;
END$$;

-- ---------------------------------------------------------------------
--  1. Model runs metadata  (final state after all migrations)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS swat_runs (
                                         id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                                         study_area   VARCHAR(32)  NOT NULL,
                                         run_label    VARCHAR(160) NOT NULL,
                                         run_date     DATE         NULL,
                                         visibility   visibility_enum NOT NULL DEFAULT 'private',
                                         description  TEXT         NULL,
                                         created_by   BIGINT       NULL,
                                         period_start DATE         NULL,
                                         period_end   DATE         NULL,
                                         time_step    time_step_enum NOT NULL DEFAULT 'MONTHLY',
                                         created_at   TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Unique run label within a study area
CREATE UNIQUE INDEX IF NOT EXISTS uniq_studyarea_runlabel
    ON swat_runs (study_area, run_label);

-- ---------------------------------------------------------------------
--  2. HRU-level KPIs (final primary key from migrations)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS swat_hru_kpi (
                                            run_id        BIGINT       NOT NULL,
                                            hru           INTEGER      NOT NULL,
                                            sub           INTEGER      NOT NULL,
                                            gis           INTEGER      NULL,
                                            lulc          VARCHAR(16)  NULL,
                                            period_date   DATE         NOT NULL,
                                            period_res    period_res_enum NOT NULL DEFAULT 'MONTHLY',

                                            area_km2      DOUBLE PRECISION NULL,
                                            irr_mm        DOUBLE PRECISION NULL,
                                            irr_sa_mm     DOUBLE PRECISION NULL,
                                            irr_da_mm     DOUBLE PRECISION NULL,
                                            yld_t_ha      DOUBLE PRECISION NULL,
                                            biom_t_ha     DOUBLE PRECISION NULL,
                                            syld_t_ha     DOUBLE PRECISION NULL,
                                            nup_kg_ha     DOUBLE PRECISION NULL,
                                            pup_kg_ha     DOUBLE PRECISION NULL,
                                            no3l_kg_ha    DOUBLE PRECISION NULL,

                                            n_app_kg_ha   DOUBLE PRECISION NULL,
                                            p_app_kg_ha   DOUBLE PRECISION NULL,
                                            nauto_kg_ha   DOUBLE PRECISION NULL,
                                            pauto_kg_ha   DOUBLE PRECISION NULL,
                                            ngraz_kg_ha   DOUBLE PRECISION NULL,
                                            pgraz_kg_ha   DOUBLE PRECISION NULL,
                                            cfertn_kg_ha  DOUBLE PRECISION NULL,
                                            cfertp_kg_ha  DOUBLE PRECISION NULL,

                                            PRIMARY KEY (run_id, hru, sub, gis, period_date),
                                            CONSTRAINT fk_hru_run FOREIGN KEY (run_id)
                                                REFERENCES swat_runs(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_hru_sub
    ON swat_hru_kpi (run_id, sub, period_date);

-- ---------------------------------------------------------------------
--  3. Reach-level KPIs (final structure after migrations)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS swat_rch_kpi (
                                            run_id        BIGINT       NOT NULL,
                                            rch           INTEGER      NOT NULL,
                                            sub           INTEGER      NULL,
                                            area_km2      DOUBLE PRECISION NULL,
                                            period_date   DATE         NOT NULL,
                                            period_res    period_res_enum NOT NULL DEFAULT 'MONTHLY',

                                            flow_out_cms  DOUBLE PRECISION NULL,
                                            no3_out_kg    DOUBLE PRECISION NULL,
                                            sed_out_t     DOUBLE PRECISION NULL,

                                            PRIMARY KEY (run_id, rch, period_date),
                                            CONSTRAINT fk_rch_run FOREIGN KEY (run_id)
                                                REFERENCES swat_runs(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_rch
    ON swat_rch_kpi (run_id, period_date);

-- ---------------------------------------------------------------------
--  4. Soil-nutrient KPIs (SNU)  (final PK from migrations)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS swat_snu_kpi (
                                            run_id        BIGINT       NOT NULL,
                                            gisnum        INTEGER      NOT NULL,
                                            period_date   DATE         NOT NULL,
                                            period_res    period_res_enum NOT NULL DEFAULT 'MONTHLY',

                                            sol_p         DOUBLE PRECISION NULL,
                                            no3           DOUBLE PRECISION NULL,
                                            org_n         DOUBLE PRECISION NULL,
                                            org_p         DOUBLE PRECISION NULL,
                                            cn            DOUBLE PRECISION NULL,
                                            sol_rsd       DOUBLE PRECISION NULL,

                                            PRIMARY KEY (run_id, gisnum, period_date),
                                            CONSTRAINT fk_snu_run FOREIGN KEY (run_id)
                                                REFERENCES swat_runs(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_snu
    ON swat_snu_kpi (run_id, period_date);

-- ---------------------------------------------------------------------
--  5. Migration tracking table (for potential future use)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS migrations (
                                          id         BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                                          filename   VARCHAR(255) NOT NULL,
                                          applied_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                                          CONSTRAINT uq_filename UNIQUE (filename)
);

-- ---------------------------------------------------------------------
-- Done
-- ---------------------------------------------------------------------