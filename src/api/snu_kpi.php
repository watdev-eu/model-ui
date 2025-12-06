<?php
// src/api/snu_kpi.php
declare(strict_types=0);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json');

$runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : 0;
if ($runId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'run_id is required and must be a positive integer']);
    exit;
}

try {
    $pdo = Database::pdo();

    // Aggregate to ANNUAL MEAN per HRUGIS × YEAR
    $sql = "
        SELECT
            gisnum                                       AS \"HRUGIS\",
            EXTRACT(YEAR FROM period_date)::int          AS \"YEAR\",
            AVG(sol_p)      AS \"SOL_P\",
            AVG(no3)        AS \"NO3\",
            AVG(org_n)      AS \"ORG_N\",
            AVG(org_p)      AS \"ORG_P\",
            AVG(cn)         AS \"CN\",
            AVG(sol_rsd)    AS \"SOL_RSD\"
        FROM swat_snu_kpi
        WHERE run_id = :id
        GROUP BY gisnum, EXTRACT(YEAR FROM period_date)::int
        ORDER BY gisnum, EXTRACT(YEAR FROM period_date)::int
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $runId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['rows' => $rows]);
    exit;

} catch (Throwable $e) {
    error_log('[snu_kpi] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}