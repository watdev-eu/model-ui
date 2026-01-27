<?php
// src/api/mca_preset_active.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false,'error' => 'Method not allowed']);
    exit;
}

$studyAreaId = (int)($_GET['study_area_id'] ?? 0);
if ($studyAreaId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false,'error' => 'study_area_id is required']);
    exit;
}

$pdo = Database::pdo();

// pick “the” preset: default/global first. (later: user-owned can override)
$stmt = $pdo->prepare("
  SELECT id, study_area_id, name, user_id, is_default
  FROM mca_preset_sets
  WHERE study_area_id = :sa
    AND is_default = TRUE
    AND user_id IS NULL
  ORDER BY id ASC
  LIMIT 1
");
$stmt->execute([':sa' => $studyAreaId]);
$preset = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$preset) {
    http_response_code(404);
    echo json_encode(['ok' => false,'error' => 'No preset configured for this study area']);
    exit;
}

// variable set: default/global first (later: user-owned override)
$stmt = $pdo->prepare("
  SELECT id, study_area_id, name, user_id, is_default
  FROM mca_variable_sets
  WHERE study_area_id = :sa
    AND is_default = TRUE
    AND user_id IS NULL
  ORDER BY id ASC
  LIMIT 1
");
$stmt->execute([':sa' => $studyAreaId]);
$varSet = $stmt->fetch(PDO::FETCH_ASSOC);

// variables + values
$globalKeys = [
    'farm_size_ha',
    'land_rent_usd_ha_yr',
    'water_use_fee_usd_m3',
    'water_cost_usd_m3',
];

$place = implode(',', array_fill(0, count($globalKeys), '?'));

$stmt = $pdo->prepare("
  SELECT v.key, v.name, v.unit, v.description, v.data_type,
         vv.value_num, vv.value_text, vv.value_bool
  FROM mca_variables v
  LEFT JOIN mca_variable_values vv
    ON vv.variable_id = v.id AND vv.variable_set_id = ?
  WHERE v.key IN ($place)
  ORDER BY v.key
");
$params = array_merge([(int)$varSet['id']], $globalKeys);
$stmt->execute($params);
$variables = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
  SELECT
    i.code AS indicator_code,
    i.name AS indicator_name,
    i.calc_key,
    COALESCE(pi.direction, i.default_direction) AS direction,
    pi.weight,
    pi.is_enabled
  FROM mca_preset_items pi
  JOIN mca_indicators i ON i.id = pi.indicator_id
  WHERE pi.preset_set_id = :ps
  ORDER BY i.code
");
$stmt->execute([':ps' => (int)$preset['id']]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
  SELECT
    id,
    scenario_key,
    label,
    run_id,
    sort_order
  FROM mca_scenarios
  WHERE preset_set_id = :ps
  ORDER BY sort_order ASC, id ASC
");
$stmt->execute([':ps' => (int)$preset['id']]);
$scenarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cropVariables = [];
if ($varSet) {
    $stmt = $pdo->prepare("
      SELECT
        c.code AS crop_code,
        c.name AS crop_name,
        v.key,
        v.name,
        v.unit,
        v.description,
        v.data_type,
        vvc.value_num,
        vvc.value_text,
        vvc.value_bool
      FROM crops c
      CROSS JOIN mca_variables v
      LEFT JOIN mca_variable_values_crop vvc
        ON vvc.crop_code = c.code
       AND vvc.variable_id = v.id
       AND vvc.variable_set_id = :vs
      WHERE v.key IN ('crop_price_usd_per_t')
      ORDER BY c.code, v.key
    ");
    $stmt->execute([':vs' => (int)$varSet['id']]);
    $cropVariables = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$stmt = $pdo->prepare("
  SELECT id
  FROM swat_runs
  WHERE study_area = :sa
    AND is_baseline = TRUE
  LIMIT 1
");
$stmt->execute([':sa' => $studyAreaId]);
$baselineRunId = (int)($stmt->fetchColumn() ?: 0);

$runId = (int)($_GET['run_id'] ?? 0);
if ($runId <= 0 && !empty($scenarios)) {
    $runId = (int)$scenarios[0]['run_id'];
}

$variablesRun = [];
if ($varSet && $runId > 0) {
    $stmt = $pdo->prepare("
    SELECT
      v.key, v.name, v.unit, v.description, v.data_type,
      vvr.value_num, vvr.value_text, vvr.value_bool
    FROM mca_variables v
    LEFT JOIN mca_variable_values_run vvr
      ON vvr.variable_id = v.id
     AND vvr.variable_set_id = :vs
     AND vvr.run_id = :run
    WHERE v.key IN (
      'discount_rate',
      'time_horizon_years',
      'bmp_invest_cost_usd_ha',
      'bmp_annual_om_cost_usd_ha',
      'water_use_fee_usd_m3'
    )
    ORDER BY v.key
  ");
    $stmt->execute([':vs' => (int)$varSet['id'], ':run' => $runId]);
    $variablesRun = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$cropPrice = [];
$cropBmp   = [];
$cropRef   = [];

if ($varSet) {
    // 1) crop price global
    $stmt = $pdo->prepare("
    SELECT c.code crop_code, c.name crop_name, v.key, v.data_type,
           vvc.value_num, vvc.value_text, vvc.value_bool
    FROM crops c
    JOIN mca_variables v ON v.key = 'crop_price_usd_per_t'
    LEFT JOIN mca_variable_values_crop vvc
      ON vvc.crop_code = c.code
     AND vvc.variable_id = v.id
     AND vvc.variable_set_id = :vs
    ORDER BY c.code
  ");
    $stmt->execute([':vs' => (int)$varSet['id']]);
    $cropPrice = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2) BMP cost per crop per run
    if ($runId > 0) {
        $stmt = $pdo->prepare("
          SELECT c.code crop_code, c.name crop_name, v.key, v.data_type,
                 vvcr.value_num, vvcr.value_text, vvcr.value_bool
          FROM crops c
          JOIN mca_variables v ON v.key = 'prod_cost_bmp_usd_ha'
          LEFT JOIN mca_variable_values_crop_run vvcr
            ON vvcr.crop_code = c.code
           AND vvcr.variable_id = v.id
           AND vvcr.variable_set_id = :vs
           AND vvcr.run_id = :run
          ORDER BY c.code
        ");
        $stmt->execute([':vs' => (int)$varSet['id'], ':run' => $runId]);
        $cropBmp = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 3) REF cost per crop per baseline run
    if ($baselineRunId > 0) {
        $stmt = $pdo->prepare("
          SELECT c.code crop_code, c.name crop_name, v.key, v.data_type,
                 vvcr.value_num, vvcr.value_text, vvcr.value_bool
          FROM crops c
          JOIN mca_variables v ON v.key = 'prod_cost_bmp_usd_ha'
          LEFT JOIN mca_variable_values_crop_run vvcr
            ON vvcr.crop_code = c.code
           AND vvcr.variable_id = v.id
           AND vvcr.variable_set_id = :vs
           AND vvcr.run_id = :run
          ORDER BY c.code
        ");
        $stmt->execute([':vs' => (int)$varSet['id'], ':run' => $baselineRunId]);
        $cropRef = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!$items) {
    http_response_code(422);
    echo json_encode(['ok' => false,'error' => 'Preset has no items configured']);
    exit;
}

echo json_encode([
    'ok' => true,
    'preset' => $preset,
    'items' => $items,
    'scenarios' => $scenarios,
    'variable_set' => $varSet,
    'variables' => $variables,
    'crop_variables' => $cropVariables,
    'variables_global' => $variables,
    'variables_run'    => $variablesRun,
    'crop_price'       => $cropPrice,
    'crop_bmp_cost'    => $cropBmp,
    'crop_ref_cost'    => $cropRef,
    'run_id'           => $runId,
    'baseline_run_id'  => $baselineRunId,
]);