<?php
// api/import_inspect.php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/ImportCsvHelper.php';
require_once __DIR__ . '/../classes/CropRepository.php';

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

try {
    $fieldSep = ImportCsvHelper::normalizeFieldSep((string)($_POST['field_sep'] ?? ';'));

    $hasAny = false;
    foreach (['hru_csv', 'rch_csv', 'snu_csv'] as $k) {
        if (!empty($_FILES[$k]) && (int)($_FILES[$k]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $hasAny = true;
            break;
        }
    }

    if (!$hasAny) {
        http_response_code(422);
        echo json_encode(['error' => 'Please upload at least one CSV file.']);
        exit;
    }

    $token = bin2hex(random_bytes(16));
    $baseDir = rtrim(UPLOAD_DIR, '/\\') . '/import_sessions/' . $token;

    if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
        throw new RuntimeException('Failed to create temporary import directory.');
    }

    $files = [];
    $inspections = [];
    $allCropCodes = [];
    $allSubbasins = [];
    $periodStartGuess = null;
    $periodEndGuess = null;

    foreach (['hru', 'rch', 'snu'] as $type) {
        $field = $type . '_csv';
        if (empty($_FILES[$field]) || (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }

        $target = $baseDir . '/' . $type . '.csv';
        ImportCsvHelper::saveUploadedFile($_FILES[$field], $target);
        $files[$type] = $target;

        $info = ImportCsvHelper::inspect($target, $type, $fieldSep);
        $info['preview_html'] = ImportCsvHelper::buildPreviewTable($info['header'], $info['preview_rows']);
        $inspections[$type] = $info;

        if (!$info['ok']) {
            http_response_code(422);
            echo json_encode([
                'error' => strtoupper($type) . ' file is missing required columns.',
                'inspection' => $info,
            ]);
            exit;
        }

        foreach ($info['crop_codes'] as $code) {
            $allCropCodes[$code] = true;
        }
        foreach ($info['detected_subbasins'] as $sub) {
            $allSubbasins[(int)$sub] = true;
        }

        if (!empty($info['period_start_guess'])) {
            $periodStartGuess = $periodStartGuess === null || $info['period_start_guess'] < $periodStartGuess
                ? $info['period_start_guess']
                : $periodStartGuess;
        }
        if (!empty($info['period_end_guess'])) {
            $periodEndGuess = $periodEndGuess === null || $info['period_end_guess'] > $periodEndGuess
                ? $info['period_end_guess']
                : $periodEndGuess;
        }
    }

    $knownCrops = [];
    foreach (CropRepository::all() as $row) {
        $knownCrops[strtoupper((string)$row['code'])] = (string)$row['name'];
    }

    $unknownCrops = [];
    foreach (array_keys($allCropCodes) as $code) {
        if (!isset($knownCrops[$code])) {
            $unknownCrops[] = $code;
        }
    }
    sort($unknownCrops);

    file_put_contents($baseDir . '/meta.json', json_encode([
        'created_at' => date(DATE_ATOM),
        'token' => $token,
        'field_sep' => $fieldSep,
        'files' => array_keys($files),
        'all_crop_codes' => array_values(array_keys($allCropCodes)),
        'all_subbasins' => array_map('intval', array_values(array_keys($allSubbasins))),
        'period_start_guess' => $periodStartGuess,
        'period_end_guess' => $periodEndGuess,
    ], JSON_PRETTY_PRINT));

    echo json_encode([
        'ok' => true,
        'import_token' => $token,
        'inspections' => $inspections,
        'unknown_crops' => $unknownCrops,
        'detected_subbasins' => array_map('intval', array_values(array_keys($allSubbasins))),
        'period_start_guess' => $periodStartGuess,
        'period_end_guess' => $periodEndGuess,
    ]);
} catch (Throwable $e) {
    error_log('[import_inspect] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}