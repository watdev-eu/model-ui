<?php
// classes/SwatRawRunInspectService.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/CropRepository.php';
require_once __DIR__ . '/SwatRawImportHelper.php';

final class SwatRawRunInspectService
{
    public static function inspectUploadedFiles(array $files, array $post = []): array
    {
        $sourceType = trim((string)($post['import_source'] ?? 'original'));
        if (!in_array($sourceType, ['original', 'csv'], true)) {
            throw new RuntimeException('Unsupported import source.');
        }

        self::assertRequiredUploads($files, $sourceType);

        $token   = bin2hex(random_bytes(16));
        $baseDir = rtrim(UPLOAD_DIR, '/\\') . '/import_sessions/' . $token;

        $parentDir = dirname($baseDir);
        if (!is_dir($parentDir) && !mkdir($parentDir, 0775, true) && !is_dir($parentDir)) {
            $err = error_get_last();
            throw new RuntimeException(
                'Failed to create import_sessions directory: ' . $parentDir .
                ($err && isset($err['message']) ? ' — ' . $err['message'] : '')
            );
        }

        if (!is_writable($parentDir)) {
            throw new RuntimeException('Import sessions directory is not writable: ' . $parentDir);
        }

        if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            $err = error_get_last();
            throw new RuntimeException(
                'Failed to create temporary import directory: ' . $baseDir .
                ($err && isset($err['message']) ? ' — ' . $err['message'] : '')
            );
        }

        if ($sourceType === 'original') {
            $paths = [
                'cio' => $baseDir . '/file.cio',
                'hru' => $baseDir . '/output.hru',
                'rch' => $baseDir . '/output.rch',
                'snu' => $baseDir . '/output.snu',
            ];

            SwatRawImportHelper::saveUploadedFile($files['cio_file'], $paths['cio']);
            SwatRawImportHelper::saveUploadedFile($files['hru_file'], $paths['hru']);
            SwatRawImportHelper::saveUploadedFile($files['snu_file'], $paths['snu']);

            if (!empty($files['rch_file']) && (int)($files['rch_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                SwatRawImportHelper::saveUploadedFile($files['rch_file'], $paths['rch']);
            } else {
                $paths['rch'] = null;
            }
        } else {
            $paths = [
                'cio' => null,
                'hru' => $baseDir . '/hru.csv',
                'rch' => $baseDir . '/rch.csv',
                'snu' => $baseDir . '/snu.csv',
            ];

            SwatRawImportHelper::saveUploadedFile($files['hru_csv_file'], $paths['hru']);
            SwatRawImportHelper::saveUploadedFile($files['snu_csv_file'], $paths['snu']);

            if (!empty($files['rch_csv_file']) && (int)($files['rch_csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                SwatRawImportHelper::saveUploadedFile($files['rch_csv_file'], $paths['rch']);
            } else {
                $paths['rch'] = null;
            }
        }

        $inspect = SwatRawImportHelper::inspectRawSet(
            $paths['cio'],
            $paths['hru'],
            $paths['rch'],
            $paths['snu'],
            $sourceType
        );

        self::assertInspectionSucceeded($inspect);

        $unknownCrops = self::detectUnknownCrops($inspect['all_crop_codes'] ?? []);

        $meta = [
            'created_at' => date(DATE_ATOM),
            'token' => $token,
            'source_type' => $sourceType,
            'cio' => $inspect['cio'],
            'files' => [
                'cio' => $paths['cio'] ? basename((string)$paths['cio']) : null,
                'hru' => basename((string)$paths['hru']),
                'rch' => $paths['rch'] ? basename((string)$paths['rch']) : null,
                'snu' => basename((string)$paths['snu']),
            ],
            'all_crop_codes' => $inspect['all_crop_codes'],
            'all_subbasins' => $inspect['all_subbasins'],
            'period_start_guess' => $inspect['period_start_guess'],
            'period_end_guess' => $inspect['period_end_guess'],
        ];

        $metaJson = json_encode($meta, JSON_PRETTY_PRINT);
        if ($metaJson === false) {
            throw new RuntimeException('Failed to encode import session metadata.');
        }

        if (file_put_contents($baseDir . '/meta.json', $metaJson) === false) {
            throw new RuntimeException('Failed to save import session metadata.');
        }

        return [
            'ok' => true,
            'import_token' => $token,
            'source_type' => $sourceType,
            'cio' => $inspect['cio'],
            'inspections' => $inspect['inspections'],
            'unknown_crops' => $unknownCrops,
            'detected_subbasins' => $inspect['all_subbasins'],
            'period_start_guess' => $inspect['period_start_guess'],
            'period_end_guess' => $inspect['period_end_guess'],
        ];
    }

    private static function assertRequiredUploads(array $files, string $sourceType): void
    {
        $required = $sourceType === 'csv'
            ? ['hru_csv_file', 'snu_csv_file']
            : ['cio_file', 'hru_file', 'snu_file'];

        foreach ($required as $field) {
            if (empty($files[$field]) || (int)($files[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException("Missing required file: {$field}");
            }
        }
    }

    private static function detectUnknownCrops(array $allCropCodes): array
    {
        $knownCrops = [];
        foreach (CropRepository::all() as $row) {
            $knownCrops[strtoupper((string)$row['code'])] = (string)$row['name'];
        }

        $unknown = [];
        foreach ($allCropCodes as $code) {
            $uc = strtoupper((string)$code);
            if (!isset($knownCrops[$uc])) {
                $unknown[] = $uc;
            }
        }

        sort($unknown);
        return $unknown;
    }

    private static function assertInspectionSucceeded(array $inspect): void
    {
        $hruOk = (bool)($inspect['inspections']['hru']['ok'] ?? false);
        $snuOk = (bool)($inspect['inspections']['snu']['ok'] ?? false);

        if (!$hruOk) {
            throw new RuntimeException('The HRU file was uploaded, but no valid HRU rows could be parsed.');
        }

        if (!$snuOk) {
            throw new RuntimeException('The SNU file was uploaded, but no valid SNU rows could be parsed.');
        }
    }
}