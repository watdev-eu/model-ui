<?php
// src/api/mca_compute.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/../classes/McaComputeService.php';

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
$presetSetId = isset($_POST['preset_set_id']) ? (int)$_POST['preset_set_id'] : 0;
if ($presetSetId <= 0) bad(400, 'preset_set_id is required');

$cropCode = isset($_POST['crop_code']) && $_POST['crop_code'] !== '' ? (string)$_POST['crop_code'] : null;

$runIds = [];
$runIdsJson = (string)($_POST['run_ids_json'] ?? '[]');
$decoded = json_decode($runIdsJson, true);
if (is_array($decoded)) $runIds = $decoded;

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

try {
    $payload = [
        'preset_set_id'     => $presetSetId,
        'crop_code'         => $cropCode,

        'run_ids'           => $runIds,

        'preset_items'      => $presetItems,
        'variables'         => $variables,
        'crop_variables'    => $cropVars,
        'crop_ref_factors'  => $refFactors,

        'run_inputs'        => $runInputs,
    ];

    $out = McaComputeService::compute($payload);
    echo json_encode($out);
} catch (InvalidArgumentException $e) {
    bad(400, $e->getMessage());
} catch (Throwable $e) {
    bad(500, $e->getMessage());
}