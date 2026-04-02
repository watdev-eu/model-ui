<?php
// api/import_finalize.php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (!ob_get_level()) {
    ob_start();
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CropRepository.php';
require_once __DIR__ . '/../classes/RunLicenseRepository.php';
require_once __DIR__ . '/../classes/ImportCsvHelper.php';

Auth::requireAdvanced();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$csrf = $_POST['csrf'] ?? '';
if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

function envv(string $key, ?string $default = null): string
{
    $v = getenv($key);
    if ($v === false || $v === null || $v === '') {
        return (string)$default;
    }
    return (string)$v;
}

function pgConnFromEnv()
{
    $host = envv('DB_HOST', 'db');
    $port = envv('DB_PORT', '5432');
    $name = envv('DB_NAME', 'watdev');

    $userFile = getenv('DB_USER_FILE');
    $passFile = getenv('DB_PASS_FILE');

    $user = $userFile && is_readable($userFile)
        ? trim((string)file_get_contents($userFile))
        : envv('DB_USER', 'watdev_user');

    $pass = $passFile && is_readable($passFile)
        ? trim((string)file_get_contents($passFile))
        : envv('DB_PASS', '');

    $connStr = sprintf(
        "host=%s port=%s dbname=%s user=%s password=%s connect_timeout=10",
        $host, $port, $name, $user, $pass
    );

    $pg = @pg_connect($connStr);
    if (!$pg) {
        throw new RuntimeException('Failed to connect to Postgres.');
    }
    return $pg;
}

function pgExec($pg, string $sql): void
{
    $res = @pg_query($pg, $sql);
    if ($res === false) {
        throw new RuntimeException('Postgres error: ' . pg_last_error($pg));
    }
}

function pgOne($pg, string $sql, array $params = []): ?array
{
    $res = pg_query_params($pg, $sql, $params);
    if ($res === false) {
        throw new RuntimeException('Postgres error: ' . pg_last_error($pg));
    }
    $row = pg_fetch_assoc($res);
    return $row ?: null;
}

function pgValue($pg, string $sql, array $params = [])
{
    $row = pgOne($pg, $sql, $params);
    if (!$row) return null;
    return array_values($row)[0] ?? null;
}

function pgIdent($pg, string $schema, string $name): string
{
    return pg_escape_identifier($pg, $schema) . '.' . pg_escape_identifier($pg, $name);
}

function pgCopyFromCsvFile($pg, string $table, string $path, string $fieldSep): void
{
    if (!is_file($path)) {
        throw new RuntimeException("CSV file not found for COPY: $path");
    }

    $sep = ($fieldSep === "\t") ? "\t" : $fieldSep;
    $copySql = "COPY {$table} FROM STDIN WITH (FORMAT csv, HEADER true, DELIMITER " . pg_escape_literal($pg, $sep) . ")";

    $res = @pg_query($pg, $copySql);
    if ($res === false) {
        throw new RuntimeException('COPY start failed: ' . pg_last_error($pg));
    }

    $fh = fopen($path, 'rb');
    if (!$fh) {
        throw new RuntimeException("Failed to open CSV for COPY: $path");
    }

    while (!feof($fh)) {
        $chunk = fread($fh, 1024 * 1024);
        if ($chunk === false) {
            fclose($fh);
            throw new RuntimeException("Failed reading CSV during COPY: $path");
        }
        if ($chunk !== '') {
            if (!pg_put_line($pg, $chunk)) {
                fclose($fh);
                throw new RuntimeException('COPY streaming failed: ' . pg_last_error($pg));
            }
        }
    }
    fclose($fh);

    pg_put_line($pg, "\\.\n");
    if (!pg_end_copy($pg)) {
        throw new RuntimeException('COPY end failed: ' . pg_last_error($pg));
    }
}

