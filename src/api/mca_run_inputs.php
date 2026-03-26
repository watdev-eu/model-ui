<?php
// src/api/mca_run_inputs.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/DashboardDatasetKey.php';
require_once __DIR__ . '/../classes/CustomScenarioRepository.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$studyAreaId = (int)($_GET['study_area_id'] ?? 0);
$rawRunId    = trim((string)($_GET['run_id'] ?? ''));

if ($studyAreaId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'study_area_id is required']);
    exit;
}
if ($rawRunId === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'run_id is required']);
    exit;
}

try {
    $parsed = DashboardDatasetKey::parse($rawRunId);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid dataset key']);
    exit;
}

/**
 * Return crop codes that actually appear in the merged custom dataset:
 * union of crops for each (sub -> effective run) pair.
 *
 * @return string[]
 */
function customScenarioCropList(PDO $pdo, array $effectiveRunMap): array
{
    if (!$effectiveRunMap) return [];

    $pairs = [];
    $params = [];
    $i = 0;

    foreach ($effectiveRunMap as $sub => $runId) {
        $sub = (int)$sub;
        $runId = (int)$runId;
        if ($sub <= 0 || $runId <= 0) continue;

        $pairs[] = "(h.run_id = :run{$i} AND h.sub = :sub{$i})";
        $params[":run{$i}"] = $runId;
        $params[":sub{$i}"] = $sub;
        $i++;
    }

    if (!$pairs) return [];

    $sql = "
        SELECT DISTINCT h.lulc AS crop_code
        FROM swat_hru_kpi h
        WHERE h.lulc IS NOT NULL
          AND h.lulc <> ''
          AND (" . implode(' OR ', $pairs) . ")
        ORDER BY h.lulc
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_values(array_filter(array_map(
        static fn($v) => trim((string)$v),
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    )));
}

/**
 * Return crop codes that actually occur in a single run.
 *
 * @return string[]
 */
function runCropList(PDO $pdo, int $runId): array
{
    if ($runId <= 0) return [];

    $stmt = $pdo->prepare("
        SELECT DISTINCT h.lulc AS crop_code
        FROM swat_hru_kpi h
        WHERE h.run_id = :run_id
          AND h.lulc IS NOT NULL
          AND h.lulc <> ''
        ORDER BY h.lulc
    ");
    $stmt->execute([':run_id' => $runId]);

    return array_values(array_filter(array_map(
        static fn($v) => trim((string)$v),
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    )));
}

/**
 * @param array<int,array<string,mixed>> $metaRows
 * @return array<int,array<string,mixed>>
 */
function blankVariableRows(array $metaRows): array
{
    $out = [];
    foreach ($metaRows as $r) {
        $out[] = [
            'key' => (string)($r['key'] ?? ''),
            'name' => (string)($r['name'] ?? ($r['key'] ?? '')),
            'unit' => $r['unit'] ?? null,
            'description' => $r['description'] ?? null,
            'data_type' => (string)($r['data_type'] ?? 'number'),
            'value_num' => null,
            'value_text' => null,
            'value_bool' => null,
        ];
    }
    return $out;
}

/**
 * @param string[] $cropCodes
 * @param array<string,string> $cropNamesByCode
 * @param array<int,array<string,mixed>> $factorMetaRows
 * @return array<int,array<string,mixed>>
 */
function blankCropFactorRows(array $cropCodes, array $cropNamesByCode, array $factorMetaRows): array
{
    $out = [];

    foreach ($cropCodes as $cropCode) {
        $cropCode = trim((string)$cropCode);
        if ($cropCode === '') continue;

        $cropName = $cropNamesByCode[$cropCode] ?? $cropCode;

        foreach ($factorMetaRows as $meta) {
            $out[] = [
                'crop_code' => $cropCode,
                'crop_name' => $cropName,
                'key' => (string)($meta['key'] ?? ''),
                'name' => (string)($meta['name'] ?? ($meta['key'] ?? '')),
                'unit' => $meta['unit'] ?? null,
                'description' => $meta['description'] ?? null,
                'data_type' => (string)($meta['data_type'] ?? 'number'),
                'value_num' => null,
                'value_text' => null,
                'value_bool' => null,
            ];
        }
    }

    return $out;
}

$pdo = Database::pdo();

$datasetType = (string)$parsed['type'];
$sourceRunIds = [];
$effectiveRunMap = null;

if ($datasetType === 'run') {
    $sourceRunIds = [(int)$parsed['id']];
} else {
    Auth::requireLogin();
    $userId = Auth::userId();
    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $scenarioId = (int)$parsed['id'];

    $scenario = CustomScenarioRepository::findByIdForUser($scenarioId, $userId);
    if (!$scenario) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Custom scenario not found']);
        exit;
    }

    if ((int)$scenario['study_area_id'] !== $studyAreaId) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Custom scenario does not belong to this study area']);
        exit;
    }

    $effectiveRunMap = CustomScenarioRepository::getEffectiveRunMapForUser($scenarioId, $userId);
    $sourceRunIds = array_values(array_unique(array_map('intval', array_values($effectiveRunMap))));
    sort($sourceRunIds);

    if (!$sourceRunIds) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Custom scenario has no effective source runs']);
        exit;
    }
}

