<?php
// src/api/run_crops.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : 0;
if ($runId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'run_id is required']);
    exit;
}

try {
    $pdo = Database::pdo();

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

    $crops = array_values(array_filter(array_map('strval', $crops), fn($c) => $c !== '' && $c !== 'NULL' && $c !== '-'));
    echo json_encode(['status' => 'ok', 'run_id' => $runId, 'crops' => $crops]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}