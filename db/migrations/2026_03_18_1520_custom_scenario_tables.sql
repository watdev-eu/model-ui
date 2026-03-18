begin;

create table custom_scenarios
(
    id            bigserial primary key,
    study_area_id integer not null
        references study_areas(id)
            on delete cascade,

    created_by    text not null,

    name          varchar(160) not null,
    description   text,

    created_at    timestamp with time zone default now() not null,
    updated_at    timestamp with time zone default now() not null
);

create unique index ux_custom_scenarios_id_study_area
    on custom_scenarios (id, study_area_id);

create unique index ux_custom_scenario_name_ci_per_user_area
    on custom_scenarios (study_area_id, created_by, lower(name));

create table custom_scenario_subbasin_runs
(
    custom_scenario_id bigint not null,
    study_area_id      integer not null,
    sub                integer not null,
    source_run_id      bigint not null,

    created_at         timestamp with time zone default now() not null,
    updated_at         timestamp with time zone default now() not null,

    primary key (custom_scenario_id, sub),

    constraint fk_custom_assignment_scenario
        foreign key (custom_scenario_id, study_area_id)
            references custom_scenarios(id, study_area_id)
            on delete cascade,

    constraint fk_custom_scenario_subbasin
        foreign key (study_area_id, sub)
            references study_area_subbasins(study_area_id, sub)
            on delete cascade
);

create index idx_custom_scenarios_area_user
    on custom_scenarios (study_area_id, created_by);

create index idx_custom_assignments_run
    on custom_scenario_subbasin_runs (source_run_id);

create unique index ux_swat_runs_id_study_area
    on swat_runs (id, study_area);

alter table custom_scenario_subbasin_runs
    add constraint fk_custom_assignment_run_same_area
        foreign key (source_run_id, study_area_id)
            references swat_runs (id, study_area)
            on delete restrict;

commit;