// Find active variable set
$variableSetId = (int)($_GET['variable_set_id'] ?? 0);
$userId = Auth::userId();

if ($variableSetId > 0) {
    if ($userId !== null && $userId !== '') {
        $stmt = $pdo->prepare("
            SELECT id, study_area_id, name, user_id, is_default
            FROM mca_variable_sets
            WHERE id = :id
              AND study_area_id = :sa
              AND (user_id IS NULL OR user_id = :uid)
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $variableSetId,
            ':sa' => $studyAreaId,
            ':uid' => $userId,
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id, study_area_id, name, user_id, is_default
            FROM mca_variable_sets
            WHERE id = :id
              AND study_area_id = :sa
              AND user_id IS NULL
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $variableSetId,
            ':sa' => $studyAreaId,
        ]);
    }

    $varSet = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
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
}

if (!$varSet) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'No variable set configured for this study area']);
    exit;
}

$varSetId = (int)$varSet['id'];

// Scenario-level variable keys
$runVarKeys = [
    'economic_life_years',
    'discount_rate',
    'bmp_invest_cost_usd_ha',
    'bmp_annual_om_cost_usd_ha',
    'water_cost_usd_m3',
    'water_use_fee_usd_ha',
];

$runVarPlaceholders = implode(',', array_fill(0, count($runVarKeys), '?'));

// Metadata for scenario-level vars
$stmt = $pdo->prepare("
    SELECT
        v.key,
        v.name,
        v.unit,
        v.description,
        v.data_type
    FROM mca_variables v
    WHERE v.key IN ($runVarPlaceholders)
    ORDER BY v.key
");
$stmt->execute($runVarKeys);
$runVarMeta = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Factor keys
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

$factorPlaceholders = implode(',', array_fill(0, count($factorKeys), '?'));

// Metadata for crop factors
$stmt = $pdo->prepare("
    SELECT
        v.key,
        v.name,
        v.unit,
        v.description,
        v.data_type
    FROM mca_variables v
    WHERE v.key IN ($factorPlaceholders)
    ORDER BY v.key
");
$stmt->execute($factorKeys);
$factorMeta = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($datasetType === 'run') {
    $runId = (int)$sourceRunIds[0];

    // Regular run: load actual values
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
        WHERE v.key IN ($runVarPlaceholders)
        ORDER BY v.key
    ");
    $params = array_merge([$varSetId, $runId], $runVarKeys);
    $stmt->execute($params);
    $variablesRun = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $runCropCodes = runCropList($pdo, $runId);

    $cropFactors = [];
    if ($runCropCodes) {
        $cropPlaceholders = implode(',', array_fill(0, count($runCropCodes), '?'));

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
            JOIN mca_variables v ON v.key IN ($factorPlaceholders)
            LEFT JOIN mca_variable_values_crop_run vvcr
              ON vvcr.crop_code = c.code
             AND vvcr.variable_id = v.id
             AND vvcr.variable_set_id = ?
             AND vvcr.run_id = ?
            WHERE c.code IN ($cropPlaceholders)
            ORDER BY c.code, v.key
        ");

        $stmt->execute(array_merge(
            $factorKeys,
            [$varSetId, $runId],
            $runCropCodes
        ));
        $cropFactors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'ok' => true,
        'dataset_id' => $rawRunId,
        'dataset_type' => $datasetType,
        'source_run_ids' => array_values(array_map('intval', $sourceRunIds)),
        'effective_run_map' => null,
        'variable_set' => $varSet,
        'run_id' => $runId,
        'variables_run' => $variablesRun,
        'crop_factors' => $cropFactors,
    ]);
    exit;
}

// Custom scenario: blank values, but correct crop list
$customCropCodes = customScenarioCropList($pdo, $effectiveRunMap ?? []);

// crop names for custom crop codes
$cropNamesByCode = [];
if ($customCropCodes) {
    $cropNamePlaceholders = implode(',', array_fill(0, count($customCropCodes), '?'));
    $stmt = $pdo->prepare("
        SELECT code, name
        FROM crops
        WHERE code IN ($cropNamePlaceholders)
    ");
    $stmt->execute($customCropCodes);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cropNamesByCode[(string)$r['code']] = (string)$r['name'];
    }
}

$variablesRun = blankVariableRows($runVarMeta);
$cropFactors = blankCropFactorRows($customCropCodes, $cropNamesByCode, $factorMeta);

echo json_encode([
    'ok' => true,
    'dataset_id' => $rawRunId,
    'dataset_type' => $datasetType,
    'source_run_ids' => array_values(array_map('intval', $sourceRunIds)),
    'effective_run_map' => $effectiveRunMap,
    'variable_set' => $varSet,
    'run_id' => null,
    'variables_run' => $variablesRun,
    'crop_factors' => $cropFactors,
]);