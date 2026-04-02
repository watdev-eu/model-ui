<?php
// classes/SwatRawRunInspectService.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/CropRepository.php';
require_once __DIR__ . '/SwatRawImportHelper.php';

final class SwatRawRunInspectService
{
    public static function inspectUploadedFiles(array $files): array
    {
        self::assertRequiredUploads($files);

        $token   = bin2hex(random_bytes(16));
        $baseDir = rtrim(UPLOAD_DIR, '/\\') . '/import_sessions/' . $token;

        if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            throw new RuntimeException('Failed to create temporary import directory.');
        }

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

        $inspect = SwatRawImportHelper::inspectRawSet(
            $paths['cio'],
            $paths['hru'],
            $paths['rch'],
            $paths['snu']
        );

        $unknownCrops = self::detectUnknownCrops($inspect['all_crop_codes'] ?? []);

        $meta = [
            'created_at' => date(DATE_ATOM),
            'token' => $token,
            'cio' => $inspect['cio'],
            'files' => [
                'cio' => 'file.cio',
                'hru' => 'output.hru',
                'rch' => $paths['rch'] ? 'output.rch' : null,
                'snu' => 'output.snu',
            ],
            'all_crop_codes' => $inspect['all_crop_codes'],
            'all_subbasins' => $inspect['all_subbasins'],
            'period_start_guess' => $inspect['period_start_guess'],
            'period_end_guess' => $inspect['period_end_guess'],
        ];

        file_put_contents($baseDir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT));

        return [
            'ok' => true,
            'import_token' => $token,
            'cio' => $inspect['cio'],
            'inspections' => $inspect['inspections'],
            'unknown_crops' => $unknownCrops,
            'detected_subbasins' => $inspect['all_subbasins'],
            'period_start_guess' => $inspect['period_start_guess'],
            'period_end_guess' => $inspect['period_end_guess'],
        ];
    }

    private static function assertRequiredUploads(array $files): void
    {
        foreach (['cio_file', 'hru_file', 'snu_file'] as $field) {
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
}