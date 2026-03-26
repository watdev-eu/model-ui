BEGIN;

-- =========================================================
-- 1) Fix ownership columns: bigint -> text for Keycloak sub
-- =========================================================

ALTER TABLE mca_preset_sets
    ALTER COLUMN user_id TYPE text
    USING CASE
    WHEN user_id IS NULL THEN NULL
    ELSE user_id::text
    END;

ALTER TABLE mca_variable_sets
    ALTER COLUMN user_id TYPE text
    USING CASE
    WHEN user_id IS NULL THEN NULL
    ELSE user_id::text
    END;

-- =========================================================
-- 2) Drop old indexes/constraints that depend on preset_set_id
--    and on old user_id assumptions
-- =========================================================

DROP INDEX IF EXISTS ux_mca_varsets_default_per_preset_user;
DROP INDEX IF EXISTS ux_mca_varsets_name_per_preset_user;
DROP INDEX IF EXISTS mca_variable_sets_one_default_per_area;
DROP INDEX IF EXISTS mca_preset_sets_one_default_per_area;
DROP INDEX IF EXISTS ux_mca_presets_name_per_area_user;

ALTER TABLE mca_variable_sets
    DROP CONSTRAINT IF EXISTS mca_variable_sets_preset_fk;

-- =========================================================
-- 3) Decouple variable sets from preset sets
-- =========================================================

ALTER TABLE mca_variable_sets
    DROP COLUMN IF EXISTS preset_set_id;

-- =========================================================
-- 4) Recreate useful indexes for variable sets
-- =========================================================

CREATE UNIQUE INDEX mca_variable_sets_one_global_default_per_area
    ON mca_variable_sets (study_area_id)
    WHERE (user_id IS NULL AND is_default = TRUE);

CREATE UNIQUE INDEX ux_mca_varsets_one_default_per_user_area
    ON mca_variable_sets (study_area_id, user_id)
    WHERE (user_id IS NOT NULL AND is_default = TRUE);

CREATE UNIQUE INDEX ux_mca_varsets_name_per_area_user
    ON mca_variable_sets (study_area_id, user_id, name);

-- =========================================================
-- 5) Recreate useful indexes for preset sets using text user_id
-- =========================================================

CREATE UNIQUE INDEX mca_preset_sets_one_global_default_per_area
    ON mca_preset_sets (study_area_id)
    WHERE (user_id IS NULL AND is_default = TRUE);

CREATE UNIQUE INDEX ux_mca_presets_one_default_per_user_area
    ON mca_preset_sets (study_area_id, user_id)
    WHERE (user_id IS NOT NULL AND is_default = TRUE);

CREATE UNIQUE INDEX ux_mca_presets_name_per_area_user
    ON mca_preset_sets (study_area_id, user_id, name);

-- =========================================================
-- 6) Add workspaces
-- =========================================================

CREATE TABLE mca_workspaces
(
    id              bigserial PRIMARY KEY,
    study_area_id   integer NOT NULL
        REFERENCES study_areas(id)
            ON DELETE CASCADE,

    user_id         text NOT NULL,

    name            varchar(160) NOT NULL,
    description     text,
    is_default      boolean NOT NULL DEFAULT false,

    preset_set_id   bigint NOT NULL
        REFERENCES mca_preset_sets(id)
            ON DELETE RESTRICT,

    variable_set_id bigint NOT NULL
        REFERENCES mca_variable_sets(id)
            ON DELETE RESTRICT,

    created_at      timestamp with time zone NOT NULL DEFAULT now(),
    updated_at      timestamp with time zone NOT NULL DEFAULT now(),

    CONSTRAINT uq_mca_workspace_name_per_user_area
        UNIQUE (study_area_id, user_id, name)
);

CREATE UNIQUE INDEX ux_mca_workspace_default_per_user_area
    ON mca_workspaces (study_area_id, user_id)
    WHERE (is_default = TRUE);

CREATE TABLE mca_workspace_selected_datasets
(
    workspace_id bigint NOT NULL
        REFERENCES mca_workspaces(id)
            ON DELETE CASCADE,

    dataset_id   text NOT NULL,
    sort_order   integer NOT NULL DEFAULT 0,

    PRIMARY KEY (workspace_id, dataset_id)
);

CREATE INDEX idx_mca_workspace_selected_datasets_workspace_sort
    ON mca_workspace_selected_datasets (workspace_id, sort_order, dataset_id);

COMMIT;