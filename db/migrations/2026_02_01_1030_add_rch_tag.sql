ALTER TABLE public.study_areas
    ADD COLUMN has_rch_results boolean NOT NULL DEFAULT true;

UPDATE public.study_areas
SET has_rch_results = true
WHERE name <> 'Kenya';