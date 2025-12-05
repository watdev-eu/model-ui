<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
//require_admin();

// Make sure we don't leak warnings/HTML into the JSON response
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Start output buffering so we can wipe any accidental output
if (!ob_get_level()) {
    ob_start();
}

header('Content-Type: application/json');

$pdo = Database::pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ------------------------------------------------------------------
// 1. Read & validate metadata
// ------------------------------------------------------------------
$studyArea  = strtolower(trim($_POST['study_area'] ?? ''));   // egypt | ethiopia | sudan
$runLabel   = trim($_POST['run_label'] ?? '');                // scenario name
$runDateRaw = trim($_POST['run_date'] ?? '');
$visibility = $_POST['visibility'] ?? 'private';
$description = trim($_POST['description'] ?? '');

// basic required fields
if (!$studyArea || !$runLabel) {
    http_response_code(400);
    echo json_encode(['error' => 'study_area and run_label are required']);
    exit;
}

// normalise visibility
if (!in_array($visibility, ['private', 'public'], true)) {
    $visibility = 'private';
}

// normalise run_date (Y-m-d) or null
$runDate = null;
if ($runDateRaw !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $runDateRaw);
    if ($dt !== false) {
        $runDate = $dt->format('Y-m-d');
    }
}

// CSV format options (with safe defaults)
$fieldSep = $_POST['field_sep'] ?? ';';

if (!in_array($fieldSep, [';', ',', "\t", '\\t'], true)) {
    $fieldSep = ';';
}
if ($fieldSep === '\\t') {
    $fieldSep = "\t";
}

// Auto-decide decimal separator:
// - If field separator is comma, assume decimals are dots (standard CSV).
// - Otherwise (semicolon or tab), assume European-style comma decimals.
$decimalSep = ($fieldSep === ',') ? '.' : ',';

$uploadDir = UPLOAD_DIR;
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

$in = [
    'hru_csv' => $_FILES['hru_csv']['tmp_name'] ?? ($_POST['hru_csv_path'] ?? null),
    'rch_csv' => $_FILES['rch_csv']['tmp_name'] ?? ($_POST['rch_csv_path'] ?? null),
    'snu_csv' => $_FILES['snu_csv']['tmp_name'] ?? ($_POST['snu_csv_path'] ?? null),
];

// Helper to normalize decimal separator in a copy of the file
function normalize_decimal_if_needed(string $srcPath, string $decimalSep, string $fieldSep): string
{
    if (!is_file($srcPath)) {
        throw new RuntimeException('CSV file not found: ' . $srcPath);
    }
    return $srcPath;
}

function pgNumericExpr(string $col, string $decimalSep, string $fieldSep): string
{
    // For semicolon/tab + comma-decimals: convert comma → dot, then cast
    if ($decimalSep === ',' && $fieldSep !== ',') {
        return "NULLIF(REPLACE($col, ',', '.'), '')::double precision";
    }

    // For standard dot-decimals
    return "NULLIF($col, '')::double precision";
}

// Build case-insensitive header index
function makeHeaderIndex(array $header): array
{
    $idx = [];
    foreach ($header as $i => $name) {
        $name = trim($name);
        if ($name === '') continue;
        // strip BOM on first column if present
        if ($i === 0) {
            $name = preg_replace('/^\xEF\xBB\xBF/', '', $name);
        }
        $idx[strtoupper($name)] = $i;
    }
    return $idx;
}

// Ensure required columns are present
function assertRequiredColumns(array $headerIndex, array $required, string $label): void
{
    $missing = [];
    foreach ($required as $col) {
        if (!array_key_exists(strtoupper($col), $headerIndex)) {
            $missing[] = $col;
        }
    }
    if ($missing) {
        throw new RuntimeException(
            "Missing required columns in {$label} CSV: " . implode(', ', $missing)
        );
    }
}

$runId = null;

