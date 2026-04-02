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
        Auth::requireLogin();
        $userId = Auth::userId();
        if ($userId === null) {
            throw new RuntimeException('Unauthorized');
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
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}