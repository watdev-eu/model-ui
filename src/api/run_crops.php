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
        Auth::requireLogin();
        $userId = Auth::userId();
        if ($userId === null) {
            throw new RuntimeException('Unauthorized');
        }
        $runIds = CustomScenarioRepository::collectEffectiveRunIdsForUser((int)$parsed['id'], $userId);
    }

    $pdo = Database::pdo();
    $cropSet = [];

    foreach ($runIds as $runId) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT h.lulc
            FROM swat_hru_kpi h
            WHERE h.run_id = :run_id
              AND h.lulc IS NOT NULL
              AND h.lulc <> ''
            ORDER BY h.lulc
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
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}