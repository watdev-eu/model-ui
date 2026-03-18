<?php
// api/custom_scenario_get.php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CustomScenarioRepository.php';

header('Content-Type: application/json; charset=utf-8');

Auth::requireLogin();

$userId = Auth::userId();
if ($userId === null) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized',
    ]);
    exit;
}

$scenarioId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($scenarioId <= 0) {
    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid scenario id',
    ]);
    exit;
}

try {
    $scenario = CustomScenarioRepository::findByIdForUser($scenarioId, $userId);
    if (!$scenario) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Scenario not found',
        ]);
        exit;
    }

    $assignments = CustomScenarioRepository::findAssignments($scenarioId, $userId);

    echo json_encode([
        'status' => 'ok',
        'scenario' => [
            'id' => (int)$scenario['id'],
            'study_area_id' => (int)$scenario['study_area_id'],
            'name' => (string)$scenario['name'],
            'description' => (string)($scenario['description'] ?? ''),
            'created_at' => (string)$scenario['created_at'],
            'updated_at' => (string)$scenario['updated_at'],
            'assignments' => $assignments,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ]);
}