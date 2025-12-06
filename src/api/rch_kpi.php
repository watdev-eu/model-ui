<?php
// src/api/rch_kpi.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Validate run_id
$runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : 0;
if ($runId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'run_id is required and must be a positive integer']);
    exit;
}

try {
    $pdo = Database::pdo();

    // Derive YEAR / MON from period_date and alias to "SWAT-style" column names
    $sql = "
        SELECT
            sub                                      AS \"SUB\",
            EXTRACT(YEAR  FROM period_date)::int     AS \"YEAR\",
            EXTRACT(MONTH FROM period_date)::int     AS \"MON\",
            area_km2                                 AS \"AREAkm2\",
            flow_out_cms                             AS \"FLOW_OUTcms\",
            no3_out_kg                               AS \"NO3_OUTkg\",
            sed_out_t                                AS \"SED_OUTtons\"
        FROM swat_rch_kpi
        WHERE run_id = :run_id
        ORDER BY sub, period_date
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':run_id' => $runId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['rows' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}