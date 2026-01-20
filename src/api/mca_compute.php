<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/McaComputeService.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$presetSetId = (int)($_POST['preset_set_id'] ?? 0);
$cropCode    = trim((string)($_POST['crop_code'] ?? ''));

if ($presetSetId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'preset_set_id is required']);
    exit;
}

$varsOverrides = null;
$varsJson = trim((string)($_POST['variables_json'] ?? ''));
if ($varsJson !== '') {
    $tmp = json_decode($varsJson, true);
    if (!is_array($tmp)) {
        http_response_code(422);
        echo json_encode(['ok'=>false,'error'=>'variables_json invalid JSON']);
        exit;
    }
    $varsOverrides = $tmp;
}

$cropVarsOverrides = null;
$cropVarsJson = trim((string)($_POST['crop_variables_json'] ?? ''));
if ($cropVarsJson !== '') {
    $tmp = json_decode($cropVarsJson, true);
    if (!is_array($tmp)) {
        http_response_code(422);
        echo json_encode(['ok'=>false,'error'=>'crop_variables_json invalid JSON']);
        exit;
    }
    $cropVarsOverrides = $tmp;
}

try {
    // --- optional: CSRF check if app uses it ---
    // if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    //     http_response_code(403);
    //     echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    //     exit;
    // }

    // --- preset item overrides (weights/direction/enabled) ---
    $overrideItems = null;
    $presetItemsJson = trim((string)($_POST['preset_items_json'] ?? ''));
    if ($presetItemsJson !== '') {
        $tmp = json_decode($presetItemsJson, true);
        if (!is_array($tmp)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'preset_items_json invalid JSON']);
            exit;
        }
        $overrideItems = $tmp;
    }

    $result = McaComputeService::compute(
        $presetSetId,
        $cropCode !== '' ? $cropCode : null,
        $overrideItems,
        $varsOverrides,
        $cropVarsOverrides
    );

    echo json_encode(['ok' => true] + $result);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[mca_compute] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}