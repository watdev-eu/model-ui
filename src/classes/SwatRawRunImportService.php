<?php
// classes/SwatRawRunImportService.php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/CropRepository.php';
require_once __DIR__ . '/RunLicenseRepository.php';
require_once __DIR__ . '/SwatRawImportHelper.php';

final class SwatRawRunImportService
{
    public static function importFromSession(array $input): array
    {
        $importToken = trim((string)($input['import_token'] ?? ''));
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

        $studyAreaId           = (int)($input['study_area'] ?? 0);
        $runLabel              = trim((string)($input['run_label'] ?? ''));
        $runDate               = self::normalizeDateOrNull($input['run_date'] ?? null);
        $modelRunAuthor        = trim((string)($input['model_run_author'] ?? ''));
        $publicationUrl        = trim((string)($input['publication_url'] ?? ''));
        $licenseName           = trim((string)($input['license_name'] ?? ''));
        $description           = trim((string)($input['description'] ?? ''));
        $visibility            = trim((string)($input['visibility'] ?? 'private'));
        $isBaseline            = (int)($input['is_baseline'] ?? 0) === 1;
        $isDownloadable        = (int)($input['is_downloadable'] ?? 0) === 1;
        $downloadableFromDate  = self::normalizeDateOrNull($input['downloadable_from_date'] ?? null);
        $selectedSubbasinsRaw  = trim((string)($input['selected_subbasins_json'] ?? '[]'));
        $unknownCropNamesRaw   = trim((string)($input['unknown_crop_names_json'] ?? '{}'));

        if ($studyAreaId <= 0) throw new RuntimeException('Study area is required.');
        if ($runLabel === '') throw new RuntimeException('Run name is required.');
        if ($runDate === null) throw new RuntimeException('Model run date is required.');
        if ($modelRunAuthor === '') throw new RuntimeException('Model run author is required.');
        if (!in_array($visibility, ['private', 'public'], true)) $visibility = 'private';
        if (!$isDownloadable) $downloadableFromDate = null;

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

        self::validateSelectedSubbasinsAgainstDetected($selectedSubbasins, $meta);
        self::validateStudyAreaAndSubs($studyAreaId, $selectedSubbasins);
        self::resolveUnknownCrops($meta, $unknownCropNames);

        $licenseId = null;
        if ($licenseName !== '') {
            $licenseId = RunLicenseRepository::findOrCreateByName($licenseName);
        }

        $cioMeta = $meta['cio'] ?? null;
        if (!is_array($cioMeta) || empty($cioMeta['printed_begin_year'])) {
            throw new RuntimeException('Missing parsed file.cio metadata.');
        }

        $rawFiles = [
            'cio' => $baseDir . '/file.cio',
            'hru' => $baseDir . '/output.hru',
            'rch' => is_file($baseDir . '/output.rch') ? $baseDir . '/output.rch' : null,
            'snu' => $baseDir . '/output.snu',
        ];

        if (!is_file($rawFiles['cio']) || !is_file($rawFiles['hru']) || !is_file($rawFiles['snu'])) {
            throw new RuntimeException('One or more required uploaded files are missing.');
        }

        $normalized = self::buildNormalizedFiles($baseDir, $rawFiles, $cioMeta);

        $pg = null;
        $txStarted = false;
        $runId = null;
        $tmpHruQ = null;
        $tmpRchQ = null;
        $tmpSnuQ = null;

        try {
            $pg = self::pgConnFromEnv();
            self::pgExec($pg, "SET synchronous_commit = OFF");
            self::pgExec($pg, "SET temp_buffers = '64MB'");
            self::pgExec($pg, "SET search_path = public");
            self::pgExec($pg, "BEGIN");
            $txStarted = true;

            $dup = self::pgValue(
                $pg,
                "SELECT 1 FROM swat_runs WHERE study_area = $1 AND run_label = $2",
                [$studyAreaId, $runLabel]
            );
            if ($dup) {
                throw new RuntimeException('A run with this name already exists for this study area.');
            }

            $runId = self::insertRun(
                $pg,
                $studyAreaId,
                $runLabel,
                $runDate,
                $visibility,
                $description,
                Auth::userId(),
                $modelRunAuthor,
                $publicationUrl,
                $licenseId,
                $isDownloadable,
                $downloadableFromDate,
                $isBaseline
            );

            self::insertRunSubbasins($pg, $runId, $studyAreaId, $selectedSubbasins);

            $tmpHruQ = self::pgIdent($pg, 'public', 'tmp_hru_import_' . $runId);
            $tmpRchQ = self::pgIdent($pg, 'public', 'tmp_rch_import_' . $runId);
            $tmpSnuQ = self::pgIdent($pg, 'public', 'tmp_snu_import_' . $runId);

            self::importHru($pg, $tmpHruQ, $normalized['hru'], $runId, $selectedSubbasins);

            if (!empty($normalized['rch']) && is_file($normalized['rch'])) {
                self::importRch($pg, $tmpRchQ, $normalized['rch'], $runId, $selectedSubbasins);
            }

            self::importSnu($pg, $tmpSnuQ, $normalized['snu'], $runId);
            self::updateRunDateRange($pg, $runId);

            self::pgExec($pg, "COMMIT");
            $txStarted = false;
            @pg_close($pg);

            return ['ok' => true, 'run_id' => $runId];
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
            throw $e;
        }
    }

