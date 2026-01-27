<?php
// src/api/mca_run_inputs.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$studyAreaId = (int)($_GET['study_area_id'] ?? 0);
$runId       = (int)($_GET['run_id'] ?? 0);

if ($studyAreaId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'study_area_id is required']);
    exit;
}
if ($runId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'run_id is required']);
    exit;
}

$pdo = Database::pdo();

// 1) Find active variable set for this study area (default/global for now)
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

if (!$varSet) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'No variable set configured for this study area']);
    exit;
}

// 2) Run-level variables (scenario-specific)
$runVarKeys = [
    'bmp_prod_cost_usd_ha',
    'time_horizon_years',
    'discount_rate',
    'bmp_invest_cost_usd_ha',
    'bmp_annual_cost_usd_ha',
];

$placeholders = implode(',', array_fill(0, count($runVarKeys), '?'));

$stmt = $pdo->prepare("
  SELECT
    v.key,
    v.name,
    v.unit,
    v.description,
    v.data_type,
    vvr.value_num,
    vvr.value_text,
    vvr.value_bool
  FROM mca_variables v
  LEFT JOIN mca_variable_values_run vvr
    ON vvr.variable_id = v.id
   AND vvr.variable_set_id = ?
   AND vvr.run_id = ?
  WHERE v.key IN ($placeholders)
  ORDER BY v.key
");

$params = array_merge([(int)$varSet['id'], $runId], $runVarKeys);
$stmt->execute($params);
$variablesRun = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3) BMP production cost per crop for THIS run
// key: prod_cost_bmp_usd_ha
$stmt = $pdo->prepare("
  SELECT
    c.code AS crop_code,
    c.name AS crop_name,
    v.key,
    v.name,
    v.unit,
    v.description,
    v.data_type,
    vvcr.value_num,
    vvcr.value_text,
    vvcr.value_bool
  FROM crops c
  JOIN mca_variables v ON v.key = 'prod_cost_bmp_usd_ha'
  LEFT JOIN mca_variable_values_crop_run vvcr
    ON vvcr.crop_code = c.code
   AND vvcr.variable_id = v.id
   AND vvcr.variable_set_id = :vs
   AND vvcr.run_id = :run
  ORDER BY c.code
");
$stmt->execute([
    ':vs' => (int)$varSet['id'],
    ':run' => $runId
]);
$cropBmp = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'ok' => true,
    'variable_set'  => $varSet,
    'run_id'        => $runId,
    'variables_run' => $variablesRun,
    'crop_bmp_cost' => $cropBmp,
]);