try {
    $pdo->beginTransaction();
    $pdo->exec("SET LOCAL synchronous_commit = OFF;");
    $pdo->exec("SET LOCAL temp_buffers = '64MB';");

    // ------------------------------------------------------------------
    // 2. Check uniqueness within study area
    // ------------------------------------------------------------------
    $sel = $pdo->prepare("SELECT id FROM swat_runs WHERE study_area = ? AND run_label = ?");
    $sel->execute([$studyArea, $runLabel]);
    if ($sel->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'A run with this scenario name already exists for this study area; choose a different name']);
        exit;
    }

    // ------------------------------------------------------------------
    // 3. Create run
    // ------------------------------------------------------------------
    $ins = $pdo->prepare("
        INSERT INTO swat_runs
          (study_area, run_label, run_date,
           visibility, description, created_by,
           period_start, period_end, time_step)
        VALUES (?,?,?,?,?,?,?,?,?)
        RETURNING id;
    ");

    $ins->execute([
        $studyArea,
        $runLabel,
        $runDate,          // user-specified run date (or null)
        $visibility,       // 'private' | 'public'
        $description !== '' ? $description : null,
        null,              // created_by (will hold user id later)
        null,              // period_start (will be updated from KPI)
        null,              // period_end   (will be updated from KPI)
        'MONTHLY',         // nominal time_step; we still store DAILY in SNU
    ]);
    $runId = (int)$ins->fetchColumn();

    // ------------------------------------------------------------------
    // 4. Copy uploaded files to a safe place
    // ------------------------------------------------------------------
    $csv = ['hru' => null, 'rch' => null, 'snu' => null];

    if ($in['hru_csv']) {
        $csv['hru'] = $uploadDir . '/hru_' . uniqid() . '.csv';
        // move_uploaded_file returns false if source is not an uploaded file
        if (!@move_uploaded_file($in['hru_csv'], $csv['hru'])) {
            @copy($in['hru_csv'], $csv['hru']);
        }
    }
    if ($in['rch_csv']) {
        $csv['rch'] = $uploadDir . '/rch_' . uniqid() . '.csv';
        if (!@move_uploaded_file($in['rch_csv'], $csv['rch'])) {
            @copy($in['rch_csv'], $csv['rch']);
        }
    }
    if ($in['snu_csv']) {
        $csv['snu'] = $uploadDir . '/snu_' . uniqid() . '.csv';
        if (!@move_uploaded_file($in['snu_csv'], $csv['snu'])) {
            @copy($in['snu_csv'], $csv['snu']);
        }
    }

    if (!$csv['hru'] && !$csv['rch'] && !$csv['snu']) {
        throw new RuntimeException('No input CSV files provided (HRU/RCH/SNU).');
    }

    // ------------------------------------------------------------------
    // 5. HRU KPI via COPY + staging
    // ------------------------------------------------------------------
    if ($csv['hru']) {
        $path = normalize_decimal_if_needed($csv['hru'], $decimalSep, $fieldSep);

        // 5.0 Header check (same style as RCH/SNU)
        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new RuntimeException('Failed to open HRU CSV.');
        }
        $header = fgetcsv($fh, 0, $fieldSep);
        fclose($fh);
        if ($header === false) {
            throw new RuntimeException('HRU CSV appears to be empty.');
        }

        $idx = makeHeaderIndex($header);
        $requiredHru = [
            'LULC', 'HRU', 'HRUGIS', 'SUB', 'YEAR',
            'MON', 'AREAkm2', 'IRRmm', 'SA_IRRmm',
            'DA_IRRmm', 'YLDt_ha', 'BIOMt_ha', 'SYLDt_ha',
            'NUP_kg_ha', 'PUPkg_ha', 'NO3Lkg_ha',
            'N_APPkg_ha', 'P_APPkg_ha', 'N_AUTOkg_ha',
            'P_AUTOkg_ha', 'NGRZkg_ha', 'PGRZkg_ha',
            'NCFRTkg_ha', 'PCFRTkg_ha'
        ];
        assertRequiredColumns($idx, $requiredHru, 'HRU');

        // 5.1. Create staging table with full SWAT HRU layout (UNLOGGED = faster)
        $pdo->exec("
            DROP TABLE IF EXISTS tmp_hru_import;
            CREATE UNLOGGED TABLE tmp_hru_import (
                LULC        VARCHAR(16),
                HRU         INTEGER,
                HRUGIS      INTEGER,
                SUB         INTEGER,
                YEAR        INTEGER,
                MON         INTEGER,
                AREAkm2     TEXT,
                PRECIPmm    TEXT,
                SNOWFALLmm  TEXT,
                SNOWMELTmm  TEXT,
                IRRmm       TEXT,
                PETmm       TEXT,
                ETmm        TEXT,
                SW_INITmm   TEXT,
                SW_ENDmm    TEXT,
                PERCmm      TEXT,
                GW_RCHGmm   TEXT,
                DA_RCHGmm   TEXT,
                REVAPmm     TEXT,
                SA_IRRmm    TEXT,
                DA_IRRmm    TEXT,
                SA_STmm     TEXT,
                DA_STmm     TEXT,
                SURQ_GENmm  TEXT,
                SURQ_CNTmm  TEXT,
                TLOSS_mm    TEXT,
                LATQ_mm     TEXT,
                GW_Qmm      TEXT,
                WYLD_Qmm    TEXT,
                DAILYCN     TEXT,
                TMP_AVdgC   TEXT,
                TMP_MXdgC   TEXT,
                TMP_MNdgC   TEXT,
                SOL_TMPdgC  TEXT,
                SOLARmj_m2  TEXT,
                SYLDt_ha    TEXT,
                USLEt_ha    TEXT,
                N_APPkg_ha  TEXT,
                P_APPkg_ha  TEXT,
                N_AUTOkg_ha TEXT,
                P_AUTOkg_ha TEXT,
                NGRZkg_ha   TEXT,
                PGRZkg_ha   TEXT,
                NCFRTkg_ha  TEXT,
                PCFRTkg_ha  TEXT,
                NRAINkg_ha  TEXT,
                NFIXkg_ha   TEXT,
                F_MNkg_ha   TEXT,
                A_MNkg_ha   TEXT,
                A_SNkg_ha   TEXT,
                F_MPkg_aha  TEXT,
                AO_LPkg_ha  TEXT,
                L_APkg_ha   TEXT,
                A_SPkg_ha   TEXT,
                DNITkg_ha   TEXT,
                NUP_kg_ha   TEXT,
                PUPkg_ha    TEXT,
                ORGNkg_ha   TEXT,
                ORGPkg_ha   TEXT,
                SEDPkg_h    TEXT,
                NSURQkg_ha  TEXT,
                NLATQkg_ha  TEXT,
                NO3Lkg_ha   TEXT,
                NO3GWkg_ha  TEXT,
                SOLPkg_ha   TEXT,
                P_GWkg_ha   TEXT,
                W_STRS      TEXT,
                TMP_STRS    TEXT,
                N_STRS      TEXT,
                P_STRS      TEXT,
                BIOMt_ha    TEXT,
                LAI         TEXT,
                YLDt_ha     TEXT,
                BACTPct     TEXT,
                BACTLPct    TEXT,
                WATB_CLI    TEXT,
                WATB_SOL    TEXT,
                SNOmm       TEXT,
                CMUPkg_ha   TEXT,
                CMTOTkg_ha  TEXT,
                QTILEmm     TEXT,
                TNO3kg_ha   TEXT,
                LNO3kg_ha   TEXT,
                YYYYMM      INTEGER
            );
        ");

        // 5.2. COPY from CSV into staging table
        $sep = ($fieldSep === "\t") ? "\t" : $fieldSep;
        $copySql = "
        COPY tmp_hru_import FROM " . $pdo->quote($path) . " WITH (
            FORMAT csv,
            HEADER true,
            DELIMITER '$sep'
        )
    ";
        $pdo->exec($copySql);

        // 5.3. Insert into final table with conversion and filtering
        $areaExpr   = pgNumericExpr('AREAkm2',    $decimalSep, $fieldSep);
        $irrExpr    = pgNumericExpr('IRRmm',      $decimalSep, $fieldSep);
        $saIrrExpr  = pgNumericExpr('SA_IRRmm',   $decimalSep, $fieldSep);
        $daIrrExpr  = pgNumericExpr('DA_IRRmm',   $decimalSep, $fieldSep);
        $yldExpr    = pgNumericExpr('YLDt_ha',    $decimalSep, $fieldSep);
        $biomExpr   = pgNumericExpr('BIOMt_ha',   $decimalSep, $fieldSep);
        $syldExpr   = pgNumericExpr('SYLDt_ha',   $decimalSep, $fieldSep);
        $nupExpr    = pgNumericExpr('NUP_kg_ha',  $decimalSep, $fieldSep);
        $pupExpr    = pgNumericExpr('PUPkg_ha',   $decimalSep, $fieldSep);
        $no3lExpr   = pgNumericExpr('NO3Lkg_ha',  $decimalSep, $fieldSep);
        $nAppExpr   = pgNumericExpr('N_APPkg_ha', $decimalSep, $fieldSep);
        $pAppExpr   = pgNumericExpr('P_APPkg_ha', $decimalSep, $fieldSep);
        $nAutoExpr  = pgNumericExpr('N_AUTOkg_ha',$decimalSep, $fieldSep);
        $pAutoExpr  = pgNumericExpr('P_AUTOkg_ha',$decimalSep, $fieldSep);
        $nGrazExpr  = pgNumericExpr('NGRZkg_ha',  $decimalSep, $fieldSep);
        $pGrazExpr  = pgNumericExpr('PGRZkg_ha',  $decimalSep, $fieldSep);
        $nCfrtExpr  = pgNumericExpr('NCFRTkg_ha', $decimalSep, $fieldSep);
        $pCfrtExpr  = pgNumericExpr('PCFRTkg_ha', $decimalSep, $fieldSep);

        $sql = "
            INSERT INTO swat_hru_kpi (
                run_id, hru, sub, gis, lulc,
                period_date, period_res,
                area_km2, irr_mm, irr_sa_mm, irr_da_mm,
                yld_t_ha, biom_t_ha, syld_t_ha,
                nup_kg_ha, pup_kg_ha, no3l_kg_ha,
                n_app_kg_ha, p_app_kg_ha,
                nauto_kg_ha, pauto_kg_ha,
                ngraz_kg_ha, pgraz_kg_ha,
                cfertn_kg_ha, cfertp_kg_ha
            )
            SELECT
                $runId                         AS run_id,
                HRU                            AS hru,
                SUB                            AS sub,
                HRUGIS                         AS gis,
                LULC                           AS lulc,
                make_date(YEAR, MON, 1)        AS period_date,
                'MONTHLY'::period_res_enum     AS period_res,
                $areaExpr                      AS area_km2,
                $irrExpr                       AS irr_mm,
                $saIrrExpr                     AS irr_sa_mm,
                $daIrrExpr                     AS irr_da_mm,
                $yldExpr                       AS yld_t_ha,
                $biomExpr                      AS biom_t_ha,
                $syldExpr                      AS syld_t_ha,
                $nupExpr                       AS nup_kg_ha,
                $pupExpr                       AS pup_kg_ha,
                $no3lExpr                      AS no3l_kg_ha,
                $nAppExpr                      AS n_app_kg_ha,
                $pAppExpr                      AS p_app_kg_ha,
                $nAutoExpr                     AS nauto_kg_ha,
                $pAutoExpr                     AS pauto_kg_ha,
                $nGrazExpr                     AS ngraz_kg_ha,
                $pGrazExpr                     AS pgraz_kg_ha,
                $nCfrtExpr                     AS cfertn_kg_ha,
                $pCfrtExpr                     AS cfertp_kg_ha
            FROM tmp_hru_import
            WHERE YEAR > 0 AND MON > 0;
        ";
        $pdo->exec($sql);

        // 5.4. Cleanup staging
        $pdo->exec("DROP TABLE tmp_hru_import");
    }

    // ------------------------------------------------------------------
    // 6. RCH KPI via COPY + staging
    // ------------------------------------------------------------------
    if ($csv['rch']) {
        $path = normalize_decimal_if_needed($csv['rch'], $decimalSep, $fieldSep);

        // Optional: quick header check (reuses your existing helper)
        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new RuntimeException('Failed to open RCH CSV.');
        }
        $header = fgetcsv($fh, 0, $fieldSep);
        fclose($fh);
        if ($header === false) {
            throw new RuntimeException('RCH CSV appears to be empty.');
        }
        $idx = makeHeaderIndex($header);
        $requiredRch = [
            'SUB', 'YEAR', 'MON', 'AREAkm2',
            'FLOW_OUTcms', 'NO3_OUTkg', 'SED_OUTtons'
        ];
        assertRequiredColumns($idx, $requiredRch, 'RCH');

        // 6.1. Create staging table (UNLOGGED = faster)
        $pdo->exec("
            DROP TABLE IF EXISTS tmp_rch_import;
            CREATE UNLOGGED TABLE tmp_rch_import (
                SUB           INTEGER,
                YEAR          INTEGER,
                MON           INTEGER,
                AREAkm2       TEXT,
                FLOW_INcms    TEXT,
                FLOW_OUTcms   TEXT,
                EVAPcms       TEXT,
                TLOSScms      TEXT,
                SED_INtons    TEXT,
                SED_OUTtons   TEXT,
                SEDCONCmg_kg  TEXT,
                ORGN_INkg     TEXT,
                ORGN_OUTkg    TEXT,
                ORGP_INkg     TEXT,
                ORGP_OUTkg    TEXT,
                NO3_INkg      TEXT,
                NO3_OUTkg     TEXT,
                NH4_INkg      TEXT,
                NH4_OUTkg     TEXT,
                NO2_INkg      TEXT,
                NO2_OUTkg     TEXT,
                MINP_INkg     TEXT,
                MINP_OUTkg    TEXT,
                CHLA_INkg     TEXT,
                CHLA_OUTkg    TEXT,
                CBOD_INkg     TEXT,
                CBOD_OUTkg    TEXT,
                DISOX_INkg    TEXT,
                DISOX_OUTkg   TEXT,
                SOLPST_INmg   TEXT,
                SOLPST_OUTmg  TEXT,
                SORPST_INmg   TEXT,
                SORPST_OUTmg  TEXT,
                REACTPTmg     TEXT,
                VOLPSTmg      TEXT,
                SETTLPST_mg   TEXT,
                RESUSP_PSTmg  TEXT,
                DIFUSEPSTmg   TEXT,
                REACHBEDPSTmg TEXT,
                BURYPSTmg     TEXT,
                BED_PSTmg     TEXT,
                BACTP_OUTct   TEXT,
                BACTLP_OUTct  TEXT,
                CMETAL1kg     TEXT,
                CMETAL2kg     TEXT,
                CMETAL3kg     TEXT,
                TOT_Nkg       TEXT,
                TOT_Pkg       TEXT,
                NO3CONCmg_l   TEXT,
                WTMPdegc      TEXT,
                YYYYMM        INTEGER
            );
        ");

        // 6.2. COPY CSV into staging table
        $sep = $fieldSep === "\t" ? '\t' : $fieldSep;
        $copySql = "
            COPY tmp_rch_import FROM " . $pdo->quote($path) . " WITH (
                FORMAT csv,
                HEADER true,
                DELIMITER '$sep'
            )
        ";
        $pdo->exec($copySql);

        // 6.3. Insert into final table with conversions
        $areaExpr = pgNumericExpr('AREAkm2',      $decimalSep, $fieldSep);
        $flowExpr = pgNumericExpr('FLOW_OUTcms',  $decimalSep, $fieldSep);
        $no3Expr  = pgNumericExpr('NO3_OUTkg',    $decimalSep, $fieldSep);
        $sedExpr  = pgNumericExpr('SED_OUTtons',  $decimalSep, $fieldSep);

        $sql = "
            INSERT INTO swat_rch_kpi (
                run_id, rch, sub, area_km2,
                period_date, period_res,
                flow_out_cms, no3_out_kg, sed_out_t
            )
            SELECT
                $runId                         AS run_id,
                SUB                            AS rch,
                SUB                            AS sub,
                $areaExpr                      AS area_km2,
                make_date(YEAR, MON, 1)        AS period_date,
                'MONTHLY'::period_res_enum     AS period_res,
                $flowExpr                      AS flow_out_cms,
                $no3Expr                       AS no3_out_kg,
                $sedExpr                       AS sed_out_t
            FROM tmp_rch_import
            WHERE YEAR > 0 AND MON > 0;
        ";
        $pdo->exec($sql);

        // 6.4. Cleanup staging
        $pdo->exec("DROP TABLE tmp_rch_import");
    }

    // ------------------------------------------------------------------
    // 7. SNU KPI via COPY + staging
    // ------------------------------------------------------------------
    if ($csv['snu']) {
        $path = normalize_decimal_if_needed($csv['snu'], $decimalSep, $fieldSep);

        // Optional header check
        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new RuntimeException('Failed to open SNU CSV.');
        }
        $header = fgetcsv($fh, 0, $fieldSep);
        fclose($fh);
        if ($header === false) {
            throw new RuntimeException('SNU CSV appears to be empty.');
        }
        $idx = makeHeaderIndex($header);
        $requiredSnu = [
            'YEAR', 'DAY', 'HRUGIS', 'SOL_RSD',
            'SOL_P', 'NO3', 'ORG_N', 'ORG_P', 'CN'
        ];
        assertRequiredColumns($idx, $requiredSnu, 'SNU');

        // 7.1. Create staging table
        $pdo->exec("
            DROP TABLE IF EXISTS tmp_snu_import;
            CREATE UNLOGGED TABLE tmp_snu_import (
                YEAR     INTEGER,
                DAY      INTEGER,
                HRUGIS   INTEGER,
                SOL_RSD  TEXT,
                SOL_P    TEXT,
                NO3      TEXT,
                ORG_N    TEXT,
                ORG_P    TEXT,
                CN       TEXT,
                YYYYDDD  INTEGER
            );
        ");

        // 7.2. COPY CSV into staging
        $sep = $fieldSep === "\t" ? '\t' : $fieldSep;
        $copySql = "
            COPY tmp_snu_import FROM " . $pdo->quote($path) . " WITH (
                FORMAT csv,
                HEADER true,
                DELIMITER '$sep'
            )
        ";
        $pdo->exec($copySql);

        // 7.3. Insert into final table with conversions + DOY→date
        $solPExpr   = pgNumericExpr('SOL_P',   $decimalSep, $fieldSep);
        $no3Expr    = pgNumericExpr('NO3',     $decimalSep, $fieldSep);
        $orgNExpr   = pgNumericExpr('ORG_N',   $decimalSep, $fieldSep);
        $orgPExpr   = pgNumericExpr('ORG_P',   $decimalSep, $fieldSep);
        $cnExpr     = pgNumericExpr('CN',      $decimalSep, $fieldSep);
        $solRsdExpr = pgNumericExpr('SOL_RSD', $decimalSep, $fieldSep);

        $sql = "
            INSERT INTO swat_snu_kpi (
                run_id, gisnum, period_date, period_res,
                sol_p, no3, org_n, org_p, cn, sol_rsd
            )
            SELECT
                $runId AS run_id,
                HRUGIS AS gisnum,
                (
                  make_date(YEAR, 1, 1)
                  + (DAY - 1) * INTERVAL '1 day'
                )::date                    AS period_date,
                'DAILY'::period_res_enum   AS period_res,
                $solPExpr                  AS sol_p,
                $no3Expr                   AS no3,
                $orgNExpr                  AS org_n,
                $orgPExpr                  AS org_p,
                $cnExpr                    AS cn,
                $solRsdExpr                AS sol_rsd
            FROM tmp_snu_import
            WHERE YEAR > 0 AND DAY > 0;
        ";
        $pdo->exec($sql);

        // 7.4. Cleanup staging
        $pdo->exec("DROP TABLE tmp_snu_import");
    }

    // ------------------------------------------------------------------
    // 8. Update period_start / period_end
    // ------------------------------------------------------------------
    $stats = $pdo->prepare("
        SELECT MIN(period_date) AS min_d, MAX(period_date) AS max_d
        FROM (
            SELECT period_date FROM swat_hru_kpi WHERE run_id = :id
            UNION ALL
            SELECT period_date FROM swat_rch_kpi WHERE run_id = :id
            UNION ALL
            SELECT period_date FROM swat_snu_kpi WHERE run_id = :id
        ) AS u
    ");
    $stats->execute([':id' => $runId]);
    $range = $stats->fetch(PDO::FETCH_ASSOC);

    if ($range && $range['min_d']) {
        $upd = $pdo->prepare("UPDATE swat_runs SET period_start = ?, period_end = ? WHERE id = ?");
        $upd->execute([$range['min_d'], $range['max_d'], $runId]);
    }

    $pdo->commit();

    // Clean any previous output, then send clean JSON
    if (ob_get_level()) {
        ob_clean();
    }
    echo json_encode(['ok' => true, 'run_id' => $runId]);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Log server-side for debugging
    error_log('[import_run] ' . $e->getMessage());

    if (ob_get_level()) {
        ob_clean();
    }
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}