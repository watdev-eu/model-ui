<?php
// src/api/hru_kpi.php
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

    // We derive YEAR/MONTH from period_date and alias columns to match the JS names
    $sql = "
        SELECT
            hru         AS \"HRU\",
            sub         AS \"SUB\",
            gis         AS \"HRUGIS\",
            lulc        AS \"LULC\",
            EXTRACT(YEAR  FROM period_date)::int AS \"YEAR\",
            EXTRACT(MONTH FROM period_date)::int AS \"MON\",
            area_km2    AS \"AREAkm2\",

            irr_mm      AS \"IRRmm\",
            irr_sa_mm   AS \"SA_IRRmm\",
            irr_da_mm   AS \"DA_IRRmm\",

            yld_t_ha    AS \"YLDt_ha\",
            biom_t_ha   AS \"BIOMt_ha\",
            syld_t_ha   AS \"SYLDt_ha\",

            nup_kg_ha   AS \"NUP_kg_ha\",
            pup_kg_ha   AS \"PUPkg_ha\",
            no3l_kg_ha  AS \"NO3Lkg_ha\",

            n_app_kg_ha AS \"N_APPkg_ha\",
            p_app_kg_ha AS \"P_APPkg_ha\",
            nauto_kg_ha AS \"N_AUTOkg_ha\",
            pauto_kg_ha AS \"P_AUTOkg_ha\",
            ngraz_kg_ha AS \"NGRZkg_ha\",
            pgraz_kg_ha AS \"PGRZkg_ha\",
            cfertn_kg_ha AS \"NCFRTkg_ha\",
            cfertp_kg_ha AS \"PCFRTkg_ha\"

        FROM swat_hru_kpi
        WHERE run_id = :run_id
        ORDER BY sub, lulc, hru, period_date
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':run_id' => $runId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['rows' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}