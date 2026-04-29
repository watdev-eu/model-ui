<?php
// src/api/mca_preset_active.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/McaWorkspaceRepository.php';

header('Content-Type: application/json');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'You must be logged in.',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$studyAreaId = (int)($_GET['study_area_id'] ?? 0);
if ($studyAreaId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'study_area_id is required']);
    exit;
}

$pdo = Database::pdo();

$workspaceMode = trim((string)($_GET['workspace_mode'] ?? 'auto'));
if (!in_array($workspaceMode, ['auto', 'system', 'workspace'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid workspace_mode']);
    exit;
}

$workspaceId = (int)($_GET['workspace_id'] ?? 0);
$userId = Auth::userId();
$workspace = null;

/**
 * Option B behavior:
 * - system   => always load built-in/system defaults, never a user workspace
 * - workspace=> load exact user workspace by id
 * - auto     => old behavior: use user's default workspace if available
 */
if ($workspaceMode === 'system') {
    $workspace = null;
} elseif ($workspaceMode === 'workspace') {
    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    if ($workspaceId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'workspace_id is required for workspace mode']);
        exit;
    }

    $workspace = McaWorkspaceRepository::findByIdForUser($workspaceId, $userId);
    if (!$workspace || (int)$workspace['study_area_id'] !== $studyAreaId) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Workspace not found']);
        exit;
    }
} else { // auto
    if ($userId !== null) {
        if ($workspaceId > 0) {
            $workspace = McaWorkspaceRepository::findByIdForUser($workspaceId, $userId);
            if ($workspace && (int)$workspace['study_area_id'] !== $studyAreaId) {
                $workspace = null;
            }
        } else {
            $workspace = McaWorkspaceRepository::findDefaultForUser($studyAreaId, $userId);
        }
    }
}

if ($workspace) {
    $stmt = $pdo->prepare("
        SELECT id, study_area_id, name, user_id, is_default
        FROM mca_preset_sets
        WHERE id = :id
          AND study_area_id = :sa
          AND (user_id IS NULL OR user_id = :uid)
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => (int)$workspace['preset_set_id'],
        ':sa' => $studyAreaId,
        ':uid' => $userId,
    ]);
    $preset = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
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
}

if (!$preset) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'No preset configured for this study area']);
    exit;
}

if ($workspace) {
    $stmt = $pdo->prepare("
        SELECT id, study_area_id, name, user_id, is_default
        FROM mca_variable_sets
        WHERE id = :id
          AND study_area_id = :sa
          AND (user_id IS NULL OR user_id = :uid)
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => (int)$workspace['variable_set_id'],
        ':sa' => $studyAreaId,
        ':uid' => $userId,
    ]);
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

if (!$varSet || !isset($varSet['id'])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'No variable set configured for this study area']);
    exit;
}

$items = [];
$scenarios = [];
$variables = [];
$cropVariables = [];
$variablesRun = [];
$cropPrice = [];
$cropBmpFactors = [];
$cropRefFactors = [];
$workspaceRunVariables = [];
$workspaceRunCropFactors = [];
$selectedDatasetIds = [];

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

$stmt = $pdo->prepare("
    SELECT id
    FROM swat_runs
    WHERE study_area = :sa
      AND is_baseline = TRUE
    LIMIT 1
");
$stmt->execute([':sa' => $studyAreaId]);
$baselineRunId = (int)($stmt->fetchColumn() ?: 0);

if ($workspace) {
    $selectedDatasetIds = McaWorkspaceRepository::getSelectedDatasetIds((int)$workspace['id']);
    $items = McaWorkspaceRepository::getPresetItems((int)$workspace['id']);
    $variables = McaWorkspaceRepository::getVariables((int)$workspace['id']);
    $cropVariables = McaWorkspaceRepository::getCropVariables((int)$workspace['id']);
    $cropRefFactors = McaWorkspaceRepository::getCropRefFactors((int)$workspace['id']);
    $workspaceRunVariables = McaWorkspaceRepository::getRunVariables((int)$workspace['id']);
    $workspaceRunCropFactors = McaWorkspaceRepository::getRunCropFactors((int)$workspace['id']);

    // scenarios can still come from preset set metadata for now
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

} else {
    // -------- system/default fallback (existing behavior) --------

    $globalKeys = [
        'farm_size_ha',
        'land_rent_usd_ha_yr',
        'labour_day_cost_usd_per_pd',
    ];

    $place = implode(',', array_fill(0, count($globalKeys), '?'));

    $stmt = $pdo->prepare("
        SELECT
            v.key,
            v.name,
            v.unit,
            v.description,
            v.data_type,
            vv.value_num,
            vv.value_text,
            vv.value_bool
        FROM mca_variables v
        LEFT JOIN mca_variable_values vv
          ON vv.variable_id = v.id
         AND vv.variable_set_id = ?
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
            i.calc_key AS indicator_calc_key,
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

    $runId = (int)($_GET['run_id'] ?? 0);

    if ($runId <= 0 && !empty($scenarios)) {
        $runId = (int)$scenarios[0]['run_id'];
    }

    if ($runId > 0) {
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
             AND vvr.variable_set_id = :vs
             AND vvr.run_id = :run
            WHERE v.key IN (
              'discount_rate',
              'economic_life_years',
              'bmp_invest_cost_usd_ha',
              'bmp_annual_om_cost_usd_ha',
              'water_cost_usd_m3',
              'water_use_fee_usd_ha'
            )
            ORDER BY v.key
        ");
        $stmt->execute([
            ':vs' => (int)$varSet['id'],
            ':run' => $runId,
        ]);
        $variablesRun = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $vsId = (int)$varSet['id'];

    $fetchFactorsForRun = function (int $runId) use ($pdo, $vsId, $factorKeys): array {
        if ($runId <= 0) {
            return [];
        }

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
        $stmt->execute(array_merge($factorKeys, [$vsId, $runId]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    if ($runId > 0) {
        $cropBmpFactors = $fetchFactorsForRun($runId);
    }

    if ($baselineRunId > 0) {
        $cropRefFactors = $fetchFactorsForRun($baselineRunId);
    }
}

if (!$items) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Preset/workspace has no items configured']);
    exit;
}

echo json_encode([
    'ok' => true,
    'workspace_mode' => $workspaceMode,
    'preset' => $preset,
    'workspace' => $workspace,
    'selected_dataset_ids' => $selectedDatasetIds,
    'items' => $items,
    'scenarios' => $scenarios,
    'variable_set' => $varSet,
    'variables' => $variables,
    'crop_variables' => $cropVariables,
    'variables_global' => $variables,
    'variables_run' => $variablesRun,
    'crop_price' => $cropPrice,
    'crop_bmp_factors' => $cropBmpFactors,
    'crop_ref_factors' => $cropRefFactors,
    'workspace_run_variables' => $workspaceRunVariables,
    'workspace_run_crop_factors' => $workspaceRunCropFactors,
    'baseline_run_id' => $baselineRunId,
]);