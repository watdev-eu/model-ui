-- =====================================================================
--  WATDEV model database schema (PostgreSQL)
--  Initializes core tables for model runs, MCA config, scenarios,
--  metadata, and KPI outputs.
--  Intended for a BLANK database.
-- =====================================================================

-- We assume the database already exists as POSTGRES_DB=watdev.
-- No CREATE DATABASE or USE statements needed in PostgreSQL.

-- ---------------------------------------------------------------------
--  0. Extensions + enum types (idempotent)
-- ---------------------------------------------------------------------
CREATE EXTENSION IF NOT EXISTS postgis;

DO $$
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'time_step_enum') THEN
            CREATE TYPE time_step_enum AS ENUM ('DAILY','MONTHLY','ANNUAL','YEARLY');
        END IF;

        IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'period_res_enum') THEN
            CREATE TYPE period_res_enum AS ENUM ('DAILY','MONTHLY');
        END IF;

        IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'visibility_enum') THEN
            CREATE TYPE visibility_enum AS ENUM ('private','public');
        END IF;
    END
$$;

-- ---------------------------------------------------------------------
--  1. Core lookup / reference tables
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.crops
(
    code varchar(8) NOT NULL,
    name text NOT NULL,
    CONSTRAINT crops_pkey PRIMARY KEY (code)
);

CREATE TABLE IF NOT EXISTS public.run_licenses
(
    id serial NOT NULL,
    name varchar(80) NOT NULL,
    CONSTRAINT run_licenses_pkey PRIMARY KEY (id),
    CONSTRAINT run_licenses_name_key UNIQUE (name)
);

INSERT INTO public.run_licenses (name)
VALUES ('CC-BY')
ON CONFLICT (name) DO NOTHING;

INSERT INTO public.run_licenses (name)
VALUES ('CC-BY-NC-ND')
ON CONFLICT (name) DO NOTHING;

