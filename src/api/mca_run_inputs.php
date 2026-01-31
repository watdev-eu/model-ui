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
    'economic_life_years',
    'discount_rate',
    'bmp_invest_cost_usd_ha',
    'bmp_annual_om_cost_usd_ha',
    'water_cost_usd_m3',
    'water_use_fee_usd_ha',
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

// 3) Crop production cost factors per crop for THIS run (crop+run specific)
$factorKeys = [
    'bmp_labour_land_preparation_pd_ha',
    'bmp_labour_planting_pd_ha',
    'bmp_labour_fertilizer_application_pd_ha',
    'bmp_labour_weeding_pd_ha',
    'bmp_labour_pest_control_pd_ha',
    'bmp_labour_irrigation_pd_ha',
    'bmp_labour_harvesting_pd_ha',
    'bmp_labour_other_pd_ha',
    'bmp_material_seeds_usd_ha',
    'bmp_material_mineral_fertilisers_usd_ha',
    'bmp_material_organic_amendments_usd_ha',
    'bmp_material_pesticides_usd_ha',
    'bmp_material_tractor_usage_usd_ha',
    'bmp_material_equipment_usage_usd_ha',
    'bmp_material_other_usd_ha',
];

$inKeys = implode(',', array_fill(0, count($factorKeys), '?'));

$sql = "
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
  JOIN mca_variables v ON v.key IN ($inKeys)
  LEFT JOIN mca_variable_values_crop_run vvcr
    ON vvcr.crop_code = c.code
   AND vvcr.variable_id = v.id
   AND vvcr.variable_set_id = ?
   AND vvcr.run_id = ?
  ORDER BY c.code, v.key
";

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($factorKeys, [(int)$varSet['id'], $runId]));
$cropFactors = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'ok' => true,
    'variable_set'   => $varSet,
    'run_id'         => $runId,
    'variables_run'  => $variablesRun,
    'crop_factors'   => $cropFactors,
]);