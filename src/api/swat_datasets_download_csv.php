<?php
// api/swat_datasets_download_csv.php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/DashboardDatasetKey.php';
require_once __DIR__ . '/../classes/SwatRunRepository.php';
require_once __DIR__ . '/../classes/CustomScenarioRepository.php';
require_once __DIR__ . '/../classes/CropRepository.php';

$raw = trim((string)($_GET['dataset_ids'] ?? ''));

if ($raw === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'dataset_ids is required']);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$userId = Auth::isLoggedIn() ? Auth::userId() : null;

$datasetKeys = array_values(array_unique(array_filter(array_map(
    'trim',
    explode(',', $raw)
))));

if (!$datasetKeys) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No valid dataset ids provided']);
    exit;
}

$plans = [];

try {
    $cropLookup = [];
    foreach (CropRepository::all() as $crop) {
        $code = (string)($crop['code'] ?? '');
        if ($code !== '') {
            $cropLookup[$code] = (string)($crop['name'] ?? '');
        }
    }

    foreach ($datasetKeys as $datasetKey) {
        $parsed = DashboardDatasetKey::parse($datasetKey);

        if ($parsed['type'] === 'run') {
            $runId = (int)$parsed['id'];
            $policy = SwatRunRepository::downloadPolicy($runId, $userId);

            if (!$policy['allowed']) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => $policy['reason'],
                    'dataset_id' => $datasetKey,
                ]);
                exit;
            }

            $plans[] = [
                'type' => 'run',
                'dataset_key' => $datasetKey,
                'dataset_label' => (string)($policy['run']['run_label'] ?? ('Run ' . $runId)),
                'run_id' => $runId,
                'run' => $policy['run'],
            ];

            continue;
        }

        if ($parsed['type'] === 'custom') {
            if (!$userId) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'You must be logged in to download custom scenarios']);
                exit;
            }

            $scenarioId = (int)$parsed['id'];
            $scenario = CustomScenarioRepository::findByIdForUser($scenarioId, $userId);

            if (!$scenario) {
                http_response_code(404);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Custom scenario not found']);
                exit;
            }

            $effectiveRunMap = CustomScenarioRepository::getEffectiveRunMapForUser($scenarioId, $userId);
            $sourceRunIds = array_values(array_unique(array_map('intval', array_values($effectiveRunMap))));

            foreach ($sourceRunIds as $sourceRunId) {
                $policy = SwatRunRepository::downloadPolicy($sourceRunId, $userId);

                if (!$policy['allowed']) {
                    http_response_code(403);
                    header('Content-Type: application/json');
                    echo json_encode([
                        'error' => 'Custom scenario contains a source run that is not downloadable: ' . $policy['reason'],
                        'dataset_id' => $datasetKey,
                        'source_run_id' => $sourceRunId,
                    ]);
                    exit;
                }
            }

            $plans[] = [
                'type' => 'custom',
                'dataset_key' => $datasetKey,
                'dataset_label' => (string)($scenario['name'] ?? ('Custom scenario ' . $scenarioId)),
                'scenario_id' => $scenarioId,
                'effective_run_map' => $effectiveRunMap,
                'source_run_ids' => $sourceRunIds,
            ];
        }
    }

    $filename = 'swat-selected-datasets-' . date('Ymd-His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');

    fputcsv($out, [
        'dataset_key',
        'dataset_label',
        'dataset_type',
        'source_run_id',
        'source_run_label',
        'indicator_code',
        'year',
        'sub',
        'crop',
        'crop_name',
        'value',
    ]);

    $pdo = Database::pdo();

    foreach ($plans as $plan) {
        if ($plan['type'] === 'run') {
            streamRunRows(
                $pdo,
                $out,
                (int)$plan['run_id'],
                (string)$plan['dataset_key'],
                (string)$plan['dataset_label'],
                'run',
                null,
                $cropLookup
            );

            continue;
        }

        if ($plan['type'] === 'custom') {
            $effectiveRunMap = $plan['effective_run_map'];

            foreach ($plan['source_run_ids'] as $sourceRunId) {
                streamRunRows(
                    $pdo,
                    $out,
                    (int)$sourceRunId,
                    (string)$plan['dataset_key'],
                    (string)$plan['dataset_label'],
                    'custom',
                    $effectiveRunMap,
                    $cropLookup
                );
            }
        }
    }

    fclose($out);
    exit;
} catch (Throwable $e) {
    error_log('[swat_datasets_download_csv] ' . $e->getMessage());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }

    echo json_encode(['error' => 'Server error']);
    exit;
}

function streamRunRows(
    PDO $pdo,
        $out,
    int $runId,
    string $datasetKey,
    string $datasetLabel,
    string $datasetType,
    ?array $effectiveRunMap,
    array $cropLookup
): void {
    $run = SwatRunRepository::find($runId);
    $sourceRunLabel = (string)($run['run_label'] ?? ('Run ' . $runId));

    $stmt = $pdo->prepare("
        SELECT
            run_id,
            indicator_code,
            year,
            sub,
            NULLIF(crop, '') AS crop,
            value
        FROM swat_indicator_yearly
        WHERE run_id = :run_id
        ORDER BY indicator_code, year, sub, crop
    ");

    $stmt->execute([':run_id' => $runId]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sub = (int)($row['sub'] ?? 0);

        if ($effectiveRunMap !== null) {
            $effectiveRunIdForSub = (int)($effectiveRunMap[$sub] ?? 0);

            if ($effectiveRunIdForSub !== $runId) {
                continue;
            }
        }

        $cropCode = (string)($row['crop'] ?? '');
        $cropName = $cropCode !== '' ? ($cropLookup[$cropCode] ?? '') : '';

        fputcsv($out, [
            $datasetKey,
            $datasetLabel,
            $datasetType,
            $runId,
            $sourceRunLabel,
            $row['indicator_code'],
            $row['year'],
            $row['sub'],
            $cropCode,
            $cropName,
            $row['value'],
        ]);
    }
}