CREATE TABLE IF NOT EXISTS public.study_areas
(
    id serial NOT NULL,
    name text NOT NULL,
    geom geometry,
    enabled boolean NOT NULL DEFAULT true,
    has_rch_results boolean NOT NULL DEFAULT true,
    CONSTRAINT study_areas_pkey PRIMARY KEY (id),
    CONSTRAINT study_areas_name_key UNIQUE (name)
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

-- ---------------------------------------------------------------------
--  2. SWAT runs + KPI tables
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.swat_runs
(
    id bigint NOT NULL GENERATED ALWAYS AS IDENTITY,
    run_label varchar(160) NOT NULL,
    run_date date,
    visibility visibility_enum NOT NULL DEFAULT 'private',
    description text,

    -- Keycloak subject (sub) of the creator
    created_by text,

    period_start date,
    period_end date,
    time_step time_step_enum NOT NULL DEFAULT 'MONTHLY',
    created_at timestamp with time zone NOT NULL DEFAULT now(),
    is_default boolean NOT NULL DEFAULT false,
    study_area integer NOT NULL,
    is_baseline boolean NOT NULL DEFAULT false,

    -- Run metadata extensions
    model_run_author text,
    publication_url text,
    license_id integer,
    is_downloadable boolean NOT NULL DEFAULT false,
    downloadable_from_date date,

    CONSTRAINT swat_runs_pkey PRIMARY KEY (id),
    CONSTRAINT swat_runs_area_runlabel_uniq UNIQUE (study_area, run_label)
);

COMMENT ON COLUMN public.swat_runs.created_by IS 'Keycloak subject (sub) of the creator';

CREATE TABLE IF NOT EXISTS public.swat_hru_kpi
(
    run_id bigint NOT NULL,
    hru integer NOT NULL,
    sub integer NOT NULL,
    gis integer NOT NULL,
    lulc varchar(16),
    period_date date NOT NULL,
    period_res period_res_enum NOT NULL DEFAULT 'MONTHLY',
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
    period_res period_res_enum NOT NULL DEFAULT 'MONTHLY',
    flow_out_cms double precision,
    no3_out_kg double precision,
    sed_out_t double precision,
    CONSTRAINT swat_rch_kpi_pkey PRIMARY KEY (run_id, rch, period_date)
);

CREATE TABLE IF NOT EXISTS public.swat_snu_kpi
(
    run_id bigint NOT NULL,
    gisnum integer NOT NULL,
    period_date date NOT NULL,
    period_res period_res_enum NOT NULL DEFAULT 'MONTHLY',
    sol_p double precision,
    no3 double precision,
    org_n double precision,
    org_p double precision,
    cn double precision,
    sol_rsd double precision,
    CONSTRAINT swat_snu_kpi_pkey PRIMARY KEY (run_id, gisnum, period_date)
);

CREATE TABLE IF NOT EXISTS public.swat_indicator_yearly
(
    id bigserial PRIMARY KEY,
    run_id bigint NOT NULL,
    indicator_code varchar(64) NOT NULL,
    year integer NOT NULL,
    sub integer NOT NULL,
    crop varchar(16) NOT NULL DEFAULT '',
    value double precision,
    CONSTRAINT swat_indicator_yearly_uniq UNIQUE (run_id, indicator_code, year, sub, crop)
);

CREATE TABLE IF NOT EXISTS public.swat_run_subbasins
(
    run_id bigint NOT NULL,
    study_area_id integer NOT NULL,
    sub integer NOT NULL,
    CONSTRAINT swat_run_subbasins_pkey PRIMARY KEY (run_id, sub)
);

CREATE TABLE IF NOT EXISTS public.swat_crop_area_context
(
    run_id bigint NOT NULL,
    sub integer NOT NULL,
    crop varchar(16) NOT NULL,
    area_ha double precision NOT NULL,
    CONSTRAINT swat_crop_area_context_pkey PRIMARY KEY (run_id, sub, crop)
);

CREATE TABLE IF NOT EXISTS public.swat_irrigation_area_context
(
    run_id bigint NOT NULL,
    sub integer NOT NULL,
    year integer NOT NULL,
    month integer NOT NULL,
    irrigated_area_ha double precision NOT NULL,
    CONSTRAINT swat_irrigation_area_context_pkey PRIMARY KEY (run_id, sub, year, month)
);

-- ---------------------------------------------------------------------
--  3. MCA core tables
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.mca_indicators
(
    id bigserial NOT NULL,
    code varchar(32) NOT NULL,
    name text NOT NULL,
    unit text,
    default_direction text NOT NULL,
    description text,
    calc_key varchar(64) NOT NULL,
    created_at timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT mca_indicators_pkey PRIMARY KEY (id),
    CONSTRAINT mca_indicators_code_key UNIQUE (code)
);

CREATE TABLE IF NOT EXISTS public.mca_preset_sets
(
    id bigserial NOT NULL,
    study_area_id integer NOT NULL,

    -- Keycloak subject (sub) of the owner
    user_id text,

    name varchar(160) NOT NULL,
    is_default boolean NOT NULL DEFAULT false,
    created_at timestamp with time zone NOT NULL DEFAULT now(),
    updated_at timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT mca_preset_sets_pkey PRIMARY KEY (id)
);

COMMENT ON COLUMN public.mca_preset_sets.user_id IS 'Keycloak subject (sub) of the owner';

CREATE TABLE IF NOT EXISTS public.mca_preset_items
(
    preset_set_id bigint NOT NULL,
    indicator_id bigint NOT NULL,
    weight double precision NOT NULL,
    direction text,
    is_enabled boolean NOT NULL DEFAULT true,
    CONSTRAINT mca_preset_items_pkey PRIMARY KEY (preset_set_id, indicator_id)
);

CREATE TABLE IF NOT EXISTS public.mca_scenarios
(
    id bigserial NOT NULL,
    preset_set_id bigint NOT NULL,
    scenario_key varchar(32) NOT NULL,
    label varchar(64) NOT NULL,
    run_id bigint,
    sort_order integer NOT NULL DEFAULT 0,
    CONSTRAINT mca_scenarios_pkey PRIMARY KEY (id),
    CONSTRAINT mca_scenarios_preset_set_id_scenario_key_key UNIQUE (preset_set_id, scenario_key)
);

CREATE TABLE IF NOT EXISTS public.mca_variables
(
    id bigserial NOT NULL,
    key varchar(64) NOT NULL,
    name text NOT NULL,
    unit text,
    description text,
    data_type text NOT NULL DEFAULT 'number',
    created_at timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT mca_variables_pkey PRIMARY KEY (id),
    CONSTRAINT mca_variables_key_key UNIQUE (key)
);

CREATE TABLE IF NOT EXISTS public.mca_variable_sets
(
    id bigserial NOT NULL,
    study_area_id integer NOT NULL,

    -- Keycloak subject (sub) of the owner
    user_id text,

    name varchar(160) NOT NULL,
    is_default boolean NOT NULL DEFAULT false,
    created_at timestamp with time zone NOT NULL DEFAULT now(),
    updated_at timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT mca_variable_sets_pkey PRIMARY KEY (id)
);

COMMENT ON COLUMN public.mca_variable_sets.user_id IS 'Keycloak subject (sub) of the owner';

CREATE TABLE IF NOT EXISTS public.mca_variable_values
(
    variable_set_id bigint NOT NULL,
    variable_id bigint NOT NULL,
    value_num double precision,
    value_text text,
    value_bool boolean,
    CONSTRAINT mca_variable_values_pkey PRIMARY KEY (variable_set_id, variable_id)
);

CREATE TABLE IF NOT EXISTS public.mca_variable_values_crop
(
    variable_set_id bigint NOT NULL,
    variable_id bigint NOT NULL,
    crop_code varchar(8) NOT NULL,
    value_num double precision,
    value_text text,
    value_bool boolean,
    CONSTRAINT mca_variable_values_crop_pkey PRIMARY KEY (variable_set_id, variable_id, crop_code)
);

CREATE TABLE IF NOT EXISTS public.mca_variable_values_run
(
    variable_set_id bigint NOT NULL,
    run_id bigint NOT NULL,
    variable_id bigint NOT NULL,
    value_num double precision,
    value_text text,
    value_bool boolean,
    CONSTRAINT mca_variable_values_run_pkey PRIMARY KEY (variable_set_id, run_id, variable_id)
);

CREATE TABLE IF NOT EXISTS public.mca_variable_values_crop_run
(
    variable_set_id bigint NOT NULL,
    run_id bigint NOT NULL,
    crop_code varchar(8) NOT NULL,
    variable_id bigint NOT NULL,
    value_num double precision,
    value_text text,
    value_bool boolean,
    CONSTRAINT mca_variable_values_crop_run_pkey PRIMARY KEY (variable_set_id, run_id, crop_code, variable_id)
);

-- ---------------------------------------------------------------------
--  4. MCA workspaces
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.mca_workspaces
(
    id bigserial PRIMARY KEY,
    study_area_id integer NOT NULL
        REFERENCES public.study_areas(id)
            ON DELETE CASCADE,
    user_id text NOT NULL,
    name varchar(160) NOT NULL,
    description text,
    is_default boolean NOT NULL DEFAULT false,
    preset_set_id bigint NOT NULL
        REFERENCES public.mca_preset_sets(id)
            ON DELETE RESTRICT,
    variable_set_id bigint NOT NULL
        REFERENCES public.mca_variable_sets(id)
            ON DELETE RESTRICT,
    workspace_state_json jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamp with time zone NOT NULL DEFAULT now(),
    updated_at timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT uq_mca_workspace_name_per_user_area UNIQUE (study_area_id, user_id, name)
);

CREATE TABLE IF NOT EXISTS public.mca_workspace_selected_datasets
(
    workspace_id bigint NOT NULL
        REFERENCES public.mca_workspaces(id)
            ON DELETE CASCADE,
    dataset_id text NOT NULL,
    sort_order integer NOT NULL DEFAULT 0,
    PRIMARY KEY (workspace_id, dataset_id)
);

CREATE TABLE IF NOT EXISTS public.mca_workspace_preset_items
(
    workspace_id bigint NOT NULL
        REFERENCES public.mca_workspaces(id)
            ON DELETE CASCADE,
    indicator_calc_key varchar(64) NOT NULL,
    indicator_code varchar(32),
    indicator_name text,
    weight double precision NOT NULL DEFAULT 0,
    direction text NOT NULL CHECK (direction IN ('pos', 'neg')),
    is_enabled boolean NOT NULL DEFAULT true,
    sort_order integer NOT NULL DEFAULT 0,
    PRIMARY KEY (workspace_id, indicator_calc_key)
);

CREATE TABLE IF NOT EXISTS public.mca_workspace_variables
(
    workspace_id bigint NOT NULL
        REFERENCES public.mca_workspaces(id)
            ON DELETE CASCADE,
    key varchar(64) NOT NULL,
    name text,
    unit text,
    description text,
    data_type text NOT NULL CHECK (data_type IN ('number', 'text', 'bool')),
    value_num double precision,
    value_text text,
    value_bool boolean,
    sort_order integer NOT NULL DEFAULT 0,
    PRIMARY KEY (workspace_id, key)
);

CREATE TABLE IF NOT EXISTS public.mca_workspace_crop_variables
(
    workspace_id bigint NOT NULL
        REFERENCES public.mca_workspaces(id)
            ON DELETE CASCADE,
    crop_code varchar(8) NOT NULL
        REFERENCES public.crops(code)
            ON DELETE CASCADE,
    crop_name text,
    key varchar(64) NOT NULL,
    name text,
    unit text,
    description text,
    data_type text NOT NULL CHECK (data_type IN ('number', 'text', 'bool')),
    value_num double precision,
    value_text text,
    value_bool boolean,
    sort_order integer NOT NULL DEFAULT 0,
    PRIMARY KEY (workspace_id, crop_code, key)
);

CREATE TABLE IF NOT EXISTS public.mca_workspace_crop_ref_factors
(
    workspace_id bigint NOT NULL
        REFERENCES public.mca_workspaces(id)
            ON DELETE CASCADE,
    crop_code varchar(8) NOT NULL
        REFERENCES public.crops(code)
            ON DELETE CASCADE,
    crop_name text,
    key varchar(64) NOT NULL,
    name text,
    unit text,
    description text,
    data_type text NOT NULL CHECK (data_type IN ('number', 'text', 'bool')),
    value_num double precision,
    value_text text,
    value_bool boolean,
    sort_order integer NOT NULL DEFAULT 0,
    PRIMARY KEY (workspace_id, crop_code, key)
);

CREATE TABLE IF NOT EXISTS public.mca_workspace_run_variables
(
    workspace_id bigint NOT NULL
        REFERENCES public.mca_workspaces(id)
            ON DELETE CASCADE,
    dataset_id varchar(64) NOT NULL,
    key varchar(64) NOT NULL,
    name text,
    unit text,
    description text,
    data_type text NOT NULL CHECK (data_type IN ('number', 'text', 'bool')),
    value_num double precision,
    value_text text,
    value_bool boolean,
    sort_order integer NOT NULL DEFAULT 0,
    PRIMARY KEY (workspace_id, dataset_id, key)
);

CREATE TABLE IF NOT EXISTS public.mca_workspace_run_crop_factors
(
    workspace_id bigint NOT NULL
        REFERENCES public.mca_workspaces(id)
            ON DELETE CASCADE,
    dataset_id varchar(64) NOT NULL,
    crop_code varchar(8) NOT NULL
        REFERENCES public.crops(code)
            ON DELETE CASCADE,
    crop_name text,
    key varchar(64) NOT NULL,
    name text,
    unit text,
    description text,
    data_type text NOT NULL CHECK (data_type IN ('number', 'text', 'bool')),
    value_num double precision,
    value_text text,
    value_bool boolean,
    sort_order integer NOT NULL DEFAULT 0,
    PRIMARY KEY (workspace_id, dataset_id, crop_code, key)
);

-- ---------------------------------------------------------------------
--  5. Custom scenarios
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.custom_scenarios
(
    id bigserial PRIMARY KEY,
    study_area_id integer NOT NULL
        REFERENCES public.study_areas(id)
            ON DELETE CASCADE,
    created_by text NOT NULL,
    name varchar(160) NOT NULL,
    description text,
    created_at timestamp with time zone NOT NULL DEFAULT now(),
    updated_at timestamp with time zone NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS public.custom_scenario_subbasin_runs
(
    custom_scenario_id bigint NOT NULL,
    study_area_id integer NOT NULL,
    sub integer NOT NULL,
    source_run_id bigint NOT NULL,
    created_at timestamp with time zone NOT NULL DEFAULT now(),
    updated_at timestamp with time zone NOT NULL DEFAULT now(),
    PRIMARY KEY (custom_scenario_id, sub)
);

-- ---------------------------------------------------------------------
--  6. Migration bookkeeping table
--     Keep only if your app still expects it.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.migrations
(
    id bigint NOT NULL GENERATED ALWAYS AS IDENTITY,
    filename varchar(255) NOT NULL,
    applied_at timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT migrations_pkey PRIMARY KEY (id),
    CONSTRAINT uq_filename UNIQUE (filename)
);

-- ---------------------------------------------------------------------
--  7. Foreign keys
-- ---------------------------------------------------------------------
ALTER TABLE IF EXISTS public.study_area_subbasins
    ADD CONSTRAINT study_area_subbasins_study_area_id_fkey
        FOREIGN KEY (study_area_id)
            REFERENCES public.study_areas (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.study_area_reaches
    ADD CONSTRAINT study_area_reaches_study_area_id_fkey
        FOREIGN KEY (study_area_id)
            REFERENCES public.study_areas (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.swat_runs
    ADD CONSTRAINT swat_runs_study_area_fk
        FOREIGN KEY (study_area)
            REFERENCES public.study_areas (id)
            ON DELETE RESTRICT;

ALTER TABLE IF EXISTS public.swat_runs
    ADD CONSTRAINT swat_runs_license_id_fkey
        FOREIGN KEY (license_id)
            REFERENCES public.run_licenses (id)
            ON DELETE SET NULL;

ALTER TABLE IF EXISTS public.swat_hru_kpi
    ADD CONSTRAINT fk_hru_run
        FOREIGN KEY (run_id)
            REFERENCES public.swat_runs (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.swat_rch_kpi
    ADD CONSTRAINT fk_rch_run
        FOREIGN KEY (run_id)
            REFERENCES public.swat_runs (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.swat_snu_kpi
    ADD CONSTRAINT fk_snu_run
        FOREIGN KEY (run_id)
            REFERENCES public.swat_runs (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.swat_indicator_yearly
    ADD CONSTRAINT swat_indicator_yearly_run_fk
        FOREIGN KEY (run_id)
            REFERENCES public.swat_runs (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.swat_run_subbasins
    ADD CONSTRAINT swat_run_subbasins_run_area_fkey
        FOREIGN KEY (run_id, study_area_id)
            REFERENCES public.swat_runs (id, study_area)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.swat_run_subbasins
    ADD CONSTRAINT swat_run_subbasins_subbasin_fkey
        FOREIGN KEY (study_area_id, sub)
            REFERENCES public.study_area_subbasins (study_area_id, sub)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.swat_crop_area_context
    ADD CONSTRAINT swat_crop_area_context_run_fk
        FOREIGN KEY (run_id)
            REFERENCES public.swat_runs (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.swat_irrigation_area_context
    ADD CONSTRAINT swat_irrigation_area_context_run_fk
        FOREIGN KEY (run_id)
            REFERENCES public.swat_runs (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.mca_preset_sets
    ADD CONSTRAINT mca_preset_sets_study_area_id_fkey
        FOREIGN KEY (study_area_id)
            REFERENCES public.study_areas (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.mca_preset_items
    ADD CONSTRAINT mca_preset_items_preset_set_id_fkey
        FOREIGN KEY (preset_set_id)
            REFERENCES public.mca_preset_sets (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.mca_preset_items
    ADD CONSTRAINT mca_preset_items_indicator_id_fkey
        FOREIGN KEY (indicator_id)
            REFERENCES public.mca_indicators (id)
            ON DELETE RESTRICT;

ALTER TABLE IF EXISTS public.mca_scenarios
    ADD CONSTRAINT mca_scenarios_preset_set_id_fkey
        FOREIGN KEY (preset_set_id)
            REFERENCES public.mca_preset_sets (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.mca_scenarios
    ADD CONSTRAINT mca_scenarios_run_id_fkey
        FOREIGN KEY (run_id)
            REFERENCES public.swat_runs (id)
            ON DELETE SET NULL;

ALTER TABLE IF EXISTS public.mca_variable_sets
    ADD CONSTRAINT mca_variable_sets_study_area_id_fkey
        FOREIGN KEY (study_area_id)
            REFERENCES public.study_areas (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.mca_variable_values
    ADD CONSTRAINT mca_variable_values_variable_set_id_fkey
        FOREIGN KEY (variable_set_id)
            REFERENCES public.mca_variable_sets (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.mca_variable_values
    ADD CONSTRAINT mca_variable_values_variable_id_fkey
        FOREIGN KEY (variable_id)
            REFERENCES public.mca_variables (id)
            ON DELETE RESTRICT;

ALTER TABLE IF EXISTS public.mca_variable_values_crop
    ADD CONSTRAINT mca_variable_values_crop_variable_set_id_fkey
        FOREIGN KEY (variable_set_id)
            REFERENCES public.mca_variable_sets (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.mca_variable_values_crop
    ADD CONSTRAINT mca_variable_values_crop_variable_id_fkey
        FOREIGN KEY (variable_id)
            REFERENCES public.mca_variables (id)
            ON DELETE RESTRICT;

ALTER TABLE IF EXISTS public.mca_variable_values_crop
    ADD CONSTRAINT mca_variable_values_crop_crop_code_fkey
        FOREIGN KEY (crop_code)
            REFERENCES public.crops (code)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.mca_variable_values_run
    ADD CONSTRAINT mca_variable_values_run_variable_set_id_fkey
        FOREIGN KEY (variable_set_id)
            REFERENCES public.mca_variable_sets (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.mca_variable_values_run
    ADD CONSTRAINT mca_variable_values_run_variable_id_fkey
        FOREIGN KEY (variable_id)
            REFERENCES public.mca_variables (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.mca_variable_values_run
    ADD CONSTRAINT mca_variable_values_run_run_id_fkey
        FOREIGN KEY (run_id)
            REFERENCES public.swat_runs (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.mca_variable_values_crop_run
    ADD CONSTRAINT mca_variable_values_crop_run_variable_set_id_fkey
        FOREIGN KEY (variable_set_id)
            REFERENCES public.mca_variable_sets (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.mca_variable_values_crop_run
    ADD CONSTRAINT mca_variable_values_crop_run_variable_id_fkey
        FOREIGN KEY (variable_id)
            REFERENCES public.mca_variables (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.mca_variable_values_crop_run
    ADD CONSTRAINT mca_variable_values_crop_run_run_id_fkey
        FOREIGN KEY (run_id)
            REFERENCES public.swat_runs (id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.mca_variable_values_crop_run
    ADD CONSTRAINT mca_variable_values_crop_run_crop_code_fkey
        FOREIGN KEY (crop_code)
            REFERENCES public.crops (code)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.custom_scenario_subbasin_runs
    ADD CONSTRAINT fk_custom_assignment_scenario
        FOREIGN KEY (custom_scenario_id, study_area_id)
            REFERENCES public.custom_scenarios(id, study_area_id)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.custom_scenario_subbasin_runs
    ADD CONSTRAINT fk_custom_scenario_subbasin
        FOREIGN KEY (study_area_id, sub)
            REFERENCES public.study_area_subbasins(study_area_id, sub)
            ON DELETE CASCADE;

ALTER TABLE IF EXISTS public.custom_scenario_subbasin_runs
    ADD CONSTRAINT fk_custom_assignment_run_same_area
        FOREIGN KEY (source_run_id, study_area_id)
            REFERENCES public.swat_runs(id, study_area)
            ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
--  8. Indexes
-- ---------------------------------------------------------------------

-- SWAT / area / lookup indexes
CREATE UNIQUE INDEX IF NOT EXISTS ux_swat_runs_id_study_area
    ON public.swat_runs (id, study_area);

CREATE INDEX IF NOT EXISTS ux_swat_runs_baseline_per_area
    ON public.swat_runs (study_area);

CREATE INDEX IF NOT EXISTS idx_swat_run_subbasins_study_area_sub
    ON public.swat_run_subbasins (study_area_id, sub);

CREATE INDEX IF NOT EXISTS idx_swat_indicator_yearly_run_code
    ON public.swat_indicator_yearly (run_id, indicator_code);

CREATE INDEX IF NOT EXISTS idx_swat_indicator_yearly_run_year
    ON public.swat_indicator_yearly (run_id, year);

CREATE INDEX IF NOT EXISTS idx_swat_crop_area_context_run
    ON public.swat_crop_area_context (run_id);

CREATE INDEX IF NOT EXISTS idx_swat_crop_area_context_run_sub
    ON public.swat_crop_area_context (run_id, sub);

CREATE INDEX IF NOT EXISTS idx_swat_irrigation_area_context_run
    ON public.swat_irrigation_area_context (run_id);

CREATE INDEX IF NOT EXISTS idx_swat_irrigation_area_context_run_year_month
    ON public.swat_irrigation_area_context (run_id, year, month);

-- MCA preset indexes
CREATE UNIQUE INDEX IF NOT EXISTS ux_mca_presets_name_per_area_user
    ON public.mca_preset_sets (study_area_id, user_id, name);

CREATE UNIQUE INDEX IF NOT EXISTS mca_preset_sets_one_global_default_per_area
    ON public.mca_preset_sets (study_area_id)
    WHERE (user_id IS NULL AND is_default = TRUE);

CREATE UNIQUE INDEX IF NOT EXISTS ux_mca_presets_one_default_per_user_area
    ON public.mca_preset_sets (study_area_id, user_id)
    WHERE (user_id IS NOT NULL AND is_default = TRUE);

-- MCA variable set indexes
CREATE UNIQUE INDEX IF NOT EXISTS ux_mca_varsets_name_per_area_user
    ON public.mca_variable_sets (study_area_id, user_id, name);

CREATE UNIQUE INDEX IF NOT EXISTS mca_variable_sets_one_global_default_per_area
    ON public.mca_variable_sets (study_area_id)
    WHERE (user_id IS NULL AND is_default = TRUE);

CREATE UNIQUE INDEX IF NOT EXISTS ux_mca_varsets_one_default_per_user_area
    ON public.mca_variable_sets (study_area_id, user_id)
    WHERE (user_id IS NOT NULL AND is_default = TRUE);

CREATE INDEX IF NOT EXISTS idx_mca_var_values_run_run
    ON public.mca_variable_values_run(run_id);

CREATE INDEX IF NOT EXISTS idx_mca_var_values_crop_run_run
    ON public.mca_variable_values_crop_run(run_id);

-- Workspace indexes
CREATE UNIQUE INDEX IF NOT EXISTS ux_mca_workspace_default_per_user_area
    ON public.mca_workspaces (study_area_id, user_id)
    WHERE (is_default = TRUE);

CREATE INDEX IF NOT EXISTS idx_mca_workspace_selected_datasets_workspace_sort
    ON public.mca_workspace_selected_datasets (workspace_id, sort_order, dataset_id);

CREATE INDEX IF NOT EXISTS idx_mca_ws_preset_items_workspace
    ON public.mca_workspace_preset_items(workspace_id);

CREATE INDEX IF NOT EXISTS idx_mca_ws_variables_workspace
    ON public.mca_workspace_variables(workspace_id);

CREATE INDEX IF NOT EXISTS idx_mca_ws_crop_variables_workspace
    ON public.mca_workspace_crop_variables(workspace_id);

CREATE INDEX IF NOT EXISTS idx_mca_ws_crop_variables_workspace_crop
    ON public.mca_workspace_crop_variables(workspace_id, crop_code);

CREATE INDEX IF NOT EXISTS idx_mca_ws_crop_ref_factors_workspace
    ON public.mca_workspace_crop_ref_factors(workspace_id);

CREATE INDEX IF NOT EXISTS idx_mca_ws_crop_ref_factors_workspace_crop
    ON public.mca_workspace_crop_ref_factors(workspace_id, crop_code);

CREATE INDEX IF NOT EXISTS idx_mca_ws_run_variables_workspace
    ON public.mca_workspace_run_variables(workspace_id);

CREATE INDEX IF NOT EXISTS idx_mca_ws_run_variables_workspace_dataset
    ON public.mca_workspace_run_variables(workspace_id, dataset_id);

CREATE INDEX IF NOT EXISTS idx_mca_ws_run_crop_factors_workspace
    ON public.mca_workspace_run_crop_factors(workspace_id);

CREATE INDEX IF NOT EXISTS idx_mca_ws_run_crop_factors_workspace_dataset
    ON public.mca_workspace_run_crop_factors(workspace_id, dataset_id);

CREATE INDEX IF NOT EXISTS idx_mca_ws_run_crop_factors_workspace_dataset_crop
    ON public.mca_workspace_run_crop_factors(workspace_id, dataset_id, crop_code);

-- Custom scenario indexes
CREATE UNIQUE INDEX IF NOT EXISTS ux_custom_scenarios_id_study_area
    ON public.custom_scenarios (id, study_area_id);

CREATE UNIQUE INDEX IF NOT EXISTS ux_custom_scenario_name_ci_per_user_area
    ON public.custom_scenarios (study_area_id, created_by, lower(name));

CREATE INDEX IF NOT EXISTS idx_custom_scenarios_area_user
    ON public.custom_scenarios (study_area_id, created_by);

CREATE INDEX IF NOT EXISTS idx_custom_assignments_run
    ON public.custom_scenario_subbasin_runs (source_run_id);

-- ---------------------------------------------------------------------
-- Done
-- ---------------------------------------------------------------------