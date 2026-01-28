<?php
declare(strict_types=1);

// Start buffering + suppress HTML error output BEFORE any includes
ini_set('display_errors', '0');
error_reporting(E_ALL);

if (!ob_get_level()) {
    ob_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
//require_admin();

header('Content-Type: application/json');

// ------------------------------------------------------------------
// 1. Read & validate metadata
// ------------------------------------------------------------------
$studyAreaId = isset($_POST['study_area']) ? (int)$_POST['study_area'] : 0;
$runLabel   = trim($_POST['run_label'] ?? '');
$runDateRaw = trim($_POST['run_date'] ?? '');
$visibility = $_POST['visibility'] ?? 'private';
$description = trim($_POST['description'] ?? '');

if ($studyAreaId <= 0 || !$runLabel) {
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

$in = [
    'hru_csv' => $_FILES['hru_csv']['tmp_name'] ?? ($_POST['hru_csv_path'] ?? null),
    'rch_csv' => $_FILES['rch_csv']['tmp_name'] ?? ($_POST['rch_csv_path'] ?? null),
    'snu_csv' => $_FILES['snu_csv']['tmp_name'] ?? ($_POST['snu_csv_path'] ?? null),
];

// Helper to normalize decimal separator in a copy of the file
function normalize_decimal_if_needed(string $srcPath, string $decimalSep, string $fieldSep): string
{
    error_log('[import_run] normalize_decimal_if_needed called with: ' . $srcPath);

    if (!is_file($srcPath)) {
        $dir = dirname($srcPath);
        error_log('[import_run] CSV missing: ' . $srcPath);
        error_log('[import_run] ls dir: ' . $dir . ' => ' . shell_exec('ls -la ' . escapeshellarg($dir) . ' 2>&1'));
        error_log('[import_run] UPLOAD_DIR: ' . (defined('UPLOAD_DIR') ? UPLOAD_DIR : '(undef)'));
        if (defined('UPLOAD_DIR')) {
            error_log('[import_run] ls UPLOAD_DIR => ' . shell_exec('ls -la ' . escapeshellarg(UPLOAD_DIR) . ' 2>&1'));
        }
        error_log('[import_run] ls /tmp => ' . shell_exec('ls -la /tmp 2>&1'));
        throw new RuntimeException('CSV file not found: ' . $srcPath);
    }

    error_log('[import_run] CSV exists, size=' . filesize($srcPath));
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

function pgOne($pg, string $sql, array $params = []): ?array
{
    $res = pg_query_params($pg, $sql, $params);
    if ($res === false) {
        throw new RuntimeException('Postgres error: ' . pg_last_error($pg) . ' | SQL: ' . $sql);
    }
    $row = pg_fetch_assoc($res);
    return $row ?: null;
}

function pgValue($pg, string $sql, array $params = [])
{
    $row = pgOne($pg, $sql, $params);
    if (!$row) return null;
    // return first column value
    return array_values($row)[0] ?? null;
}

function pgIdent($pg, string $schema, string $name): string
{
    return pg_escape_identifier($pg, $schema) . '.' . pg_escape_identifier($pg, $name);
}

function normalizeCsvToTemp(string $srcPath, string $delimiter, string $enclosure = '"', string $escape = "\\"): string
{
    $in = fopen($srcPath, 'rb');
    if (!$in) throw new RuntimeException("Cannot open CSV: $srcPath");

    $tmp = tempnam(sys_get_temp_dir(), 'csvnorm_');
    $out = fopen($tmp, 'wb');
    if (!$out) throw new RuntimeException("Cannot create temp CSV: $tmp");

    // Read header
    $headerLine = fgets($in);
    if ($headerLine === false) throw new RuntimeException("CSV empty: $srcPath");

    $header = str_getcsv(rtrim($headerLine, "\r\n"), $delimiter, $enclosure, $escape);
    $expectedCols = count($header);

    // Write header back out cleanly
    fputcsv($out, $header, $delimiter, $enclosure, $escape);

    $buffer = '';
    $lineNo = 1;

    while (!feof($in)) {
        $line = fgets($in);
        if ($line === false) break;
        $lineNo++;

        $buffer .= $line;

        // Try to parse current buffer as a CSV record
        $record = str_getcsv(rtrim($buffer, "\r\n"), $delimiter, $enclosure, $escape);

        // If it doesn't match expected col count, it might be a multiline field: keep buffering
        if (count($record) !== $expectedCols) {
            continue;
        }

        // It matches: write normalized record and reset buffer
        fputcsv($out, $record, $delimiter, $enclosure, $escape);
        $buffer = '';
    }

    // If buffer left, it means last record never matched expected columns
    if (trim($buffer) !== '') {
        fclose($in);
        fclose($out);
        @unlink($tmp);
        throw new RuntimeException("CSV appears malformed near end (unfinished record).");
    }

    fclose($in);
    fclose($out);

    return $tmp;
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

function pgConnFromEnv()
{
    $host = env('DB_HOST', 'db');
    $port = env('DB_PORT', '5432');
    $name = env('DB_NAME', 'watdev');

    $user = getenv('DB_USER_FILE') && is_readable(getenv('DB_USER_FILE'))
        ? trim(file_get_contents(getenv('DB_USER_FILE')))
        : env('DB_USER', 'watdev_user');

    $pass = getenv('DB_PASS_FILE') && is_readable(getenv('DB_PASS_FILE'))
        ? trim(file_get_contents(getenv('DB_PASS_FILE')))
        : env('DB_PASS', '');

    // Optional: add connect_timeout so the import fails fast if networking is broken
    $connStr = sprintf(
        "host=%s port=%s dbname=%s user=%s password=%s connect_timeout=10",
        $host, $port, $name, $user, $pass
    );

    $pg = @pg_connect($connStr);
    if (!$pg) {
        throw new RuntimeException('Failed to connect to Postgres via ext/pgsql (check DB_HOST/PORT/network/creds).');
    }
    return $pg;
}

function pgExec($pg, string $sql): void
{
    $res = @pg_query($pg, $sql);
    if ($res === false) {
        throw new RuntimeException('Postgres error: ' . pg_last_error($pg) . ' | SQL: ' . $sql);
    }
}

function pgCopyFromCsvFile($pg, string $table, string $path, string $fieldSep): void
{
    if (!is_file($path)) {
        throw new RuntimeException("CSV file not found for COPY: $path");
    }

    $sep = ($fieldSep === "\t") ? "\t" : $fieldSep;

    // Start COPY FROM STDIN (client-side)
    $copySql = "COPY {$table} FROM STDIN WITH (FORMAT csv, HEADER true, DELIMITER " . pg_escape_literal($pg, $sep) . ")";

    error_log('[import_run] COPY SQL: ' . $copySql);

    $res = @pg_query($pg, $copySql);
    if ($res === false) {
        throw new RuntimeException('COPY start failed: ' . pg_last_error($pg));
    }

    $fh = fopen($path, 'rb');
    if (!$fh) {
        throw new RuntimeException("Failed to open CSV for COPY: $path");
    }

    // Stream in chunks (fast; less overhead than line-by-line)
    while (!feof($fh)) {
        $chunk = fread($fh, 1024 * 1024); // 1MB
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

    // End COPY
    pg_put_line($pg, "\\.\n");
    if (!pg_end_copy($pg)) {
        throw new RuntimeException('COPY end failed: ' . pg_last_error($pg));
    }
}

function dropStagingTablesPg($pg, ?string ...$tables): void
{
    foreach ($tables as $t) {
        if (!$t) continue;

        // allow "tmp_*" or "public.tmp_*"
        if (!preg_match('/^(public\.)?tmp_(hru|rch|snu)_import_\d+$/', $t)) {
            continue;
        }

        // If caller passed unqualified, drop as public.<name>
        if (strpos($t, '.') === false) {
            $t = pg_escape_identifier($pg, 'public') . '.' . pg_escape_identifier($pg, $t);
        } else {
            // already qualified like public.tmp_hru_import_8
            [$schema, $name] = explode('.', $t, 2);
            $t = pg_escape_identifier($pg, $schema) . '.' . pg_escape_identifier($pg, $name);
        }

        @pg_query($pg, "DROP TABLE IF EXISTS {$t}");
    }
}

$pg = null;
$txStarted = false;
$runId = null;

try {
    $uploadDir = UPLOAD_DIR;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        throw new RuntimeException("Failed to create upload dir: $uploadDir");
    }
    if (!is_writable($uploadDir)) {
        throw new RuntimeException("Upload dir not writable: $uploadDir");
    }

// ------------------------------------------------------------------
// 4. Copy uploaded files to a safe place
// ------------------------------------------------------------------
    $csv = ['hru' => null, 'rch' => null, 'snu' => null];

    if ($in['hru_csv']) {
        $csv['hru'] = $uploadDir . '/hru_' . uniqid() . '.csv';
        error_log('[import_run] HRU target path: ' . $csv['hru']);
        // move_uploaded_file returns false if source is not an uploaded file
        if (is_uploaded_file($in['hru_csv'])) {
            if (!move_uploaded_file($in['hru_csv'], $csv['hru'])) {
                throw new RuntimeException('Failed to move uploaded HRU CSV');
            }
            error_log('[import_run] HRU file moved successfully');
        } else {
            error_log('[import_run] HRU source is NOT an uploaded file, trying copy');
            if (!copy($in['hru_csv'], $csv['hru'])) {
                throw new RuntimeException('Failed to copy HRU CSV from ' . $in['hru_csv']);
            }
        }
    }
    if ($in['rch_csv']) {
        $csv['rch'] = $uploadDir . '/rch_' . uniqid() . '.csv';
        error_log('[import_run] RCH target path: ' . $csv['rch']);
        if (is_uploaded_file($in['rch_csv'])) {
            if (!move_uploaded_file($in['rch_csv'], $csv['rch'])) {
                throw new RuntimeException('Failed to move uploaded RCH CSV');
            }
            error_log('[import_run] RCH file moved successfully');
        } else {
            error_log('[import_run] RCH source is NOT an uploaded file, trying copy');
            if (!copy($in['rch_csv'], $csv['rch'])) {
                throw new RuntimeException('Failed to copy RCH CSV from ' . $in['rch_csv']);
            }
        }
    }
    if ($in['snu_csv']) {
        $csv['snu'] = $uploadDir . '/snu_' . uniqid() . '.csv';
        error_log('[import_run] SNU target path: ' . $csv['snu']);
        if (is_uploaded_file($in['snu_csv'])) {
            if (!move_uploaded_file($in['snu_csv'], $csv['snu'])) {
                throw new RuntimeException('Failed to move uploaded SNU CSV');
            }
            error_log('[import_run] SNU file moved successfully');
        } else {
            error_log('[import_run] SNU source is NOT an uploaded file, trying copy');
            if (!copy($in['snu_csv'], $csv['snu'])) {
                throw new RuntimeException('Failed to copy SNU CSV from ' . $in['snu_csv']);
            }
        }
    }

    error_log('[import_run] _FILES: ' . print_r($_FILES, true));
    error_log('[import_run] POST paths: ' . print_r([
            'hru_csv_path' => $_POST['hru_csv_path'] ?? null,
            'rch_csv_path' => $_POST['rch_csv_path'] ?? null,
            'snu_csv_path' => $_POST['snu_csv_path'] ?? null,
        ], true));
    error_log('[import_run] upload_tmp_dir: ' . ini_get('upload_tmp_dir'));
    error_log('[import_run] sys_temp_dir: ' . sys_get_temp_dir());
    error_log('[import_run] cwd: ' . getcwd());

    if (!$csv['hru'] && !$csv['rch'] && !$csv['snu']) {
        throw new RuntimeException('No input CSV files provided (HRU/RCH/SNU).');
    }

    $pg = pgConnFromEnv();
    pgExec($pg, "SET synchronous_commit = OFF");
    pgExec($pg, "SET temp_buffers = '64MB'");
    pgExec($pg, "SET search_path = public");

    pgExec($pg, "BEGIN");
    $txStarted = true;

    //$info = pgOne($pg, "SELECT current_schema() s, current_setting('search_path') sp");
    //error_log('[import_run] schema/search_path: ' . json_encode($info));

    $exists = pgValue($pg,
        "SELECT 1 FROM study_areas WHERE id = $1 AND enabled = TRUE",
        [$studyAreaId]
    );
    if (!$exists) {
        pgExec($pg, "ROLLBACK");
        $txStarted = false;
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or disabled study area']);
        @pg_close($pg);
        exit;
    }

    //$ctx = pgOne($pg, "SELECT inet_server_addr() addr, inet_server_port() port, current_database() db, current_schema() schema_name, current_user usr");
    //error_log('[import_run] PG conn: ' . json_encode($ctx));

    // ------------------------------------------------------------------
    // 2. Check uniqueness within study area
    // ------------------------------------------------------------------
    $dup = pgValue($pg,
        "SELECT 1 FROM swat_runs WHERE study_area = $1 AND run_label = $2",
        [$studyAreaId, $runLabel]
    );
    if ($dup) {
        if ($txStarted) { pgExec($pg, "ROLLBACK"); $txStarted = false; }
        @pg_close($pg);
        http_response_code(409);
        echo json_encode(['error' => 'A run with this scenario name already exists for this study area; choose a different name']);
        exit;
    }

    // ------------------------------------------------------------------
    // 3. Create run
    // ------------------------------------------------------------------
    $row = pgOne($pg, "
        INSERT INTO swat_runs
          (study_area, run_label, run_date,
           visibility, description, created_by,
           period_start, period_end, time_step)
        VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9)
        RETURNING id
    ", [
        $studyAreaId,
        $runLabel,
        $runDate,                               // can be null
        $visibility,
        $description !== '' ? $description : null,
        null,                                   // created_by
        null,                                   // period_start
        null,                                   // period_end
        'MONTHLY',
    ]);

    $runId = (int)($row['id'] ?? 0);
    if ($runId <= 0) {
        throw new RuntimeException('Failed to create swat_runs row');
    }

    $tmpHru = 'tmp_hru_import_' . $runId;
    $tmpRch = 'tmp_rch_import_' . $runId;
    $tmpSnu = 'tmp_snu_import_' . $runId;

    $tmpHruQ = pgIdent($pg, 'public', $tmpHru);
    $tmpRchQ = pgIdent($pg, 'public', $tmpRch);
    $tmpSnuQ = pgIdent($pg, 'public', $tmpSnu);

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
        pgExec($pg, "DROP TABLE IF EXISTS {$tmpHruQ}");
        pgExec($pg, "CREATE UNLOGGED TABLE {$tmpHruQ} (
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
            )
        ");

        $tbl = pgValue($pg, "SELECT to_regclass($1)", ["public.$tmpHru"]);
        error_log('[import_run] to_regclass HRU = ' . var_export($tbl, true));
        if (!$tbl) {
            throw new RuntimeException("Staging table missing right before COPY: public.$tmpHru");
        }

        $normalized = normalizeCsvToTemp($path, $fieldSep);
        pgCopyFromCsvFile($pg, $tmpHruQ, $normalized, $fieldSep);
        @unlink($normalized);

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
            FROM {$tmpHruQ}
            WHERE YEAR > 0 AND MON > 0
        ";
        pgExec($pg, $sql);

        // 5.4. Cleanup staging
        pgExec($pg, "DROP TABLE IF EXISTS {$tmpHruQ}");
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
        pgExec($pg, "DROP TABLE IF EXISTS {$tmpRchQ}");
        pgExec($pg, "CREATE UNLOGGED TABLE {$tmpRchQ} (
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
            )");

        $tbl = pgValue($pg, "SELECT to_regclass($1)", ["public.$tmpRch"]);
        error_log('[import_run] to_regclass RCH = ' . var_export($tbl, true));
        if (!$tbl) {
            throw new RuntimeException("Staging table missing right before COPY: public.$tmpRch");
        }

        $normalized = normalizeCsvToTemp($path, $fieldSep);
        pgCopyFromCsvFile($pg, $tmpRchQ, $normalized, $fieldSep);
        @unlink($normalized);

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
            FROM {$tmpRchQ}
            WHERE YEAR > 0 AND MON > 0;
        ";
        pgExec($pg, $sql);

        // 6.4. Cleanup staging
        pgExec($pg, "DROP TABLE IF EXISTS {$tmpRchQ}");
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
        pgExec($pg, "DROP TABLE IF EXISTS {$tmpSnuQ}");
        pgExec($pg, "CREATE UNLOGGED TABLE {$tmpSnuQ} (
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
            )");

        $tbl = pgValue($pg, "SELECT to_regclass($1)", ["public.$tmpSnu"]);
        error_log('[import_run] to_regclass SNU = ' . var_export($tbl, true));
        if (!$tbl) {
            throw new RuntimeException("Staging table missing right before COPY: public.$tmpSnu");
        }

        $normalized = normalizeCsvToTemp($path, $fieldSep);
        pgCopyFromCsvFile($pg, $tmpSnuQ, $normalized, $fieldSep);
        @unlink($normalized);

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
            FROM {$tmpSnuQ}
            WHERE YEAR > 0 AND DAY > 0
        ";
        pgExec($pg, $sql);

        // 7.4. Cleanup staging
        pgExec($pg, "DROP TABLE IF EXISTS {$tmpSnuQ}");
    }

    // ------------------------------------------------------------------
    // 8. Update period_start / period_end
    // ------------------------------------------------------------------
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
        pgExec($pg, "UPDATE swat_runs SET period_start = " . pg_escape_literal($pg, $range['min_d']) .
            ", period_end = " . pg_escape_literal($pg, $range['max_d']) .
            " WHERE id = " . (int)$runId);
    }

    pgExec($pg, "COMMIT");
    $txStarted = false;

    @pg_close($pg);

    // Clean any previous output, then send clean JSON
    if (ob_get_level()) {
        ob_clean();
    }
    echo json_encode(['ok' => true, 'run_id' => $runId]);
    exit;

} catch (Throwable $e) {
    if ($pg && $txStarted) {
        pgExec($pg, "ROLLBACK");
        $txStarted = false;
    }

    // Always attempt cleanup if names exist
    if ($pg) {
        try {
            dropStagingTablesPg(
                $pg,
                $tmpHruQ ?? null,
                $tmpRchQ ?? null,
                $tmpSnuQ ?? null,
                $tmpHru  ?? null,
                $tmpRch  ?? null,
                $tmpSnu  ?? null
            );
        } catch (Throwable $ignored) {
            error_log('[import_run] staging cleanup failed: ' . $ignored->getMessage());
        }
    }

    if (isset($pg) && $pg) {
        @pg_close($pg);
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