<?php
// src/api/mca_defaults_admin.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// require_admin();

// -------------------- helpers --------------------
function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function normalizeValueForDb(string $dataType, $raw): array {
    // returns [value_num, value_text, value_bool]
    $valueNum = null;
    $valueText = null;
    $valueBool = null;

    if ($raw === '' || $raw === null) {
        return [$valueNum, $valueText, $valueBool];
    }

    if ($dataType === 'bool') {
        // accept true/false, "1"/"0", "on"
        $valueBool = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return [$valueNum, $valueText, $valueBool];
    }

    if ($dataType === 'number') {
        $n = is_numeric($raw) ? (float)$raw : null;
        $valueNum = (is_float($n) || is_int($n)) ? $n : null;
        return [$valueNum, $valueText, $valueBool];
    }

    // text
    $valueText = trim((string)$raw);
    if ($valueText === '') $valueText = null;
    return [$valueNum, $valueText, $valueBool];
}

// -------------------- method routing --------------------
$pdo = Database::pdo();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $studyAreaId = (int)($_GET['study_area_id'] ?? 0);

    // 1) study areas always returned (dynamic)
    $areas = $pdo->query("
        SELECT id, name, enabled
        FROM study_areas
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // If no study area chosen yet: return only areas
    if ($studyAreaId <= 0) {
        echo json_encode([
            'ok' => true,
            'study_areas' => array_map(fn($a) => [
                'id' => (int)$a['id'],
                'name' => $a['name'],
                'enabled' => (bool)$a['enabled'],
            ], $areas),
        ]);
        exit;
    }

    // runs for this study area (for the dropdown)
    $stmt = $pdo->prepare("
        SELECT id, run_label, run_date, is_baseline
        FROM swat_runs
        WHERE study_area = :sa
        ORDER BY run_date DESC NULLS LAST, created_at DESC NULLS LAST, id DESC
    ");
    $stmt->execute([':sa' => $studyAreaId]);
    $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $runsOut = array_map(fn($r) => [
        'id' => (int)$r['id'],
        'run_label' => (string)$r['run_label'],
        'run_date' => !empty($r['run_date']) ? substr((string)$r['run_date'], 0, 10) : null,
        'is_baseline' => !empty($r['is_baseline']),
    ], $runs);

    // 2) find default variable set for this study area (global/default)
    $stmt = $pdo->prepare("
        SELECT id, study_area_id, name, user_id, is_default, preset_set_id
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
        // Optional: auto-create default set (disabled by default)
        // fail(404, 'No default MCA variable set found for this study area.');
        echo json_encode([
            'ok' => true,
            'study_areas' => array_map(fn($a) => [
                'id' => (int)$a['id'],
                'name' => $a['name'],
                'enabled' => (bool)$a['enabled'],
            ], $areas),
            'study_area_id' => $studyAreaId,
            'runs' => $runsOut,
            'variable_set' => null,
            'variables_global' => [],
            'crop_variables' => [],
            'crops_in_runs' => [],
            'note' => 'No default MCA variable set configured for this study area.',
        ]);
        exit;
    }

    $varSetId = (int)$varSet['id'];

    $runId = (int)($_GET['run_id'] ?? 0);
    if ($runId > 0) {
        // run keys you want editable
        $runKeys = [
            'bmp_prod_cost_usd_ha',
            'time_horizon_years',
            'discount_rate',
            'bmp_invest_cost_usd_ha',
            'bmp_annual_om_cost_usd_ha',
        ];

        $place = implode(',', array_fill(0, count($runKeys), '?'));
        $stmt = $pdo->prepare("
            SELECT
                v.key, v.name, v.unit, v.description, v.data_type,
                vvr.value_num, vvr.value_text, vvr.value_bool
            FROM mca_variables v
            LEFT JOIN mca_variable_values_run vvr
              ON vvr.variable_id = v.id
             AND vvr.variable_set_id = ?
             AND vvr.run_id = ?
            WHERE v.key IN ($place)
            ORDER BY v.key
        ");
        $stmt->execute(array_merge([$varSetId, $runId], $runKeys));
        $runVars = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // crops in THIS run (scenario)
        $stmt = $pdo->prepare("
            SELECT DISTINCT TRIM(lulc) AS crop_code
            FROM swat_hru_kpi
            WHERE run_id = :run_id
              AND lulc IS NOT NULL
              AND TRIM(lulc) <> ''
            ORDER BY TRIM(lulc) ASC
        ");
        $stmt->execute([':run_id' => $runId]);
        $runCropCodes = array_values(array_filter(array_map(
            fn($x) => (string)$x['crop_code'],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        )));

        $runCropVars = [];
        $runCropKeys = ['prod_cost_bmp_usd_ha'];

        if ($runCropCodes) {
            $valuesSql = implode(',', array_fill(0, count($runCropCodes), '(?)'));
            $inKeys    = implode(',', array_fill(0, count($runCropKeys), '?'));

            $sql = "
              WITH crop_list(crop_code) AS (VALUES $valuesSql)
              SELECT
                cl.crop_code,
                COALESCE(c.name,'') AS crop_name,
                v.key, v.name, v.unit, v.description, v.data_type,
                vvcr.value_num, vvcr.value_text, vvcr.value_bool
              FROM crop_list cl
              LEFT JOIN crops c ON c.code = cl.crop_code
              JOIN mca_variables v ON v.key IN ($inKeys)
              LEFT JOIN mca_variable_values_crop_run vvcr
                ON vvcr.crop_code = cl.crop_code
               AND vvcr.variable_id = v.id
               AND vvcr.variable_set_id = ?
               AND vvcr.run_id = ?
              ORDER BY cl.crop_code, v.key
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($runCropCodes, $runCropKeys, [$varSetId, $runId]));
            $runCropVars = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode([
            'ok' => true,
            'study_areas' => array_map(fn($a) => [
                'id' => (int)$a['id'],
                'name' => $a['name'],
                'enabled' => (bool)$a['enabled'],
            ], $areas),
            'study_area_id' => $studyAreaId,
            'runs' => $runsOut,

            'run_id' => $runId,
            'variable_set' => [
                'id' => (int)$varSet['id'],
                'name' => $varSet['name'],
                'is_default' => (bool)$varSet['is_default'],
                'preset_set_id' => (int)$varSet['preset_set_id'],
            ],
            'run_variables' => $runVars,
            'run_crops_in_run' => $runCropCodes,
            'run_crop_variables' => $runCropVars,
        ]);
        exit;
    }

    // 3) define which global keys we manage in phase 1
    $globalKeys = [
        'farm_size_ha',
        'water_cost_usd_m3',
        'water_use_fee_usd_m3',
    ];

    // Fetch variable definitions + current values
    $place = implode(',', array_fill(0, count($globalKeys), '?'));
    $stmt = $pdo->prepare("
        SELECT
            v.key, v.name, v.unit, v.description, v.data_type,
            vv.value_num, vv.value_text, vv.value_bool
        FROM mca_variables v
        LEFT JOIN mca_variable_values vv
          ON vv.variable_id = v.id
         AND vv.variable_set_id = ?
        WHERE v.key IN ($place)
        ORDER BY v.key
    ");
    $stmt->execute(array_merge([$varSetId], $globalKeys));
    $globals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4) crops occurring in runs for this study area
    $stmt = $pdo->prepare("
        SELECT DISTINCT TRIM(hk.lulc) AS crop_code
        FROM swat_hru_kpi hk
        JOIN swat_runs r ON r.id = hk.run_id
        WHERE r.study_area = :sa
          AND hk.lulc IS NOT NULL
          AND TRIM(hk.lulc) <> ''
        ORDER BY TRIM(hk.lulc) ASC
    ");
    $stmt->execute([':sa' => $studyAreaId]);
    $cropCodes = array_values(array_filter(array_map(
        fn($x) => (string)$x['crop_code'],
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    )));

    $cropKeys = ['crop_price_usd_per_t'];
    $cropVars = [];

    if ($cropCodes) {
        // Build VALUES list like: ('CORN'),('CSIL')...
        $valuesSql = implode(',', array_fill(0, count($cropCodes), '(?)'));
        $inKeys    = implode(',', array_fill(0, count($cropKeys), '?'));

        $sql = "
            WITH crop_list(crop_code) AS (
                VALUES $valuesSql
            )
            SELECT
                cl.crop_code,
                COALESCE(c.name, '') AS crop_name,
                v.key,
                v.name,
                v.unit,
                v.description,
                v.data_type,
                vvc.value_num,
                vvc.value_text,
                vvc.value_bool
            FROM crop_list cl
            LEFT JOIN crops c ON c.code = cl.crop_code
            JOIN mca_variables v ON v.key IN ($inKeys)
            LEFT JOIN mca_variable_values_crop vvc
              ON vvc.crop_code = cl.crop_code
             AND vvc.variable_id = v.id
             AND vvc.variable_set_id = ?
            ORDER BY cl.crop_code, v.key
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($cropCodes, $cropKeys, [$varSetId]));
        $cropVars = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'ok' => true,
        'study_areas' => array_map(fn($a) => [
            'id' => (int)$a['id'],
            'name' => $a['name'],
            'enabled' => (bool)$a['enabled'],
        ], $areas),

        'study_area_id' => $studyAreaId,
        'variable_set' => [
            'id' => (int)$varSet['id'],
            'name' => $varSet['name'],
            'is_default' => (bool)$varSet['is_default'],
            'preset_set_id' => (int)$varSet['preset_set_id'],
        ],

        'variables_global' => $globals,
        'crops_in_runs' => $cropCodes,
        'crop_variables' => $cropVars,
        'runs' => $runsOut,
    ]);
    exit;
}

// -------------------- POST (save) --------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Method not allowed');
}

// CSRF check
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$csrf = $_POST['csrf'] ?? '';
if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    fail(403, 'Invalid CSRF token');
}

