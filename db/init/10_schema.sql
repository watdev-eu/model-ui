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

CREATE TABLE IF NOT EXISTS public.crops
(
    code character varying(8) COLLATE pg_catalog."default" NOT NULL,
    name text COLLATE pg_catalog."default" NOT NULL,
    CONSTRAINT crops_pkey PRIMARY KEY (code)
);

CREATE TABLE IF NOT EXISTS public.mca_indicators
(
    id bigserial NOT NULL,
    code character varying(32) COLLATE pg_catalog."default" NOT NULL,
    name text COLLATE pg_catalog."default" NOT NULL,
    unit text COLLATE pg_catalog."default",
    default_direction text COLLATE pg_catalog."default" NOT NULL,
    description text COLLATE pg_catalog."default",
    calc_key character varying(64) COLLATE pg_catalog."default" NOT NULL,
    created_at timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT mca_indicators_pkey PRIMARY KEY (id),
    CONSTRAINT mca_indicators_code_key UNIQUE (code)
);

CREATE TABLE IF NOT EXISTS public.mca_preset_items
(
    preset_set_id bigint NOT NULL,
    indicator_id bigint NOT NULL,
    weight double precision NOT NULL,
    direction text COLLATE pg_catalog."default",
    is_enabled boolean NOT NULL DEFAULT true,
    CONSTRAINT mca_preset_items_pkey PRIMARY KEY (preset_set_id, indicator_id),
    CONSTRAINT mca_preset_items_unique UNIQUE (preset_set_id, indicator_id)
);

CREATE TABLE IF NOT EXISTS public.mca_preset_sets
(
    id bigserial NOT NULL,
    study_area_id integer NOT NULL,
    user_id bigint,
    name character varying(160) COLLATE pg_catalog."default" NOT NULL,
    is_default boolean NOT NULL DEFAULT false,
    created_at timestamp with time zone NOT NULL DEFAULT now(),
    updated_at timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT mca_preset_sets_pkey PRIMARY KEY (id),
    CONSTRAINT mca_preset_sets_study_area_id_user_id_name_key UNIQUE (study_area_id, user_id, name)
);

CREATE TABLE IF NOT EXISTS public.mca_scenarios
(
    id bigserial NOT NULL,
    preset_set_id bigint NOT NULL,
    scenario_key character varying(32) COLLATE pg_catalog."default" NOT NULL,
    label character varying(64) COLLATE pg_catalog."default" NOT NULL,
    run_id bigint,
    sort_order integer NOT NULL DEFAULT 0,
    CONSTRAINT mca_scenarios_pkey PRIMARY KEY (id),
    CONSTRAINT mca_scenarios_preset_set_id_scenario_key_key UNIQUE (preset_set_id, scenario_key)
);

CREATE TABLE IF NOT EXISTS public.mca_variable_sets
(
    id bigserial NOT NULL,
    study_area_id integer NOT NULL,
    user_id bigint,
    name character varying(160) COLLATE pg_catalog."default" NOT NULL,
    is_default boolean NOT NULL DEFAULT false,
    created_at timestamp with time zone NOT NULL DEFAULT now(),
    updated_at timestamp with time zone NOT NULL DEFAULT now(),
    preset_set_id bigint NOT NULL,
    CONSTRAINT mca_variable_sets_pkey PRIMARY KEY (id),
    CONSTRAINT mca_variable_sets_study_area_id_user_id_name_key UNIQUE (study_area_id, user_id, name)
);

CREATE TABLE IF NOT EXISTS public.mca_variable_values
(
    variable_set_id bigint NOT NULL,
    variable_id bigint NOT NULL,
    value_num double precision,
    value_text text COLLATE pg_catalog."default",
    value_bool boolean,
    CONSTRAINT mca_variable_values_pkey PRIMARY KEY (variable_set_id, variable_id)
);

CREATE TABLE IF NOT EXISTS public.mca_variable_values_crop
(
    variable_set_id bigint NOT NULL,
    variable_id bigint NOT NULL,
    crop_code character varying(8) COLLATE pg_catalog."default" NOT NULL,
    value_num double precision,
    value_text text COLLATE pg_catalog."default",
    value_bool boolean,
    CONSTRAINT mca_variable_values_crop_pkey PRIMARY KEY (variable_set_id, variable_id, crop_code)
);