    private static function validateSelectedSubbasinsAgainstDetected(array $selectedSubbasins, array $meta): void
    {
        $detectedSubbasins = array_map('intval', $meta['all_subbasins'] ?? []);
        if (!$detectedSubbasins) {
            return;
        }

        $detectedSet = array_fill_keys($detectedSubbasins, true);
        foreach ($selectedSubbasins as $sub) {
            if (!isset($detectedSet[$sub])) {
                throw new RuntimeException("Selected subbasin {$sub} was not detected in the uploaded files.");
            }
        }
    }

    private static function validateStudyAreaAndSubs(int $studyAreaId, array $selectedSubbasins): void
    {
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
    }

    private static function resolveUnknownCrops(array $meta, array $unknownCropNames): void
    {
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
    }

    private static function buildNormalizedFiles(string $baseDir, array $rawFiles, array $cioMeta): array
    {
        $normalized = [
            'hru' => $baseDir . '/normalized_hru.csv',
            'rch' => $baseDir . '/normalized_rch.csv',
            'snu' => $baseDir . '/normalized_snu.csv',
        ];

        SwatRawImportHelper::convertHruToCsv($rawFiles['hru'], $cioMeta, $normalized['hru']);

        if (!empty($rawFiles['rch'])) {
            SwatRawImportHelper::convertRchToCsv($rawFiles['rch'], $cioMeta, $normalized['rch']);
        } else {
            $normalized['rch'] = null;
        }

        SwatRawImportHelper::convertSnuToCsv($rawFiles['snu'], $cioMeta, $normalized['snu']);

        return $normalized;
    }

