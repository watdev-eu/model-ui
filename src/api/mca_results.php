<?php
// src/api/mca_results.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/McaResultRepository.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$presetSetId = (int)($_GET['preset_set_id'] ?? 0);
$cropCode    = trim((string)($_GET['crop_code'] ?? ''));

if ($presetSetId <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'preset_set_id is required']);
    exit;
}

try {
    $rows   = McaResultRepository::byPresetSet($presetSetId, $cropCode !== '' ? $cropCode : null);
    $totals = McaResultRepository::totalsByPresetSet($presetSetId, $cropCode !== '' ? $cropCode : null);

    echo json_encode(['ok' => true, 'rows' => $rows, 'totals' => $totals]);
} catch (Throwable $e) {
    error_log('[mca_results] ' . $e->getMessage());
    http_response_code(500);
    // echo json_encode(['error' => 'Server error']);
    echo json_encode(['error' => $e->getMessage()]);
}