<?php
// api/custom_scenario_effective_runs.php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CustomScenarioRepository.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'You must be logged in.',
    ]);
    exit;
}

$userId = Auth::userId();
if ($userId === null) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized',
    ]);
    exit;
}

$scenarioId = isset($_GET['scenario_id']) ? (int)$_GET['scenario_id'] : 0;
if ($scenarioId <= 0) {
    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid scenario_id',
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

    $effectiveRunBySub = CustomScenarioRepository::getEffectiveRunMapForUser($scenarioId, $userId);

    echo json_encode([
        'status' => 'ok',
        'scenario' => [
            'id' => (int)$scenario['id'],
            'name' => (string)$scenario['name'],
            'study_area_id' => (int)$scenario['study_area_id'],
        ],
        'effective_run_by_sub' => $effectiveRunBySub,
    ]);
} catch (Throwable $e) {
    error_log('[custom_scenario_effective_runs] ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error',
    ]);
}