begin;

-- Document the intent
comment on column swat_runs.created_by is 'Keycloak subject (sub) of the creator';
comment on column mca_preset_sets.user_id is 'Keycloak subject (sub) of the owner';
comment on column mca_variable_sets.user_id is 'Keycloak subject (sub) of the owner';

-- Convert bigint user reference columns to text-based Keycloak subject IDs
alter table swat_runs
    alter column created_by type text
    using created_by::text;

alter table mca_preset_sets
    alter column user_id type text
    using user_id::text;

alter table mca_variable_sets
    alter column user_id type text
    using user_id::text;

commit;