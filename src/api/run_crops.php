<?php
// src/api/run_crops.php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CustomScenarioRepository.php';
require_once __DIR__ . '/../classes/DashboardDatasetKey.php';

header('Content-Type: application/json');

$rawRunId = trim((string)($_GET['run_id'] ?? ''));
if ($rawRunId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'run_id is required']);
    exit;
}

try {
    $parsed = DashboardDatasetKey::parse($rawRunId);

    $runIds = [];
    if ($parsed['type'] === 'run') {
        $runIds = [(int)$parsed['id']];
    } else {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!Auth::isLoggedIn()) {
            http_response_code(401);
            echo json_encode(['error' => 'You must be logged in.']);
            exit;
        }

        $userId = Auth::userId();
        if ($userId === null) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        $runIds = CustomScenarioRepository::collectEffectiveRunIdsForUser((int)$parsed['id'], $userId);
    }

    $pdo = Database::pdo();
    $cropSet = [];

    foreach ($runIds as $runId) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT crop
            FROM swat_crop_area_context
            WHERE run_id = :run_id
              AND crop IS NOT NULL
              AND crop <> ''
            ORDER BY crop
        ");
        $stmt->execute([':run_id' => $runId]);
        $crops = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($crops as $crop) {
            $crop = (string)$crop;
            if ($crop !== '' && $crop !== 'NULL' && $crop !== '-') {
                $cropSet[$crop] = true;
            }
        }
    }

    $crops = array_keys($cropSet);
    sort($crops);

    echo json_encode([
        'status' => 'ok',
        'run_id' => $rawRunId,
        'crops'  => $crops,
    ]);
} catch (Throwable $e) {
    error_log('[run_crops] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}