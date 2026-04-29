<?php
// api/custom_scenario_delete.php

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
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

$scenarioId = (int)($data['id'] ?? 0);
if ($scenarioId <= 0) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid scenario id']);
    exit;
}

try {
    $deleted = CustomScenarioRepository::delete($scenarioId, $userId);

    if (!$deleted) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Scenario not found']);
        exit;
    }

    echo json_encode([
        'status' => 'ok',
        'message' => 'Scenario deleted successfully',
    ]);
} catch (Throwable $e) {
    error_log('[custom_scenario_delete] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error',
    ]);
}