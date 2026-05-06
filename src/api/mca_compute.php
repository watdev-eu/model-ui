<?php
// src/api/mca_compute.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/McaComputeService.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/DashboardDatasetKey.php';
require_once __DIR__ . '/../classes/CustomScenarioRepository.php';

header('Content-Type: application/json');

function bad(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// --- CSRF (adjust if you have a helper already) ---
$csrf = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
    bad(403, 'Invalid CSRF token');
}

// --- Parse inputs ---
$allowedCropCodes = [];
$acj = (string)($_POST['allowed_crop_codes_json'] ?? '[]');
$decoded = json_decode($acj, true);
if (is_array($decoded)) {
    $allowedCropCodes = array_values(array_filter(array_map('strval', $decoded)));
}

$presetSetId = isset($_POST['preset_set_id']) ? (int)$_POST['preset_set_id'] : 0;
if ($presetSetId <= 0) bad(400, 'preset_set_id is required');

$cropCode = isset($_POST['crop_code']) && $_POST['crop_code'] !== '' ? (string)$_POST['crop_code'] : null;

$datasetIds = [];
$datasetIdsJson = (string)($_POST['dataset_ids_json'] ?? $_POST['run_ids_json'] ?? '[]');
$decoded = json_decode($datasetIdsJson, true);
if (is_array($decoded)) $datasetIds = array_values(array_map('strval', $decoded));

$presetItems = [];
$pj = (string)($_POST['preset_items_json'] ?? '[]');
$decoded = json_decode($pj, true);
if (is_array($decoded)) $presetItems = $decoded;

$variables = [];
$vj = (string)($_POST['variables_json'] ?? '[]');
$decoded = json_decode($vj, true);
if (is_array($decoded)) $variables = $decoded;

$cropVars = [];
$cvj = (string)($_POST['crop_variables_json'] ?? '[]');
$decoded = json_decode($cvj, true);
if (is_array($decoded)) $cropVars = $decoded;

$refFactors = [];
$rfj = (string)($_POST['crop_ref_factors_json'] ?? '[]');
$decoded = json_decode($rfj, true);
if (is_array($decoded)) $refFactors = $decoded;

$runInputs = [];
$rij = (string)($_POST['run_inputs_json'] ?? '[]');
$decoded = json_decode($rij, true);
if (is_array($decoded)) $runInputs = $decoded;

$hasCustomDatasets = false;
foreach ($datasetIds as $datasetId) {
    if (str_starts_with((string)$datasetId, 'custom:')) {
        $hasCustomDatasets = true;
        break;
    }
}

$userId = null;
if ($hasCustomDatasets) {
    Auth::requireLogin();
    $userId = Auth::userId();
    if ($userId === null) {
        bad(401, 'Unauthorized');
    }
}

try {
    $payload = [
        'preset_set_id'     => $presetSetId,
        'crop_code'         => $cropCode,

        'dataset_ids'       => $datasetIds,
        'user_id'           => $userId,

        'preset_items'      => $presetItems,
        'variables'         => $variables,
        'crop_variables'    => $cropVars,
        'crop_ref_factors'  => $refFactors,

        'run_inputs'        => $runInputs,

        'allowed_crop_codes' => $allowedCropCodes,
    ];

    $out = McaComputeService::compute($payload);
    echo json_encode($out);
} catch (InvalidArgumentException $e) {
    bad(400, $e->getMessage());
} catch (Throwable $e) {
    bad(500, $e->getMessage());
}