$action = $_POST['action'] ?? '';
if (!in_array($action, ['save', 'save_run_defaults'], true)) {
    fail(400, 'Unknown action');
}

$studyAreaId = (int)($_POST['study_area_id'] ?? 0);
if ($studyAreaId <= 0) fail(422, 'study_area_id is required');

$globalsJson = $_POST['globals_json'] ?? '[]';
$cropsJson   = $_POST['crop_vars_json'] ?? '[]';

$globals = json_decode($globalsJson, true);
$crops   = json_decode($cropsJson, true);

if (!is_array($globals)) $globals = [];
if (!is_array($crops)) $crops = [];

$pdo->beginTransaction();
try {
    // find default var set
    $stmt = $pdo->prepare("
        SELECT id
        FROM mca_variable_sets
        WHERE study_area_id = :sa
          AND is_default = TRUE
          AND user_id IS NULL
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->execute([':sa' => $studyAreaId]);
    $varSetId = (int)($stmt->fetchColumn() ?: 0);
    if ($varSetId <= 0) fail(404, 'No default MCA variable set configured for this study area');

    // map variable key -> id + data_type
    $stmt = $pdo->query("SELECT id, key, data_type FROM mca_variables");
    $varRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $varByKey = [];
    foreach ($varRows as $r) {
        $varByKey[(string)$r['key']] = ['id' => (int)$r['id'], 'data_type' => (string)$r['data_type']];
    }

    // upsert global values
    if ($globals) {
        $stmtUpsert = $pdo->prepare("
            INSERT INTO mca_variable_values (variable_set_id, variable_id, value_num, value_text, value_bool)
            VALUES (:vs, :vid, :n, :t, :b)
            ON CONFLICT (variable_set_id, variable_id)
            DO UPDATE SET value_num = EXCLUDED.value_num,
                          value_text = EXCLUDED.value_text,
                          value_bool = EXCLUDED.value_bool
        ");

        foreach ($globals as $g) {
            $key = (string)($g['key'] ?? '');
            if ($key === '' || !isset($varByKey[$key])) continue;

            $vid = $varByKey[$key]['id'];
            $dt  = $varByKey[$key]['data_type'];

            [$n, $t, $b] = normalizeValueForDb($dt, $g['value'] ?? null);

            $stmtUpsert->execute([
                ':vs' => $varSetId,
                ':vid' => $vid,
                ':n' => $n,
                ':t' => $t,
                ':b' => $b,
            ]);
        }
    }

    // upsert crop values
    if ($crops) {
        $stmtUpsertCrop = $pdo->prepare("
            INSERT INTO mca_variable_values_crop (variable_set_id, variable_id, crop_code, value_num, value_text, value_bool)
            VALUES (:vs, :vid, :crop, :n, :t, :b)
            ON CONFLICT (variable_set_id, variable_id, crop_code)
            DO UPDATE SET value_num = EXCLUDED.value_num,
                          value_text = EXCLUDED.value_text,
                          value_bool = EXCLUDED.value_bool
        ");

        $allowedCropDefaultKeys = ['crop_price_usd_per_t'];
        foreach ($crops as $cv) {
            $crop = trim((string)($cv['crop_code'] ?? ''));
            $key  = trim((string)($cv['key'] ?? ''));
            if (!in_array($key, $allowedCropDefaultKeys, true)) continue;
            if ($crop === '' || $key === '' || !isset($varByKey[$key])) continue;

            $vid = $varByKey[$key]['id'];
            $dt  = $varByKey[$key]['data_type'];

            [$n, $t, $b] = normalizeValueForDb($dt, $cv['value'] ?? null);

            $stmtUpsertCrop->execute([
                ':vs' => $varSetId,
                ':vid' => $vid,
                ':crop' => $crop,
                ':n' => $n,
                ':t' => $t,
                ':b' => $b,
            ]);
        }
    }

    if ($action === 'save_run_defaults') {
        $runId = (int)($_POST['run_id'] ?? 0);
        if ($runId <= 0) fail(422, 'run_id is required');

        $runGlobalsJson = $_POST['run_globals_json'] ?? '[]';
        $runCropsJson   = $_POST['run_crop_vars_json'] ?? '[]';

        $runGlobals = json_decode($runGlobalsJson, true);
        $runCrops   = json_decode($runCropsJson, true);

        if (!is_array($runGlobals)) $runGlobals = [];
        if (!is_array($runCrops))   $runCrops = [];

        // upsert run values
        $stmtUpsertRun = $pdo->prepare("
        INSERT INTO mca_variable_values_run (variable_set_id, run_id, variable_id, value_num, value_text, value_bool)
        VALUES (:vs, :run, :vid, :n, :t, :b)
        ON CONFLICT (variable_set_id, run_id, variable_id)
        DO UPDATE SET value_num = EXCLUDED.value_num,
                      value_text = EXCLUDED.value_text,
                      value_bool = EXCLUDED.value_bool
    ");

        foreach ($runGlobals as $g) {
            $key = (string)($g['key'] ?? '');
            if ($key === '' || !isset($varByKey[$key])) continue;

            $vid = $varByKey[$key]['id'];
            $dt  = $varByKey[$key]['data_type'];
            [$n, $t, $b] = normalizeValueForDb($dt, $g['value'] ?? null);

            $stmtUpsertRun->execute([
                ':vs' => $varSetId,
                ':run' => $runId,
                ':vid' => $vid,
                ':n' => $n,
                ':t' => $t,
                ':b' => $b,
            ]);
        }

        // upsert run+crop values
        $stmtUpsertRunCrop = $pdo->prepare("
            INSERT INTO mca_variable_values_crop_run (variable_set_id, run_id, crop_code, variable_id, value_num, value_text, value_bool)
            VALUES (:vs, :run, :crop, :vid, :n, :t, :b)
            ON CONFLICT (variable_set_id, run_id, crop_code, variable_id)
            DO UPDATE SET value_num = EXCLUDED.value_num,
                          value_text = EXCLUDED.value_text,
                          value_bool = EXCLUDED.value_bool
        ");

        $allowedRunCropKeys = ['prod_cost_bmp_usd_ha'];

        foreach ($runCrops as $cv) {
            $crop = trim((string)($cv['crop_code'] ?? ''));
            $key  = trim((string)($cv['key'] ?? ''));

            if (!in_array($key, $allowedRunCropKeys, true)) continue;
            if ($crop === '' || $key === '' || !isset($varByKey[$key])) continue;

            $vid = $varByKey[$key]['id'];
            $dt  = $varByKey[$key]['data_type'];
            [$n, $t, $b] = normalizeValueForDb($dt, $cv['value'] ?? null);

            $stmtUpsertRunCrop->execute([
                ':vs' => $varSetId,
                ':run' => $runId,
                ':crop' => $crop,
                ':vid' => $vid,
                ':n' => $n,
                ':t' => $t,
                ':b' => $b,
            ]);
        }

        $pdo->commit();
        echo json_encode(['ok' => true]);
        exit;
    }

    $pdo->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[mca_defaults_admin] ' . $e->getMessage());
    fail(500, 'Server error');
}