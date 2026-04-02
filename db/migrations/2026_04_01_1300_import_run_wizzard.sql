-- Run metadata extensions + subbasin assignment + license lookup

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

ALTER TABLE public.swat_runs
    ADD COLUMN IF NOT EXISTS model_run_author text,
    ADD COLUMN IF NOT EXISTS publication_url text,
    ADD COLUMN IF NOT EXISTS license_id integer,
    ADD COLUMN IF NOT EXISTS is_downloadable boolean NOT NULL DEFAULT false,
    ADD COLUMN IF NOT EXISTS downloadable_from_date date;

ALTER TABLE public.swat_runs
    DROP CONSTRAINT IF EXISTS swat_runs_license_id_fkey;

ALTER TABLE public.swat_runs
    ADD CONSTRAINT swat_runs_license_id_fkey
        FOREIGN KEY (license_id)
            REFERENCES public.run_licenses (id)
            ON UPDATE NO ACTION
            ON DELETE SET NULL;

CREATE UNIQUE INDEX IF NOT EXISTS ux_swat_runs_id_study_area
    ON public.swat_runs (id, study_area);

CREATE TABLE IF NOT EXISTS public.swat_run_subbasins
(
    run_id bigint NOT NULL,
    study_area_id integer NOT NULL,
    sub integer NOT NULL,
    CONSTRAINT swat_run_subbasins_pkey PRIMARY KEY (run_id, sub)
);

ALTER TABLE public.swat_run_subbasins
    DROP CONSTRAINT IF EXISTS swat_run_subbasins_run_area_fkey;

ALTER TABLE public.swat_run_subbasins
    ADD CONSTRAINT swat_run_subbasins_run_area_fkey
        FOREIGN KEY (run_id, study_area_id)
            REFERENCES public.swat_runs (id, study_area)
            ON UPDATE NO ACTION
            ON DELETE CASCADE;

ALTER TABLE public.swat_run_subbasins
    DROP CONSTRAINT IF EXISTS swat_run_subbasins_subbasin_fkey;

ALTER TABLE public.swat_run_subbasins
    ADD CONSTRAINT swat_run_subbasins_subbasin_fkey
        FOREIGN KEY (study_area_id, sub)
            REFERENCES public.study_area_subbasins (study_area_id, sub)
            ON UPDATE NO ACTION
            ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS idx_swat_run_subbasins_study_area_sub
    ON public.swat_run_subbasins (study_area_id, sub);