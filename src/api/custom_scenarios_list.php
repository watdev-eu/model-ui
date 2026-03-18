<?php
// api/custom_scenarios_list.php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CustomScenarioRepository.php';

header('Content-Type: application/json; charset=utf-8');

Auth::requireLogin();

$userId = Auth::userId();
if ($userId === null) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$studyAreaId = isset($_GET['study_area_id']) ? (int)$_GET['study_area_id'] : 0;
if ($studyAreaId <= 0) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid study_area_id']);
    exit;
}

try {
    $rows = CustomScenarioRepository::listByStudyAreaForUser($studyAreaId, $userId);

    echo json_encode([
        'status' => 'ok',
        'scenarios' => $rows,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ]);
}