function normalizeDateOrNull(?string $raw): ?string
{
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    $dt = DateTime::createFromFormat('Y-m-d', $raw);
    return $dt ? $dt->format('Y-m-d') : null;
}

$pg = null;
$txStarted = false;
$runId = null;
$tmpHru = null;
$tmpRch = null;
$tmpSnu = null;
$tmpHruQ = null;
$tmpRchQ = null;
$tmpSnuQ = null;

try {
    $importToken = trim((string)($_POST['import_token'] ?? ''));
    if ($importToken === '' || !preg_match('/^[a-f0-9]{32}$/', $importToken)) {
        throw new RuntimeException('Invalid import token.');
    }

    $baseDir = rtrim(UPLOAD_DIR, '/\\') . '/import_sessions/' . $importToken;
    $metaFile = $baseDir . '/meta.json';
    if (!is_file($metaFile)) {
        throw new RuntimeException('Import session not found or expired.');
    }

    $meta = json_decode((string)file_get_contents($metaFile), true);
    if (!is_array($meta)) {
        throw new RuntimeException('Import session metadata is invalid.');
    }

    $studyAreaId = (int)($_POST['study_area'] ?? 0);
    $runLabel = trim((string)($_POST['run_label'] ?? ''));
    $runDate = normalizeDateOrNull($_POST['run_date'] ?? null);
    $modelRunAuthor = trim((string)($_POST['model_run_author'] ?? ''));
    $publicationUrl = trim((string)($_POST['publication_url'] ?? ''));
    $licenseName = trim((string)($_POST['license_name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $visibility = trim((string)($_POST['visibility'] ?? 'private'));
    $isBaseline = (int)($_POST['is_baseline'] ?? 0) === 1;
    $isDownloadable = (int)($_POST['is_downloadable'] ?? 0) === 1;
    $downloadableFromDate = normalizeDateOrNull($_POST['downloadable_from_date'] ?? null);
    $selectedSubbasinsRaw = trim((string)($_POST['selected_subbasins_json'] ?? '[]'));
    $unknownCropNamesRaw = trim((string)($_POST['unknown_crop_names_json'] ?? '{}'));

    if ($studyAreaId <= 0) {
        throw new RuntimeException('Study area is required.');
    }
    if ($runLabel === '') {
        throw new RuntimeException('Run name is required.');
    }
    if ($runDate === null) {
        throw new RuntimeException('Model run date is required.');
    }
    if ($modelRunAuthor === '') {
        throw new RuntimeException('Model run author is required.');
    }
    if (!in_array($visibility, ['private', 'public'], true)) {
        $visibility = 'private';
    }
    if (!$isDownloadable) {
        $downloadableFromDate = null;
    }

    $selectedSubbasins = json_decode($selectedSubbasinsRaw, true);
    if (!is_array($selectedSubbasins)) {
        throw new RuntimeException('Selected subbasins are invalid.');
    }
    $selectedSubbasins = array_values(array_unique(array_map('intval', $selectedSubbasins)));
    $selectedSubbasins = array_values(array_filter($selectedSubbasins, fn(int $v) => $v > 0));

    if (!$selectedSubbasins) {
        throw new RuntimeException('Please select at least one subbasin.');
    }

    $unknownCropNames = json_decode($unknownCropNamesRaw, true);
    if (!is_array($unknownCropNames)) {
        throw new RuntimeException('Unknown crop names are invalid.');
    }

    $detectedSubbasins = array_map('intval', $meta['all_subbasins'] ?? []);
    if ($detectedSubbasins) {
        $detectedSet = array_fill_keys($detectedSubbasins, true);
        foreach ($selectedSubbasins as $sub) {
            if (!isset($detectedSet[$sub])) {
                throw new RuntimeException("Selected subbasin {$sub} was not detected in the uploaded files.");
            }
        }
    }

    $pdo = Database::pdo();

    $stmt = $pdo->prepare("SELECT 1 FROM study_areas WHERE id = :id AND enabled = TRUE");
    $stmt->execute([':id' => $studyAreaId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Invalid or disabled study area.');
    }

    $stmt = $pdo->prepare("
        SELECT sub
        FROM study_area_subbasins
        WHERE study_area_id = :study_area_id
    ");
    $stmt->execute([':study_area_id' => $studyAreaId]);
    $validSubs = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $validSubSet = array_fill_keys($validSubs, true);

    foreach ($selectedSubbasins as $sub) {
        if (!isset($validSubSet[$sub])) {
            throw new RuntimeException("Subbasin {$sub} does not exist in the selected study area.");
        }
    }

    $knownCrops = [];
    foreach (CropRepository::all() as $row) {
        $knownCrops[strtoupper((string)$row['code'])] = (string)$row['name'];
    }

    $allCropCodes = array_map('strtoupper', $meta['all_crop_codes'] ?? []);
    foreach ($allCropCodes as $code) {
        if (!isset($knownCrops[$code])) {
            $name = trim((string)($unknownCropNames[$code] ?? ''));
            if ($name === '') {
                throw new RuntimeException("Please provide a name for crop code {$code}.");
            }
            CropRepository::upsert($code, $name);
        }
    }

    $licenseId = null;
    if ($licenseName !== '') {
        $licenseId = RunLicenseRepository::findOrCreateByName($licenseName);
    }

    $files = [
        'hru' => is_file($baseDir . '/hru.csv') ? $baseDir . '/hru.csv' : null,
        'rch' => is_file($baseDir . '/rch.csv') ? $baseDir . '/rch.csv' : null,
        'snu' => is_file($baseDir . '/snu.csv') ? $baseDir . '/snu.csv' : null,
    ];

    if (!$files['hru'] && !$files['rch'] && !$files['snu']) {
        throw new RuntimeException('No uploaded CSV files found in the import session.');
    }

    $fieldSep = ImportCsvHelper::normalizeFieldSep((string)($meta['field_sep'] ?? ';'));
    $decimalSep = ImportCsvHelper::decimalSepForFieldSep($fieldSep);

    $pg = pgConnFromEnv();
    pgExec($pg, "SET synchronous_commit = OFF");
    pgExec($pg, "SET temp_buffers = '64MB'");
    pgExec($pg, "SET search_path = public");
    pgExec($pg, "BEGIN");
    $txStarted = true;

    $dup = pgValue($pg,
        "SELECT 1 FROM swat_runs WHERE study_area = $1 AND run_label = $2",
        [$studyAreaId, $runLabel]
    );
    if ($dup) {
        throw new RuntimeException('A run with this name already exists for this study area.');
    }

    $createdBy = Auth::userId();

    $row = pgOne($pg, "
        INSERT INTO swat_runs
          (study_area, run_label, run_date, visibility, description, created_by,
           model_run_author, publication_url, license_id, is_downloadable,
           downloadable_from_date, is_baseline, is_default,
           period_start, period_end, time_step)
        VALUES
          ($1,$2,$3,$4,$5,$6,
           $7,$8,$9,$10,
           $11,$12,FALSE,
           NULL,NULL,'MONTHLY')
        RETURNING id
    ", [
        $studyAreaId,
        $runLabel,
        $runDate,
        $visibility,
        $description !== '' ? $description : null,
        $createdBy,
        $modelRunAuthor,
        $publicationUrl !== '' ? $publicationUrl : null,
        $licenseId,
        $isDownloadable ? 't' : 'f',
        $downloadableFromDate,
        $isBaseline ? 't' : 'f',
    ]);

    $runId = (int)($row['id'] ?? 0);
    if ($runId <= 0) {
        throw new RuntimeException('Failed to create run.');
    }

    foreach ($selectedSubbasins as $sub) {
        pgOne($pg, "
            INSERT INTO swat_run_subbasins (run_id, study_area_id, sub)
            VALUES ($1, $2, $3)
            RETURNING run_id
        ", [$runId, $studyAreaId, $sub]);
    }

    $tmpHru = 'tmp_hru_import_' . $runId;
    $tmpRch = 'tmp_rch_import_' . $runId;
    $tmpSnu = 'tmp_snu_import_' . $runId;
    $tmpHruQ = pgIdent($pg, 'public', $tmpHru);
    $tmpRchQ = pgIdent($pg, 'public', $tmpRch);
    $tmpSnuQ = pgIdent($pg, 'public', $tmpSnu);

    if ($files['hru']) {
        $path = ImportCsvHelper::normalizeDecimalIfNeeded($files['hru'], $decimalSep, $fieldSep);

        pgExec($pg, "DROP TABLE IF EXISTS {$tmpHruQ}");
        pgExec($pg, "CREATE UNLOGGED TABLE {$tmpHruQ} (
            LULC VARCHAR(16), HRU INTEGER, HRUGIS INTEGER, SUB INTEGER, YEAR INTEGER, MON INTEGER,
            AREAkm2 TEXT, PRECIPmm TEXT, SNOWFALLmm TEXT, SNOWMELTmm TEXT, IRRmm TEXT, PETmm TEXT, ETmm TEXT,
            SW_INITmm TEXT, SW_ENDmm TEXT, PERCmm TEXT, GW_RCHGmm TEXT, DA_RCHGmm TEXT, REVAPmm TEXT,
            SA_IRRmm TEXT, DA_IRRmm TEXT, SA_STmm TEXT, DA_STmm TEXT, SURQ_GENmm TEXT, SURQ_CNTmm TEXT,
            TLOSS_mm TEXT, LATQ_mm TEXT, GW_Qmm TEXT, WYLD_Qmm TEXT, DAILYCN TEXT, TMP_AVdgC TEXT,
            TMP_MXdgC TEXT, TMP_MNdgC TEXT, SOL_TMPdgC TEXT, SOLARmj_m2 TEXT, SYLDt_ha TEXT, USLEt_ha TEXT,
            N_APPkg_ha TEXT, P_APPkg_ha TEXT, N_AUTOkg_ha TEXT, P_AUTOkg_ha TEXT, NGRZkg_ha TEXT,
            PGRZkg_ha TEXT, NCFRTkg_ha TEXT, PCFRTkg_ha TEXT, NRAINkg_ha TEXT, NFIXkg_ha TEXT,
            F_MNkg_ha TEXT, A_MNkg_ha TEXT, A_SNkg_ha TEXT, F_MPkg_aha TEXT, AO_LPkg_ha TEXT,
            L_APkg_ha TEXT, A_SPkg_ha TEXT, DNITkg_ha TEXT, NUP_kg_ha TEXT, PUPkg_ha TEXT,
            ORGNkg_ha TEXT, ORGPkg_ha TEXT, SEDPkg_h TEXT, NSURQkg_ha TEXT, NLATQkg_ha TEXT,
            NO3Lkg_ha TEXT, NO3GWkg_ha TEXT, SOLPkg_ha TEXT, P_GWkg_ha TEXT, W_STRS TEXT, TMP_STRS TEXT,
            N_STRS TEXT, P_STRS TEXT, BIOMt_ha TEXT, LAI TEXT, YLDt_ha TEXT, BACTPct TEXT, BACTLPct TEXT,
            WATB_CLI TEXT, WATB_SOL TEXT, SNOmm TEXT, CMUPkg_ha TEXT, CMTOTkg_ha TEXT, QTILEmm TEXT,
            TNO3kg_ha TEXT, LNO3kg_ha TEXT, YYYYMM INTEGER
        )");

        $normalized = ImportCsvHelper::normalizeCsvToTemp($path, $fieldSep);
        pgCopyFromCsvFile($pg, $tmpHruQ, $normalized, $fieldSep);
        @unlink($normalized);

        $areaExpr   = ImportCsvHelper::pgNumericExpr('AREAkm2',    $decimalSep, $fieldSep);
        $irrExpr    = ImportCsvHelper::pgNumericExpr('IRRmm',      $decimalSep, $fieldSep);
        $saIrrExpr  = ImportCsvHelper::pgNumericExpr('SA_IRRmm',   $decimalSep, $fieldSep);
        $daIrrExpr  = ImportCsvHelper::pgNumericExpr('DA_IRRmm',   $decimalSep, $fieldSep);
        $yldExpr    = ImportCsvHelper::pgNumericExpr('YLDt_ha',    $decimalSep, $fieldSep);
        $biomExpr   = ImportCsvHelper::pgNumericExpr('BIOMt_ha',   $decimalSep, $fieldSep);
        $syldExpr   = ImportCsvHelper::pgNumericExpr('SYLDt_ha',   $decimalSep, $fieldSep);
        $nupExpr    = ImportCsvHelper::pgNumericExpr('NUP_kg_ha',  $decimalSep, $fieldSep);
        $pupExpr    = ImportCsvHelper::pgNumericExpr('PUPkg_ha',   $decimalSep, $fieldSep);
        $no3lExpr   = ImportCsvHelper::pgNumericExpr('NO3Lkg_ha',  $decimalSep, $fieldSep);
        $nAppExpr   = ImportCsvHelper::pgNumericExpr('N_APPkg_ha', $decimalSep, $fieldSep);
        $pAppExpr   = ImportCsvHelper::pgNumericExpr('P_APPkg_ha', $decimalSep, $fieldSep);
        $nAutoExpr  = ImportCsvHelper::pgNumericExpr('N_AUTOkg_ha',$decimalSep, $fieldSep);
        $pAutoExpr  = ImportCsvHelper::pgNumericExpr('P_AUTOkg_ha',$decimalSep, $fieldSep);
        $nGrazExpr  = ImportCsvHelper::pgNumericExpr('NGRZkg_ha',  $decimalSep, $fieldSep);
        $pGrazExpr  = ImportCsvHelper::pgNumericExpr('PGRZkg_ha',  $decimalSep, $fieldSep);
        $nCfrtExpr  = ImportCsvHelper::pgNumericExpr('NCFRTkg_ha', $decimalSep, $fieldSep);
        $pCfrtExpr  = ImportCsvHelper::pgNumericExpr('PCFRTkg_ha', $decimalSep, $fieldSep);

        pgExec($pg, "
            INSERT INTO swat_hru_kpi (
                run_id, hru, sub, gis, lulc, period_date, period_res,
                area_km2, irr_mm, irr_sa_mm, irr_da_mm,
                yld_t_ha, biom_t_ha, syld_t_ha,
                nup_kg_ha, pup_kg_ha, no3l_kg_ha,
                n_app_kg_ha, p_app_kg_ha, nauto_kg_ha, pauto_kg_ha,
                ngraz_kg_ha, pgraz_kg_ha, cfertn_kg_ha, cfertp_kg_ha
            )
            SELECT
                {$runId}, HRU, SUB, HRUGIS, LULC,
                make_date(YEAR, MON, 1), 'MONTHLY'::period_res_enum,
                {$areaExpr}, {$irrExpr}, {$saIrrExpr}, {$daIrrExpr},
                {$yldExpr}, {$biomExpr}, {$syldExpr},
                {$nupExpr}, {$pupExpr}, {$no3lExpr},
                {$nAppExpr}, {$pAppExpr}, {$nAutoExpr}, {$pAutoExpr},
                {$nGrazExpr}, {$pGrazExpr}, {$nCfrtExpr}, {$pCfrtExpr}
            FROM {$tmpHruQ}
            WHERE YEAR > 0 AND MON > 0
              AND SUB IN (" . implode(',', array_map('intval', $selectedSubbasins)) . ")
        ");

        pgExec($pg, "DROP TABLE IF EXISTS {$tmpHruQ}");
    }

    if ($files['rch']) {
        $path = ImportCsvHelper::normalizeDecimalIfNeeded($files['rch'], $decimalSep, $fieldSep);

        pgExec($pg, "DROP TABLE IF EXISTS {$tmpRchQ}");
        pgExec($pg, "CREATE UNLOGGED TABLE {$tmpRchQ} (
            SUB INTEGER, YEAR INTEGER, MON INTEGER, AREAkm2 TEXT,
            FLOW_INcms TEXT, FLOW_OUTcms TEXT, EVAPcms TEXT, TLOSScms TEXT,
            SED_INtons TEXT, SED_OUTtons TEXT, SEDCONCmg_kg TEXT,
            ORGN_INkg TEXT, ORGN_OUTkg TEXT, ORGP_INkg TEXT, ORGP_OUTkg TEXT,
            NO3_INkg TEXT, NO3_OUTkg TEXT, NH4_INkg TEXT, NH4_OUTkg TEXT,
            NO2_INkg TEXT, NO2_OUTkg TEXT, MINP_INkg TEXT, MINP_OUTkg TEXT,
            CHLA_INkg TEXT, CHLA_OUTkg TEXT, CBOD_INkg TEXT, CBOD_OUTkg TEXT,
            DISOX_INkg TEXT, DISOX_OUTkg TEXT, SOLPST_INmg TEXT, SOLPST_OUTmg TEXT,
            SORPST_INmg TEXT, SORPST_OUTmg TEXT, REACTPTmg TEXT, VOLPSTmg TEXT,
            SETTLPST_mg TEXT, RESUSP_PSTmg TEXT, DIFUSEPSTmg TEXT, REACHBEDPSTmg TEXT,
            BURYPSTmg TEXT, BED_PSTmg TEXT, BACTP_OUTct TEXT, BACTLP_OUTct TEXT,
            CMETAL1kg TEXT, CMETAL2kg TEXT, CMETAL3kg TEXT, TOT_Nkg TEXT, TOT_Pkg TEXT,
            NO3CONCmg_l TEXT, WTMPdegc TEXT, YYYYMM INTEGER
        )");

        $normalized = ImportCsvHelper::normalizeCsvToTemp($path, $fieldSep);
        pgCopyFromCsvFile($pg, $tmpRchQ, $normalized, $fieldSep);
        @unlink($normalized);

        $areaExpr = ImportCsvHelper::pgNumericExpr('AREAkm2', $decimalSep, $fieldSep);
        $flowExpr = ImportCsvHelper::pgNumericExpr('FLOW_OUTcms', $decimalSep, $fieldSep);
        $no3Expr  = ImportCsvHelper::pgNumericExpr('NO3_OUTkg', $decimalSep, $fieldSep);
        $sedExpr  = ImportCsvHelper::pgNumericExpr('SED_OUTtons', $decimalSep, $fieldSep);

        pgExec($pg, "
            INSERT INTO swat_rch_kpi (
                run_id, rch, sub, area_km2, period_date, period_res,
                flow_out_cms, no3_out_kg, sed_out_t
            )
            SELECT
                {$runId}, SUB, SUB, {$areaExpr},
                make_date(YEAR, MON, 1), 'MONTHLY'::period_res_enum,
                {$flowExpr}, {$no3Expr}, {$sedExpr}
            FROM {$tmpRchQ}
            WHERE YEAR > 0 AND MON > 0
              AND SUB IN (" . implode(',', array_map('intval', $selectedSubbasins)) . ")
        ");

        pgExec($pg, "DROP TABLE IF EXISTS {$tmpRchQ}");
    }

    if ($files['snu']) {
        $path = ImportCsvHelper::normalizeDecimalIfNeeded($files['snu'], $decimalSep, $fieldSep);

        pgExec($pg, "DROP TABLE IF EXISTS {$tmpSnuQ}");
        pgExec($pg, "CREATE UNLOGGED TABLE {$tmpSnuQ} (
            YEAR INTEGER, DAY INTEGER, HRUGIS INTEGER,
            SOL_RSD TEXT, SOL_P TEXT, NO3 TEXT, ORG_N TEXT, ORG_P TEXT, CN TEXT, YYYYDDD INTEGER
        )");

        $normalized = ImportCsvHelper::normalizeCsvToTemp($path, $fieldSep);
        pgCopyFromCsvFile($pg, $tmpSnuQ, $normalized, $fieldSep);
        @unlink($normalized);

        $solPExpr   = ImportCsvHelper::pgNumericExpr('SOL_P',   $decimalSep, $fieldSep);
        $no3Expr    = ImportCsvHelper::pgNumericExpr('NO3',     $decimalSep, $fieldSep);
        $orgNExpr   = ImportCsvHelper::pgNumericExpr('ORG_N',   $decimalSep, $fieldSep);
        $orgPExpr   = ImportCsvHelper::pgNumericExpr('ORG_P',   $decimalSep, $fieldSep);
        $cnExpr     = ImportCsvHelper::pgNumericExpr('CN',      $decimalSep, $fieldSep);
        $solRsdExpr = ImportCsvHelper::pgNumericExpr('SOL_RSD', $decimalSep, $fieldSep);

        pgExec($pg, "
            INSERT INTO swat_snu_kpi (
                run_id, gisnum, period_date, period_res,
                sol_p, no3, org_n, org_p, cn, sol_rsd
            )
            SELECT
                {$runId}, HRUGIS,
                (make_date(YEAR, 1, 1) + (DAY - 1) * INTERVAL '1 day')::date,
                'DAILY'::period_res_enum,
                {$solPExpr}, {$no3Expr}, {$orgNExpr}, {$orgPExpr}, {$cnExpr}, {$solRsdExpr}
            FROM {$tmpSnuQ}
            WHERE YEAR > 0 AND DAY > 0
        ");

        pgExec($pg, "DROP TABLE IF EXISTS {$tmpSnuQ}");
    }

    $range = pgOne($pg, "
        SELECT MIN(period_date) AS min_d, MAX(period_date) AS max_d
        FROM (
            SELECT period_date FROM swat_hru_kpi WHERE run_id = $1
            UNION ALL
            SELECT period_date FROM swat_rch_kpi WHERE run_id = $1
            UNION ALL
            SELECT period_date FROM swat_snu_kpi WHERE run_id = $1
        ) AS u
    ", [$runId]);

    if ($range && !empty($range['min_d'])) {
        pgExec(
            $pg,
            "UPDATE swat_runs
             SET period_start = " . pg_escape_literal($pg, $range['min_d']) . ",
                 period_end = " . pg_escape_literal($pg, $range['max_d']) . "
             WHERE id = " . (int)$runId
        );
    }

    pgExec($pg, "COMMIT");
    $txStarted = false;
    @pg_close($pg);

    echo json_encode([
        'ok' => true,
        'run_id' => $runId,
    ]);
    exit;
} catch (Throwable $e) {
    if ($pg && $txStarted) {
        @pg_query($pg, "ROLLBACK");
    }
    if ($pg) {
        if ($tmpHruQ) @pg_query($pg, "DROP TABLE IF EXISTS {$tmpHruQ}");
        if ($tmpRchQ) @pg_query($pg, "DROP TABLE IF EXISTS {$tmpRchQ}");
        if ($tmpSnuQ) @pg_query($pg, "DROP TABLE IF EXISTS {$tmpSnuQ}");
        @pg_close($pg);
    }

    error_log('[import_finalize] ' . $e->getMessage());

    if (ob_get_level()) {
        ob_clean();
    }

    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}