    private static function insertRun(
        $pg,
        int $studyAreaId,
        string $runLabel,
        string $runDate,
        string $visibility,
        string $description,
        ?string $createdBy,
        string $modelRunAuthor,
        string $publicationUrl,
        ?int $licenseId,
        bool $isDownloadable,
        ?string $downloadableFromDate,
        bool $isBaseline
    ): int {
        $row = self::pgOne($pg, "
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
        return $runId;
    }

    private static function insertRunSubbasins($pg, int $runId, int $studyAreaId, array $selectedSubbasins): void
    {
        foreach ($selectedSubbasins as $sub) {
            self::pgOne($pg, "
                INSERT INTO swat_run_subbasins (run_id, study_area_id, sub)
                VALUES ($1, $2, $3)
                RETURNING run_id
            ", [$runId, $studyAreaId, $sub]);
        }
    }

    private static function importHru($pg, string $tmpHruQ, string $normalizedHru, int $runId, array $selectedSubbasins): void
    {
        self::pgExec($pg, "DROP TABLE IF EXISTS {$tmpHruQ}");
        self::pgExec($pg, "CREATE UNLOGGED TABLE {$tmpHruQ} (
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
            TNO3kg_ha TEXT, LNO3kg_ha TEXT
        )");

        self::pgCopyFromCsvFile($pg, $tmpHruQ, $normalizedHru);

        self::pgExec($pg, "
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
                NULLIF(AREAkm2, '')::double precision,
                NULLIF(IRRmm, '')::double precision,
                NULLIF(SA_IRRmm, '')::double precision,
                NULLIF(DA_IRRmm, '')::double precision,
                NULLIF(YLDt_ha, '')::double precision,
                NULLIF(BIOMt_ha, '')::double precision,
                NULLIF(SYLDt_ha, '')::double precision,
                NULLIF(NUP_kg_ha, '')::double precision,
                NULLIF(PUPkg_ha, '')::double precision,
                NULLIF(NO3Lkg_ha, '')::double precision,
                NULLIF(N_APPkg_ha, '')::double precision,
                NULLIF(P_APPkg_ha, '')::double precision,
                NULLIF(N_AUTOkg_ha, '')::double precision,
                NULLIF(P_AUTOkg_ha, '')::double precision,
                NULLIF(NGRZkg_ha, '')::double precision,
                NULLIF(PGRZkg_ha, '')::double precision,
                NULLIF(NCFRTkg_ha, '')::double precision,
                NULLIF(PCFRTkg_ha, '')::double precision
            FROM {$tmpHruQ}
            WHERE YEAR > 0 AND MON > 0
              AND SUB IN (" . implode(',', array_map('intval', $selectedSubbasins)) . ")
        ");
    }

    private static function importRch($pg, string $tmpRchQ, string $normalizedRch, int $runId, array $selectedSubbasins): void
    {
        self::pgExec($pg, "DROP TABLE IF EXISTS {$tmpRchQ}");
        self::pgExec($pg, "CREATE UNLOGGED TABLE {$tmpRchQ} (
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
            NO3CONCmg_l TEXT, WTMPdegc TEXT
        )");

        self::pgCopyFromCsvFile($pg, $tmpRchQ, $normalizedRch);

        self::pgExec($pg, "
            INSERT INTO swat_rch_kpi (
                run_id, rch, sub, area_km2, period_date, period_res,
                flow_out_cms, no3_out_kg, sed_out_t
            )
            SELECT
                {$runId}, SUB, SUB,
                NULLIF(AREAkm2, '')::double precision,
                make_date(YEAR, MON, 1), 'MONTHLY'::period_res_enum,
                NULLIF(FLOW_OUTcms, '')::double precision,
                NULLIF(NO3_OUTkg, '')::double precision,
                NULLIF(SED_OUTtons, '')::double precision
            FROM {$tmpRchQ}
            WHERE YEAR > 0 AND MON > 0
              AND SUB IN (" . implode(',', array_map('intval', $selectedSubbasins)) . ")
        ");
    }

    private static function importSnu($pg, string $tmpSnuQ, string $normalizedSnu, int $runId): void
    {
        self::pgExec($pg, "DROP TABLE IF EXISTS {$tmpSnuQ}");
        self::pgExec($pg, "CREATE UNLOGGED TABLE {$tmpSnuQ} (
            YEAR INTEGER, DAY INTEGER, HRUGIS INTEGER,
            SOL_RSD TEXT, SOL_P TEXT, NO3 TEXT, ORG_N TEXT, ORG_P TEXT, CN TEXT
        )");

        self::pgCopyFromCsvFile($pg, $tmpSnuQ, $normalizedSnu);

        self::pgExec($pg, "
            INSERT INTO swat_snu_kpi (
                run_id, gisnum, period_date, period_res,
                sol_p, no3, org_n, org_p, cn, sol_rsd
            )
            SELECT
                {$runId}, HRUGIS,
                (make_date(YEAR, 1, 1) + (DAY - 1) * INTERVAL '1 day')::date,
                'DAILY'::period_res_enum,
                NULLIF(SOL_P, '')::double precision,
                NULLIF(NO3, '')::double precision,
                NULLIF(ORG_N, '')::double precision,
                NULLIF(ORG_P, '')::double precision,
                NULLIF(CN, '')::double precision,
                NULLIF(SOL_RSD, '')::double precision
            FROM {$tmpSnuQ}
            WHERE YEAR > 0 AND DAY > 0
        ");
    }

    private static function updateRunDateRange($pg, int $runId): void
    {
        $range = self::pgOne($pg, "
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
            self::pgExec(
                $pg,
                "UPDATE swat_runs
                 SET period_start = " . pg_escape_literal($pg, $range['min_d']) . ",
                     period_end = " . pg_escape_literal($pg, $range['max_d']) . "
                 WHERE id = " . (int)$runId
            );
        }
    }

    private static function normalizeDateOrNull(?string $raw): ?string
    {
        $raw = trim((string)$raw);
        if ($raw === '') return null;
        $dt = DateTime::createFromFormat('Y-m-d', $raw);
        return $dt ? $dt->format('Y-m-d') : null;
    }

    private static function envv(string $key, ?string $default = null): string
    {
        $v = getenv($key);
        if ($v === false || $v === null || $v === '') {
            return (string)$default;
        }
        return (string)$v;
    }

    private static function pgConnFromEnv()
    {
        $host = self::envv('DB_HOST', 'db');
        $port = self::envv('DB_PORT', '5432');
        $name = self::envv('DB_NAME', 'watdev');

        $userFile = getenv('DB_USER_FILE');
        $passFile = getenv('DB_PASS_FILE');

        $user = $userFile && is_readable($userFile)
            ? trim((string)file_get_contents($userFile))
            : self::envv('DB_USER', 'watdev_user');

        $pass = $passFile && is_readable($passFile)
            ? trim((string)file_get_contents($passFile))
            : self::envv('DB_PASS', '');

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

    private static function pgExec($pg, string $sql): void
    {
        $res = @pg_query($pg, $sql);
        if ($res === false) {
            throw new RuntimeException('Postgres error: ' . pg_last_error($pg));
        }
    }

    private static function pgOne($pg, string $sql, array $params = []): ?array
    {
        $res = pg_query_params($pg, $sql, $params);
        if ($res === false) {
            throw new RuntimeException('Postgres error: ' . pg_last_error($pg));
        }
        $row = pg_fetch_assoc($res);
        return $row ?: null;
    }

    private static function pgValue($pg, string $sql, array $params = [])
    {
        $row = self::pgOne($pg, $sql, $params);
        if (!$row) return null;
        return array_values($row)[0] ?? null;
    }

    private static function pgIdent($pg, string $schema, string $name): string
    {
        return pg_escape_identifier($pg, $schema) . '.' . pg_escape_identifier($pg, $name);
    }

    private static function pgCopyFromCsvFile($pg, string $table, string $path): void
    {
        if (!is_file($path)) {
            throw new RuntimeException("CSV file not found for COPY: $path");
        }

        $copySql = "COPY {$table} FROM STDIN WITH (FORMAT csv, HEADER true, DELIMITER ',')";
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
}