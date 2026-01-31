BEGIN;

-- 1) Ensure every study area has a "Default MCA" preset set (user_id NULL)
INSERT INTO mca_preset_sets (
    study_area_id,
    user_id,
    name,
    is_default
)
SELECT
    sa.id         AS study_area_id,
    NULL::bigint  AS user_id,
    'Default MCA' AS name,
    TRUE          AS is_default
FROM study_areas sa
WHERE NOT EXISTS (
    SELECT 1
    FROM mca_preset_sets ps
    WHERE ps.study_area_id = sa.id
      AND ps.user_id IS NULL
);

-- 2) Ensure every preset set has a "Default MCA variables" variable set (user_id NULL)
INSERT INTO mca_variable_sets (
    study_area_id,
    user_id,
    name,
    is_default,
    preset_set_id
)
SELECT
    ps.study_area_id,
    NULL::bigint             AS user_id,
    'Default MCA variables'  AS name,
    TRUE                     AS is_default,
    ps.id                    AS preset_set_id
FROM mca_preset_sets ps
WHERE NOT EXISTS (
    SELECT 1
    FROM mca_variable_sets vs
    WHERE vs.preset_set_id = ps.id
);

COMMIT;