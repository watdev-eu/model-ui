BEGIN;

ALTER TABLE mca_workspaces
    ADD COLUMN IF NOT EXISTS workspace_state_json jsonb NOT NULL DEFAULT '{}'::jsonb;

-- 1) Selected scenarios already exist.
-- Keep using:
-- mca_workspace_selected_datasets(workspace_id, dataset_id, sort_order)

-- 2) Indicators / weights / directions
CREATE TABLE IF NOT EXISTS mca_workspace_preset_items (
    workspace_id         bigint       NOT NULL REFERENCES mca_workspaces(id) ON DELETE CASCADE,
    indicator_calc_key   varchar(64)  NOT NULL,
    indicator_code       varchar(32),
    indicator_name       text,
    weight               double precision NOT NULL DEFAULT 0,
    direction            text NOT NULL CHECK (direction IN ('pos', 'neg')),
    is_enabled           boolean NOT NULL DEFAULT true,
    sort_order           integer NOT NULL DEFAULT 0,
    PRIMARY KEY (workspace_id, indicator_calc_key)
);

CREATE INDEX IF NOT EXISTS idx_mca_ws_preset_items_workspace
    ON mca_workspace_preset_items(workspace_id);

-- 3) Global variables accordion
CREATE TABLE IF NOT EXISTS mca_workspace_variables (
    workspace_id bigint      NOT NULL REFERENCES mca_workspaces(id) ON DELETE CASCADE,
    key          varchar(64) NOT NULL,
    name         text,
    unit         text,
    description  text,
    data_type    text NOT NULL CHECK (data_type IN ('number', 'text', 'bool')),
    value_num    double precision,
    value_text   text,
    value_bool   boolean,
    sort_order   integer NOT NULL DEFAULT 0,
    PRIMARY KEY (workspace_id, key)
);

CREATE INDEX IF NOT EXISTS idx_mca_ws_variables_workspace
    ON mca_workspace_variables(workspace_id);

-- 4) Global crop variables accordion
CREATE TABLE IF NOT EXISTS mca_workspace_crop_variables (
    workspace_id bigint      NOT NULL REFERENCES mca_workspaces(id) ON DELETE CASCADE,
    crop_code    varchar(8)  NOT NULL REFERENCES crops(code) ON DELETE CASCADE,
    crop_name    text,
    key          varchar(64) NOT NULL,
    name         text,
    unit         text,
    description  text,
    data_type    text NOT NULL CHECK (data_type IN ('number', 'text', 'bool')),
    value_num    double precision,
    value_text   text,
    value_bool   boolean,
    sort_order   integer NOT NULL DEFAULT 0,
    PRIMARY KEY (workspace_id, crop_code, key)
);

CREATE INDEX IF NOT EXISTS idx_mca_ws_crop_variables_workspace
    ON mca_workspace_crop_variables(workspace_id);

CREATE INDEX IF NOT EXISTS idx_mca_ws_crop_variables_workspace_crop
    ON mca_workspace_crop_variables(workspace_id, crop_code);

-- 5) Baseline/reference crop factors accordion
CREATE TABLE IF NOT EXISTS mca_workspace_crop_ref_factors (
    workspace_id bigint      NOT NULL REFERENCES mca_workspaces(id) ON DELETE CASCADE,
    crop_code    varchar(8)  NOT NULL REFERENCES crops(code) ON DELETE CASCADE,
    crop_name    text,
    key          varchar(64) NOT NULL,
    name         text,
    unit         text,
    description  text,
    data_type    text NOT NULL CHECK (data_type IN ('number', 'text', 'bool')),
    value_num    double precision,
    value_text   text,
    value_bool   boolean,
    sort_order   integer NOT NULL DEFAULT 0,
    PRIMARY KEY (workspace_id, crop_code, key)
);

CREATE INDEX IF NOT EXISTS idx_mca_ws_crop_ref_factors_workspace
    ON mca_workspace_crop_ref_factors(workspace_id);

CREATE INDEX IF NOT EXISTS idx_mca_ws_crop_ref_factors_workspace_crop
    ON mca_workspace_crop_ref_factors(workspace_id, crop_code);

-- 6) Per-scenario variables accordion
CREATE TABLE IF NOT EXISTS mca_workspace_run_variables (
    workspace_id bigint      NOT NULL REFERENCES mca_workspaces(id) ON DELETE CASCADE,
    dataset_id   varchar(64) NOT NULL,
    key          varchar(64) NOT NULL,
    name         text,
    unit         text,
    description  text,
    data_type    text NOT NULL CHECK (data_type IN ('number', 'text', 'bool')),
    value_num    double precision,
    value_text   text,
    value_bool   boolean,
    sort_order   integer NOT NULL DEFAULT 0,
    PRIMARY KEY (workspace_id, dataset_id, key)
);

CREATE INDEX IF NOT EXISTS idx_mca_ws_run_variables_workspace
    ON mca_workspace_run_variables(workspace_id);

CREATE INDEX IF NOT EXISTS idx_mca_ws_run_variables_workspace_dataset
    ON mca_workspace_run_variables(workspace_id, dataset_id);

-- 7) Per-scenario crop factors accordion
CREATE TABLE IF NOT EXISTS mca_workspace_run_crop_factors (
  workspace_id bigint      NOT NULL REFERENCES mca_workspaces(id) ON DELETE CASCADE,
  dataset_id   varchar(64) NOT NULL,
  crop_code    varchar(8)  NOT NULL REFERENCES crops(code) ON DELETE CASCADE,
  crop_name    text,
  key          varchar(64) NOT NULL,
  name         text,
  unit         text,
  description  text,
  data_type    text NOT NULL CHECK (data_type IN ('number', 'text', 'bool')),
  value_num    double precision,
  value_text   text,
  value_bool   boolean,
  sort_order   integer NOT NULL DEFAULT 0,
  PRIMARY KEY (workspace_id, dataset_id, crop_code, key)
);

CREATE INDEX IF NOT EXISTS idx_mca_ws_run_crop_factors_workspace
    ON mca_workspace_run_crop_factors(workspace_id);

CREATE INDEX IF NOT EXISTS idx_mca_ws_run_crop_factors_workspace_dataset
    ON mca_workspace_run_crop_factors(workspace_id, dataset_id);

CREATE INDEX IF NOT EXISTS idx_mca_ws_run_crop_factors_workspace_dataset_crop
    ON mca_workspace_run_crop_factors(workspace_id, dataset_id, crop_code);

COMMIT;