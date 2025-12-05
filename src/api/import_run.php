<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
//require_admin();

header('Content-Type: application/json');

$pdo = Database::pdo();
$pdo->setAttribute(PDO::MYSQL_ATTR_LOCAL_INFILE, true);
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
    // If fieldSep is ',', we can't tell decimal commas from separators, so we don't touch the file.
    if ($decimalSep !== ',' || $fieldSep === ',') {
        return $srcPath;
    }

    $normalized = $srcPath . '.norm';

    $in  = fopen($srcPath, 'rb');
    $out = fopen($normalized, 'wb');
    if (!$in || !$out) {
        throw new RuntimeException('Failed to open CSV for normalization.');
    }

    while (!feof($in)) {
        $chunk = fread($in, 8192);
        if ($chunk === false) {
            break;
        }

        // Replace only commas that look like decimal separators: digit , digit
        $chunk = preg_replace('/(?<=\d),(?=\d)/', '.', $chunk);

        fwrite($out, $chunk);
    }

    fclose($in);
    fclose($out);

    return $normalized;
}

$runId = null;

try {
    // ------------------------------------------------------------------
    // 2. Check uniqueness within study area
    // ------------------------------------------------------------------
    $sel = $pdo->prepare("SELECT id FROM swat_runs WHERE study_area = ? AND run_label = ?");
    $sel->execute([$studyArea, $runLabel]);
    if ($sel->fetchColumn()) {
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

    $runId = (int)$pdo->lastInsertId();

    // ------------------------------------------------------------------
    // 4. Copy uploaded files to a safe place
    // ------------------------------------------------------------------
    $csv = ['hru' => null, 'rch' => null, 'snu' => null];

    if ($in['hru_csv']) {
        $csv['hru'] = $uploadDir . '/hru_' . uniqid() . '.csv';
        move_uploaded_file($in['hru_csv'], $csv['hru']);
    }
    if ($in['rch_csv']) {
        $csv['rch'] = $uploadDir . '/rch_' . uniqid() . '.csv';
        move_uploaded_file($in['rch_csv'], $csv['rch']);
    }
    if ($in['snu_csv']) {
        $csv['snu'] = $uploadDir . '/snu_' . uniqid() . '.csv';
        move_uploaded_file($in['snu_csv'], $csv['snu']);
    }

    if (!$csv['hru'] && !$csv['rch'] && !$csv['snu']) {
        throw new RuntimeException('No input CSV files provided (HRU/RCH/SNU).');
    }

    // ------------------------------------------------------------------
    // 5. Helper: load CSV into temp tables and then KPI tables
    // ------------------------------------------------------------------
    $loadCsv = function(
        string $csvPath,
        string $tmpName,
        string $schemaSql,
        string $insertSql
    ) use ($pdo, $fieldSep, $decimalSep) {

        // Normalize decimal commas if needed
        $normalizedPath = normalize_decimal_if_needed($csvPath, $decimalSep, $fieldSep);

        $pdo->exec("DROP TABLE IF EXISTS `$tmpName`");
        $pdo->exec($schemaSql);

        $sepForSql = $fieldSep === "\t" ? "\\t" : $fieldSep;
        $ignoreHeader = "IGNORE 1 LINES";

        $sql = "
            LOAD DATA LOCAL INFILE " . $pdo->quote($normalizedPath) . "
            INTO TABLE `$tmpName`
            FIELDS TERMINATED BY " . $pdo->quote($sepForSql) . " ENCLOSED BY '\"'
            LINES TERMINATED BY '\\n'
            $ignoreHeader
        ";

        $pdo->exec($sql);
        $pdo->exec($insertSql);

        if ($normalizedPath !== $csvPath && is_file($normalizedPath)) {
            @unlink($normalizedPath);
        }
    };

    // ------------------------------------------------------------------
    // 6. HRU KPI
    // ------------------------------------------------------------------
    if ($csv['hru']) {
        $tmp = 'tmp_hru_' . uniqid();

        $schema = "CREATE TABLE `$tmp` (
            LULC        VARCHAR(16),
            HRU         INT,
            HRUGIS      INT,
            SUB         INT,
            YEAR        INT,
            MON         INT,
            AREAkm2     DOUBLE,
            PRECIPmm    DOUBLE,
            SNOWFALLmm  DOUBLE,
            SNOWMELTmm  DOUBLE,
            IRRmm       DOUBLE,
            PETmm       DOUBLE,
            ETmm        DOUBLE,
            SW_INITmm   DOUBLE,
            SW_ENDmm    DOUBLE,
            PERCmm      DOUBLE,
            GW_RCHGmm   DOUBLE,
            DA_RCHGmm   DOUBLE,
            REVAPmm     DOUBLE,
            SA_IRRmm    DOUBLE,
            DA_IRRmm    DOUBLE,
            SA_STmm     DOUBLE,
            DA_STmm     DOUBLE,
            SURQ_GENmm  DOUBLE,
            SURQ_CNTmm  DOUBLE,
            TLOSS_mm    DOUBLE,
            LATQ_mm     DOUBLE,
            GW_Qmm      DOUBLE,
            WYLD_Qmm    DOUBLE,
            DAILYCN     DOUBLE,
            TMP_AVdgC   DOUBLE,
            TMP_MXdgC   DOUBLE,
            TMP_MNdgC   DOUBLE,
            SOL_TMPdgC  DOUBLE,
            SOLARmj_m2  DOUBLE,
            SYLDt_ha    DOUBLE,
            USLEt_ha    DOUBLE,
            N_APPkg_ha  DOUBLE,
            P_APPkg_ha  DOUBLE,
            N_AUTOkg_ha DOUBLE,
            P_AUTOkg_ha DOUBLE,
            NGRZkg_ha   DOUBLE,
            PGRZkg_ha   DOUBLE,
            NCFRTkg_ha  DOUBLE,
            PCFRTkg_ha  DOUBLE,
            NRAINkg_ha  DOUBLE,
            NFIXkg_ha   DOUBLE,
            F_MNkg_ha   DOUBLE,
            A_MNkg_ha   DOUBLE,
            A_SNkg_ha   DOUBLE,
            F_MPkg_aha  DOUBLE,
            AO_LPkg_ha  DOUBLE,
            L_APkg_ha   DOUBLE,
            A_SPkg_ha   DOUBLE,
            DNITkg_ha   DOUBLE,
            NUP_kg_ha   DOUBLE,
            PUPkg_ha    DOUBLE,
            ORGNkg_ha   DOUBLE,
            ORGPkg_ha   DOUBLE,
            SEDPkg_h    DOUBLE,
            NSURQkg_ha  DOUBLE,
            NLATQkg_ha  DOUBLE,
            NO3Lkg_ha   DOUBLE,
            NO3GWkg_ha  DOUBLE,
            SOLPkg_ha   DOUBLE,
            P_GWkg_ha   DOUBLE,
            W_STRS      DOUBLE,
            TMP_STRS    DOUBLE,
            N_STRS      DOUBLE,
            P_STRS      DOUBLE,
            BIOMt_ha    DOUBLE,
            LAI         DOUBLE,
            YLDt_ha     DOUBLE,
            BACTPct     DOUBLE,
            BACTLPct    DOUBLE,
            WATB_CLI    DOUBLE,
            WATB_SOL    DOUBLE,
            SNOmm       DOUBLE,
            CMUPkg_ha   DOUBLE,
            CMTOTkg_ha  DOUBLE,
            QTILEmm     DOUBLE,
            TNO3kg_ha   DOUBLE,
            LNO3kg_ha   DOUBLE,
            YYYYMM      INT
        ) ENGINE=InnoDB";

        $insert = "INSERT INTO swat_hru_kpi (
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
                $runId,
                HRU        AS hru,
                SUB        AS sub,
                HRUGIS     AS gis,
                LULC       AS lulc,
                STR_TO_DATE(CONCAT(YEAR, '-', LPAD(MON, 2, '0'), '-01'), '%Y-%m-%d') AS period_date,
                'MONTHLY'  AS period_res,
                AREAkm2    AS area_km2,
                IRRmm      AS irr_mm,
                SA_IRRmm   AS irr_sa_mm,
                DA_IRRmm   AS irr_da_mm,
                YLDt_ha    AS yld_t_ha,
                BIOMt_ha   AS biom_t_ha,
                SYLDt_ha   AS syld_t_ha,
                NUP_kg_ha  AS nup_kg_ha,
                PUPkg_ha   AS pup_kg_ha,
                NO3Lkg_ha  AS no3l_kg_ha,
                N_APPkg_ha AS n_app_kg_ha,
                P_APPkg_ha AS p_app_kg_ha,
                N_AUTOkg_ha AS nauto_kg_ha,
                P_AUTOkg_ha AS pauto_kg_ha,
                NGRZkg_ha   AS ngraz_kg_ha,
                PGRZkg_ha   AS pgraz_kg_ha,
                NCFRTkg_ha  AS cfertn_kg_ha,
                PCFRTkg_ha  AS cfertp_kg_ha
            FROM `$tmp`";

        $loadCsv($csv['hru'], $tmp, $schema, $insert);
    }

    // ------------------------------------------------------------------
    // 7. RCH KPI
    // ------------------------------------------------------------------
    if ($csv['rch']) {
        $tmp = 'tmp_rch_' . uniqid();

        $schema = "CREATE TABLE `$tmp` (
            SUB           INT,
            YEAR          INT,
            MON           INT,
            AREAkm2       DOUBLE,
            FLOW_INcms    DOUBLE,
            FLOW_OUTcms   DOUBLE,
            EVAPcms       DOUBLE,
            TLOSScms      DOUBLE,
            SED_INtons    DOUBLE,
            SED_OUTtons   DOUBLE,
            SEDCONCmg_kg  DOUBLE,
            ORGN_INkg     DOUBLE,
            ORGN_OUTkg    DOUBLE,
            ORGP_INkg     DOUBLE,
            ORGP_OUTkg    DOUBLE,
            NO3_INkg      DOUBLE,
            NO3_OUTkg     DOUBLE,
            NH4_INkg      DOUBLE,
            NH4_OUTkg     DOUBLE,
            NO2_INkg      DOUBLE,
            NO2_OUTkg     DOUBLE,
            MINP_INkg     DOUBLE,
            MINP_OUTkg    DOUBLE,
            CHLA_INkg     DOUBLE,
            CHLA_OUTkg    DOUBLE,
            CBOD_INkg     DOUBLE,
            CBOD_OUTkg    DOUBLE,
            DISOX_INkg    DOUBLE,
            DISOX_OUTkg   DOUBLE,
            SOLPST_INmg   DOUBLE,
            SOLPST_OUTmg  DOUBLE,
            SORPST_INmg   DOUBLE,
            SORPST_OUTmg  DOUBLE,
            REACTPTmg     DOUBLE,
            VOLPSTmg      DOUBLE,
            SETTLPST_mg   DOUBLE,
            RESUSP_PSTmg  DOUBLE,
            DIFUSEPSTmg   DOUBLE,
            REACHBEDPSTmg DOUBLE,
            BURYPSTmg     DOUBLE,
            BED_PSTmg     DOUBLE,
            BACTP_OUTct   DOUBLE,
            BACTLP_OUTct  DOUBLE,
            CMETAL1kg     DOUBLE,
            CMETAL2kg     DOUBLE,
            CMETAL3kg     DOUBLE,
            TOT_Nkg       DOUBLE,
            TOT_Pkg       DOUBLE,
            NO3CONCmg_l   DOUBLE,
            WTMPdegc      DOUBLE,
            YYYYMM        INT
        ) ENGINE=InnoDB";

        $insert = "INSERT INTO swat_rch_kpi (
                run_id, rch, sub, area_km2,
                period_date, period_res,
                flow_out_cms, no3_out_kg, sed_out_t
            )
            SELECT
                $runId,
                SUB AS rch,
                SUB AS sub,
                AREAkm2,
                STR_TO_DATE(CONCAT(YEAR, '-', LPAD(MON, 2, '0'), '-01'), '%Y-%m-%d') AS period_date,
                'MONTHLY' AS period_res,
                FLOW_OUTcms AS flow_out_cms,
                NO3_OUTkg   AS no3_out_kg,
                SED_OUTtons AS sed_out_t
            FROM `$tmp`";

        $loadCsv($csv['rch'], $tmp, $schema, $insert);
    }

    // ------------------------------------------------------------------
    // 8. SNU KPI
    // ------------------------------------------------------------------
    if ($csv['snu']) {
        $tmp = 'tmp_snu_' . uniqid();

        $schema = "CREATE TABLE `$tmp` (
            YEAR     INT,
            DAY      INT,
            HRUGIS   INT,
            SOL_RSD  DOUBLE,
            SOL_P    DOUBLE,
            NO3      DOUBLE,
            ORG_N    DOUBLE,
            ORG_P    DOUBLE,
            CN       DOUBLE,
            YYYYDDD  INT
        ) ENGINE=InnoDB";

        $insert = "INSERT INTO swat_snu_kpi (
                run_id, gisnum, period_date, period_res,
                sol_p, no3, org_n, org_p, cn, sol_rsd
            )
            SELECT
                $runId,
                HRUGIS AS gisnum,
                MAKEDATE(YEAR, DAY) AS period_date,
                'DAILY' AS period_res,
                SOL_P   AS sol_p,
                NO3     AS no3,
                ORG_N   AS org_n,
                ORG_P   AS org_p,
                CN      AS cn,
                SOL_RSD AS sol_rsd
            FROM `$tmp`";

        $loadCsv($csv['snu'], $tmp, $schema, $insert);
    }

    // ------------------------------------------------------------------
    // 9. Update period_start / period_end
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

    echo json_encode(['ok' => true, 'run_id' => $runId]);

} catch (Throwable $e) {
    // Best-effort cleanup: remove partially imported run (cascades to KPI tables)
    if ($runId) {
        try {
            $stmt = $pdo->prepare("DELETE FROM swat_runs WHERE id = ?");
            $stmt->execute([$runId]);
        } catch (Throwable $cleanupError) {
            // ignore cleanup failure
        }
    }

    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}