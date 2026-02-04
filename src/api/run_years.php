<?php
// src/api/run_years.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/SwatResultsRepository.php';

header('Content-Type: application/json');

$runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : 0;
if ($runId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'run_id is required']);
    exit;
}

try {
    $pdo = Database::pdo();

    // 1) Prefer HRU (monthly)
    $stmt = $pdo->prepare("
        SELECT DISTINCT EXTRACT(YEAR FROM h.period_date)::int AS year
        FROM swat_hru_kpi h
        WHERE h.run_id = :run_id
          AND h.period_res = 'MONTHLY'
        ORDER BY year
    ");
    $stmt->execute([':run_id' => $runId]);
    $years = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 2) Fallback to SNU (end-of-month rows only)
    if (!$years) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT EXTRACT(YEAR FROM s.period_date)::int AS year
            FROM swat_snu_kpi s
            WHERE s.run_id = :run_id
              AND s.period_date = (date_trunc('month', s.period_date) + interval '1 month - 1 day')::date
            ORDER BY year
        ");
        $stmt->execute([':run_id' => $runId]);
        $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // 3) Fallback to RCH (if applicable + has rows)
    if (!$years && SwatResultsRepository::runHasRchEnabled($runId) && SwatResultsRepository::runHasRchRows($runId)) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT EXTRACT(YEAR FROM r.period_date)::int AS year
            FROM swat_rch_kpi r
            WHERE r.run_id = :run_id
              AND r.period_res = 'MONTHLY'
            ORDER BY year
        ");
        $stmt->execute([':run_id' => $runId]);
        $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $years = array_values(array_filter(array_map('intval', $years), fn($y) => $y > 0));
    echo json_encode(['status' => 'ok', 'run_id' => $runId, 'years' => $years]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}