CREATE TABLE IF NOT EXISTS public.mca_variable_values_crop_run
(
    variable_set_id bigint NOT NULL,
    run_id bigint NOT NULL,
    crop_code character varying(8) COLLATE pg_catalog."default" NOT NULL,
    variable_id bigint NOT NULL,
    value_num double precision,
    value_text text COLLATE pg_catalog."default",
    value_bool boolean,
    CONSTRAINT mca_variable_values_crop_run_pkey PRIMARY KEY (variable_set_id, run_id, crop_code, variable_id)
);

CREATE TABLE IF NOT EXISTS public.mca_variable_values_run
(
    variable_set_id bigint NOT NULL,
    run_id bigint NOT NULL,
    variable_id bigint NOT NULL,
    value_num double precision,
    value_text text COLLATE pg_catalog."default",
    value_bool boolean,
    CONSTRAINT mca_variable_values_run_pkey PRIMARY KEY (variable_set_id, run_id, variable_id)
);

CREATE TABLE IF NOT EXISTS public.mca_variables
(
    id bigserial NOT NULL,
    key character varying(64) COLLATE pg_catalog."default" NOT NULL,
    name text COLLATE pg_catalog."default" NOT NULL,
    unit text COLLATE pg_catalog."default",
    description text COLLATE pg_catalog."default",
    data_type text COLLATE pg_catalog."default" NOT NULL DEFAULT 'number'::text,
    created_at timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT mca_variables_pkey PRIMARY KEY (id),
    CONSTRAINT mca_variables_key_key UNIQUE (key)
);

CREATE TABLE IF NOT EXISTS public.migrations
(
    id bigint NOT NULL GENERATED ALWAYS AS IDENTITY ( INCREMENT 1 START 1 MINVALUE 1 MAXVALUE 9223372036854775807 CACHE 1 ),
    filename character varying(255) COLLATE pg_catalog."default" NOT NULL,
    applied_at timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT migrations_pkey PRIMARY KEY (id),
    CONSTRAINT uq_filename UNIQUE (filename)
);

