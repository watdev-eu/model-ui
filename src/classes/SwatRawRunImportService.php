<?php
// classes/SwatRawRunImportService.php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/CropRepository.php';
require_once __DIR__ . '/RunLicenseRepository.php';
require_once __DIR__ . '/SwatRawImportHelper.php';
require_once __DIR__ . '/SwatYearlyMaterializer.php';

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

        $pg = null;
        $txStarted = false;
        $runId = null;

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

            $artifacts = SwatYearlyMaterializer::buildImportArtifacts(
                $rawFiles,
                $cioMeta,
                $selectedSubbasins
            );

            self::insertYearlyIndicatorRows($pg, $runId, $artifacts['yearly_rows'] ?? []);
            self::insertCropAreaContextRows($pg, $runId, $artifacts['crop_area_rows'] ?? []);
            self::insertIrrigationAreaContextRows($pg, $runId, $artifacts['irrigation_area_rows'] ?? []);
            self::updateRunDateRangeFromMetaOrYearly($pg, $runId, $meta);

            self::pgExec($pg, "COMMIT");
            $txStarted = false;
            @pg_close($pg);

            return ['ok' => true, 'run_id' => $runId];
        } catch (Throwable $e) {
            if ($pg && $txStarted) {
                @pg_query($pg, "ROLLBACK");
            }
            if ($pg) {
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
               NULL,NULL,'YEARLY')
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

    private static function insertYearlyIndicatorRows($pg, int $runId, array $rows): void
    {
        if (!$rows) {
            throw new RuntimeException('No yearly indicator rows were generated from the uploaded files.');
        }

        $sql = "
        INSERT INTO swat_indicator_yearly
            (run_id, indicator_code, year, sub, crop, value)
        VALUES
            ($1, $2, $3, $4, $5, $6)
        ON CONFLICT (run_id, indicator_code, year, sub, crop)
        DO UPDATE SET value = EXCLUDED.value
    ";

        foreach ($rows as $row) {
            $res = pg_query_params($pg, $sql, [
                $runId,
                (string)$row['indicator_code'],
                (int)$row['year'],
                (int)$row['sub'],
                (string)($row['crop'] ?? ''),
                $row['value'] !== null ? (float)$row['value'] : null,
            ]);

            if ($res === false) {
                throw new RuntimeException('Postgres error: ' . pg_last_error($pg));
            }
        }
    }

    private static function updateRunDateRangeFromMetaOrYearly($pg, int $runId, array $meta): void
    {
        $periodStart = trim((string)($meta['period_start_guess'] ?? ''));
        $periodEnd   = trim((string)($meta['period_end_guess'] ?? ''));

        if ($periodStart !== '' && $periodEnd !== '') {
            self::pgExec(
                $pg,
                "UPDATE swat_runs
             SET period_start = " . pg_escape_literal($pg, $periodStart) . ",
                 period_end = " . pg_escape_literal($pg, $periodEnd) . ",
                 time_step = 'YEARLY'
             WHERE id = " . (int)$runId
            );
            return;
        }

        $range = self::pgOne($pg, "
        SELECT MIN(year) AS min_y, MAX(year) AS max_y
        FROM swat_indicator_yearly
        WHERE run_id = $1
    ", [$runId]);

        if ($range && !empty($range['min_y'])) {
            $fallbackStart = sprintf('%04d-01-01', (int)$range['min_y']);
            $fallbackEnd   = sprintf('%04d-12-31', (int)$range['max_y']);

            self::pgExec(
                $pg,
                "UPDATE swat_runs
             SET period_start = " . pg_escape_literal($pg, $fallbackStart) . ",
                 period_end = " . pg_escape_literal($pg, $fallbackEnd) . ",
                 time_step = 'YEARLY'
             WHERE id = " . (int)$runId
            );
        }
    }

    private static function insertCropAreaContextRows($pg, int $runId, array $rows): void
    {
        $sql = "
        INSERT INTO swat_crop_area_context
            (run_id, sub, crop, area_ha)
        VALUES
            ($1, $2, $3, $4)
        ON CONFLICT (run_id, sub, crop)
        DO UPDATE SET area_ha = EXCLUDED.area_ha
    ";

        foreach ($rows as $row) {
            $res = pg_query_params($pg, $sql, [
                $runId,
                (int)$row['sub'],
                (string)$row['crop'],
                (float)$row['area_ha'],
            ]);

            if ($res === false) {
                throw new RuntimeException('Postgres error: ' . pg_last_error($pg));
            }
        }
    }

    private static function insertIrrigationAreaContextRows($pg, int $runId, array $rows): void
    {
        $sql = "
        INSERT INTO swat_irrigation_area_context
            (run_id, sub, year, month, irrigated_area_ha)
        VALUES
            ($1, $2, $3, $4, $5)
        ON CONFLICT (run_id, sub, year, month)
        DO UPDATE SET irrigated_area_ha = EXCLUDED.irrigated_area_ha
    ";

        foreach ($rows as $row) {
            $res = pg_query_params($pg, $sql, [
                $runId,
                (int)$row['sub'],
                (int)$row['year'],
                (int)$row['month'],
                (float)$row['irrigated_area_ha'],
            ]);

            if ($res === false) {
                throw new RuntimeException('Postgres error: ' . pg_last_error($pg));
            }
        }
    }
}