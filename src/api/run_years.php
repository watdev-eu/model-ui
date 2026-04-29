<?php
// src/api/run_years.php

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
    $yearSet = [];

    foreach ($runIds as $runId) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT year
            FROM swat_indicator_yearly
            WHERE run_id = :run_id
            ORDER BY year
        ");
        $stmt->execute([':run_id' => $runId]);
        $years = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($years as $y) {
            $y = (int)$y;
            if ($y > 0) {
                $yearSet[$y] = true;
            }
        }
    }

    $years = array_keys($yearSet);
    sort($years);

    echo json_encode([
        'status' => 'ok',
        'run_id' => $rawRunId,
        'years'  => array_map('intval', $years),
    ]);
} catch (Throwable $e) {
    error_log('[run_years] ' . $e->getMessage());

    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}