CREATE TABLE IF NOT EXISTS public.study_area_reaches
(
    id bigserial NOT NULL,
    study_area_id integer NOT NULL,
    rch integer NOT NULL,
    sub integer,
    geom geometry NOT NULL,
    properties jsonb DEFAULT '{}'::jsonb,
    CONSTRAINT study_area_reaches_pkey PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS public.study_area_subbasins
(
    id bigserial NOT NULL,
    study_area_id integer NOT NULL,
    sub integer NOT NULL,
    geom geometry NOT NULL,
    properties jsonb DEFAULT '{}'::jsonb,
    CONSTRAINT study_area_subbasins_pkey PRIMARY KEY (id),
    CONSTRAINT uq_sub_per_area UNIQUE (study_area_id, sub)
);

CREATE TABLE IF NOT EXISTS public.study_areas
(
    id serial NOT NULL,
    name text COLLATE pg_catalog."default" NOT NULL,
    geom geometry,
    enabled boolean NOT NULL DEFAULT true,
    has_rch_results boolean NOT NULL DEFAULT true,
    CONSTRAINT study_areas_pkey PRIMARY KEY (id),
    CONSTRAINT study_areas_name_key UNIQUE (name)
);

CREATE TABLE IF NOT EXISTS public.swat_hru_kpi
(
    run_id bigint NOT NULL,
    hru integer NOT NULL,
    sub integer NOT NULL,
    gis integer NOT NULL,
    lulc character varying(16) COLLATE pg_catalog."default",
    period_date date NOT NULL,
    period_res period_res_enum NOT NULL DEFAULT 'MONTHLY'::period_res_enum,
    area_km2 double precision,
    irr_mm double precision,
    irr_sa_mm double precision,
    irr_da_mm double precision,
    yld_t_ha double precision,
    biom_t_ha double precision,
    syld_t_ha double precision,
    nup_kg_ha double precision,
    pup_kg_ha double precision,
    no3l_kg_ha double precision,
    n_app_kg_ha double precision,
    p_app_kg_ha double precision,
    nauto_kg_ha double precision,
    pauto_kg_ha double precision,
    ngraz_kg_ha double precision,
    pgraz_kg_ha double precision,
    cfertn_kg_ha double precision,
    cfertp_kg_ha double precision,
    CONSTRAINT swat_hru_kpi_pkey PRIMARY KEY (run_id, hru, sub, gis, period_date)
);

CREATE TABLE IF NOT EXISTS public.swat_rch_kpi
(
    run_id bigint NOT NULL,
    rch integer NOT NULL,
    sub integer,
    area_km2 double precision,
    period_date date NOT NULL,
    period_res period_res_enum NOT NULL DEFAULT 'MONTHLY'::period_res_enum,
    flow_out_cms double precision,
    no3_out_kg double precision,
    sed_out_t double precision,
    CONSTRAINT swat_rch_kpi_pkey PRIMARY KEY (run_id, rch, period_date)
);

CREATE TABLE IF NOT EXISTS public.swat_runs
(
    id bigint NOT NULL GENERATED ALWAYS AS IDENTITY ( INCREMENT 1 START 1 MINVALUE 1 MAXVALUE 9223372036854775807 CACHE 1 ),
    run_label character varying(160) COLLATE pg_catalog."default" NOT NULL,
    run_date date,
    visibility visibility_enum NOT NULL DEFAULT 'private'::visibility_enum,
    description text COLLATE pg_catalog."default",
    created_by bigint,
    period_start date,
    period_end date,
    time_step time_step_enum NOT NULL DEFAULT 'MONTHLY'::time_step_enum,
    created_at timestamp with time zone NOT NULL DEFAULT now(),
    is_default boolean NOT NULL DEFAULT false,
    study_area integer NOT NULL,
    is_baseline boolean NOT NULL DEFAULT false,
    CONSTRAINT swat_runs_pkey PRIMARY KEY (id),
    CONSTRAINT swat_runs_area_runlabel_uniq UNIQUE (study_area, run_label)
);

CREATE TABLE IF NOT EXISTS public.swat_snu_kpi
(
    run_id bigint NOT NULL,
    gisnum integer NOT NULL,
    period_date date NOT NULL,
    period_res period_res_enum NOT NULL DEFAULT 'MONTHLY'::period_res_enum,
    sol_p double precision,
    no3 double precision,
    org_n double precision,
    org_p double precision,
    cn double precision,
    sol_rsd double precision,
    CONSTRAINT swat_snu_kpi_pkey PRIMARY KEY (run_id, gisnum, period_date)
);

ALTER TABLE IF EXISTS public.mca_preset_items
    ADD CONSTRAINT mca_preset_items_indicator_id_fkey FOREIGN KEY (indicator_id)
        REFERENCES public.mca_indicators (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE RESTRICT;


ALTER TABLE IF EXISTS public.mca_preset_items
    ADD CONSTRAINT mca_preset_items_preset_set_id_fkey FOREIGN KEY (preset_set_id)
        REFERENCES public.mca_preset_sets (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;


ALTER TABLE IF EXISTS public.mca_preset_sets
    ADD CONSTRAINT mca_preset_sets_study_area_id_fkey FOREIGN KEY (study_area_id)
        REFERENCES public.study_areas (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS ux_mca_preset_sets_one_default_per_area
    ON public.mca_preset_sets(study_area_id);


ALTER TABLE IF EXISTS public.mca_scenarios
    ADD CONSTRAINT mca_scenarios_preset_set_id_fkey FOREIGN KEY (preset_set_id)
        REFERENCES public.mca_preset_sets (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;


ALTER TABLE IF EXISTS public.mca_scenarios
    ADD CONSTRAINT mca_scenarios_run_id_fkey FOREIGN KEY (run_id)
        REFERENCES public.swat_runs (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE SET NULL;


ALTER TABLE IF EXISTS public.mca_variable_sets
    ADD CONSTRAINT mca_variable_sets_preset_fk FOREIGN KEY (preset_set_id)
        REFERENCES public.mca_preset_sets (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;


ALTER TABLE IF EXISTS public.mca_variable_sets
    ADD CONSTRAINT mca_variable_sets_study_area_id_fkey FOREIGN KEY (study_area_id)
        REFERENCES public.study_areas (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS ux_mca_variable_sets_one_default_per_area
    ON public.mca_variable_sets(study_area_id);


ALTER TABLE IF EXISTS public.mca_variable_values
    ADD CONSTRAINT mca_variable_values_variable_id_fkey FOREIGN KEY (variable_id)
        REFERENCES public.mca_variables (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE RESTRICT;


ALTER TABLE IF EXISTS public.mca_variable_values
    ADD CONSTRAINT mca_variable_values_variable_set_id_fkey FOREIGN KEY (variable_set_id)
        REFERENCES public.mca_variable_sets (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;


ALTER TABLE IF EXISTS public.mca_variable_values_crop
    ADD CONSTRAINT mca_variable_values_crop_crop_code_fkey FOREIGN KEY (crop_code)
        REFERENCES public.crops (code) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;


ALTER TABLE IF EXISTS public.mca_variable_values_crop
    ADD CONSTRAINT mca_variable_values_crop_variable_id_fkey FOREIGN KEY (variable_id)
        REFERENCES public.mca_variables (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE RESTRICT;


ALTER TABLE IF EXISTS public.mca_variable_values_crop
    ADD CONSTRAINT mca_variable_values_crop_variable_set_id_fkey FOREIGN KEY (variable_set_id)
        REFERENCES public.mca_variable_sets (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;


ALTER TABLE IF EXISTS public.mca_variable_values_crop_run
    ADD CONSTRAINT mca_variable_values_crop_run_crop_code_fkey FOREIGN KEY (crop_code)
        REFERENCES public.crops (code) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;


ALTER TABLE IF EXISTS public.mca_variable_values_crop_run
    ADD CONSTRAINT mca_variable_values_crop_run_run_id_fkey FOREIGN KEY (run_id)
        REFERENCES public.swat_runs (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_mca_var_values_crop_run_run
    ON public.mca_variable_values_crop_run(run_id);


ALTER TABLE IF EXISTS public.mca_variable_values_crop_run
    ADD CONSTRAINT mca_variable_values_crop_run_variable_id_fkey FOREIGN KEY (variable_id)
        REFERENCES public.mca_variables (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;


ALTER TABLE IF EXISTS public.mca_variable_values_crop_run
    ADD CONSTRAINT mca_variable_values_crop_run_variable_set_id_fkey FOREIGN KEY (variable_set_id)
        REFERENCES public.mca_variable_sets (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;


ALTER TABLE IF EXISTS public.mca_variable_values_run
    ADD CONSTRAINT mca_variable_values_run_run_id_fkey FOREIGN KEY (run_id)
        REFERENCES public.swat_runs (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;
CREATE INDEX IF NOT EXISTS idx_mca_var_values_run_run
    ON public.mca_variable_values_run(run_id);


ALTER TABLE IF EXISTS public.mca_variable_values_run
    ADD CONSTRAINT mca_variable_values_run_variable_id_fkey FOREIGN KEY (variable_id)
        REFERENCES public.mca_variables (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;


ALTER TABLE IF EXISTS public.mca_variable_values_run
    ADD CONSTRAINT mca_variable_values_run_variable_set_id_fkey FOREIGN KEY (variable_set_id)
        REFERENCES public.mca_variable_sets (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;


ALTER TABLE IF EXISTS public.study_area_reaches
    ADD CONSTRAINT study_area_reaches_study_area_id_fkey FOREIGN KEY (study_area_id)
        REFERENCES public.study_areas (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;


ALTER TABLE IF EXISTS public.study_area_subbasins
    ADD CONSTRAINT study_area_subbasins_study_area_id_fkey FOREIGN KEY (study_area_id)
        REFERENCES public.study_areas (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;


ALTER TABLE IF EXISTS public.swat_hru_kpi
    ADD CONSTRAINT fk_hru_run FOREIGN KEY (run_id)
        REFERENCES public.swat_runs (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;


ALTER TABLE IF EXISTS public.swat_rch_kpi
    ADD CONSTRAINT fk_rch_run FOREIGN KEY (run_id)
        REFERENCES public.swat_runs (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;


ALTER TABLE IF EXISTS public.swat_runs
    ADD CONSTRAINT swat_runs_study_area_fk FOREIGN KEY (study_area)
        REFERENCES public.study_areas (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE RESTRICT;
CREATE INDEX IF NOT EXISTS ux_swat_runs_baseline_per_area
    ON public.swat_runs(study_area);


ALTER TABLE IF EXISTS public.swat_snu_kpi
    ADD CONSTRAINT fk_snu_run FOREIGN KEY (run_id)
        REFERENCES public.swat_runs (id) MATCH SIMPLE
        ON UPDATE NO ACTION
        ON DELETE CASCADE;

-- ---------------------------------------------------------------------
-- Done
-- ---------------------------------------------------------------------