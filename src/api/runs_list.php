<?php
// api/runs_list.php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/SwatRunRepository.php';
require_once __DIR__ . '/../classes/CustomScenarioRepository.php';
require_once __DIR__ . '/../classes/DashboardDatasetKey.php';

header('Content-Type: application/json');

$studyAreaId = isset($_GET['study_area_id']) ? (int)$_GET['study_area_id'] : 0;
$studyArea   = trim($_GET['study_area'] ?? '');

$userId = Auth::isLoggedIn() ? Auth::userId() : null;

function runIsDownloadableNow(array $run): bool
{
    if (empty($run['is_downloadable'])) {
        return false;
    }

    $fromDate = trim((string)($run['downloadable_from_date'] ?? ''));

    if ($fromDate === '') {
        return true;
    }

    $today = (new DateTimeImmutable('today'))->format('Y-m-d');

    return $fromDate <= $today;
}

try {
    if ($studyAreaId > 0) {
        $runs = SwatRunRepository::visibleForStudyAreaId($studyAreaId, $userId);
    } elseif ($studyArea !== '') {
        $allRuns = SwatRunRepository::forStudyArea($studyArea);

        if ($allRuns) {
            $studyAreaId = (int)($allRuns[0]['study_area_id'] ?? 0);
            $runs = SwatRunRepository::visibleForStudyAreaId($studyAreaId, $userId);
        } else {
            $runs = [];
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'study_area_id or study_area is required']);
        exit;
    }

    $runIds = array_map(fn($r) => (int)$r['id'], $runs);
    $subbasinsByRun = CustomScenarioRepository::runSubbasinsByRunIds($runIds);

    $runsById = [];
    foreach ($runs as $r) {
        $runsById[(int)$r['id']] = $r;
    }

    $customScenarios = ($studyAreaId > 0 && $userId !== null)
        ? CustomScenarioRepository::listForStudyAreaPublicAndUser($studyAreaId, $userId)
        : [];

    $items = [];

    foreach ($runs as $r) {
        $rawRunId = (int)$r['id'];
        $isOwner = $userId !== null && (string)($r['created_by'] ?? '') === (string)$userId;

        $items[] = [
            'id'                 => DashboardDatasetKey::makeRunKey($rawRunId),
            'dataset_type'       => 'run',
            'source_id'          => $rawRunId,
            'run_label'          => $r['run_label'],
            'is_default'         => (bool)$r['is_default'],
            'is_baseline'        => (bool)($r['is_baseline'] ?? false),
            'run_date'           => $r['run_date'] ?? null,
            'visibility'         => (string)($r['visibility'] ?? 'private'),
            'is_owner'           => $isOwner,
            'enabled_subbasins'  => $subbasinsByRun[$rawRunId] ?? [],

            'description'              => $r['description'] ?? '',
            'model_run_author'         => $r['model_run_author'] ?? '',
            'publication_url'          => $r['publication_url'] ?? null,
            'license_name'             => $r['license_name'] ?? null,
            'is_downloadable'          => (bool)($r['is_downloadable'] ?? false),
            'downloadable_from_date'   => $r['downloadable_from_date'] ?? null,
            'is_downloadable_now'      => runIsDownloadableNow($r),
        ];
    }

    foreach ($customScenarios as $cs) {
        $scenarioId = (int)$cs['id'];

        $isDownloadable = false;
        $downloadableFromDate = null;
        $downloadBlockedReason = null;

        try {
            $effectiveRunMap = CustomScenarioRepository::getEffectiveRunMapForUser($scenarioId, (string)$userId);
            $sourceRunIds = array_values(array_unique(array_map('intval', array_values($effectiveRunMap))));

            if (!$sourceRunIds) {
                $downloadBlockedReason = 'Custom scenario has no source runs.';
            } else {
                $isDownloadable = true;
                $latestFromDate = null;

                foreach ($sourceRunIds as $sourceRunId) {
                    $sourceRun = $runsById[$sourceRunId] ?? SwatRunRepository::find($sourceRunId);

                    if (!$sourceRun) {
                        $isDownloadable = false;
                        $downloadBlockedReason = 'Custom scenario contains a source run that no longer exists.';
                        break;
                    }

                    if (!SwatRunRepository::userCanAccess($sourceRunId, $userId)) {
                        $isDownloadable = false;
                        $downloadBlockedReason = 'Custom scenario contains a source run you cannot access.';
                        break;
                    }

                    if (empty($sourceRun['is_downloadable'])) {
                        $isDownloadable = false;
                        $downloadBlockedReason = 'Custom scenario contains a source run that is not downloadable.';
                        break;
                    }

                    $fromDate = trim((string)($sourceRun['downloadable_from_date'] ?? ''));

                    if ($fromDate !== '') {
                        if ($latestFromDate === null || $fromDate > $latestFromDate) {
                            $latestFromDate = $fromDate;
                        }
                    }
                }

                $downloadableFromDate = $latestFromDate;

                if ($isDownloadable && $downloadableFromDate !== null) {
                    $today = (new DateTimeImmutable('today'))->format('Y-m-d');

                    if ($downloadableFromDate > $today) {
                        $isDownloadable = false;
                        $downloadBlockedReason = 'Custom scenario is not downloadable yet.';
                    }
                }
            }
        } catch (Throwable $e) {
            $isDownloadable = false;
            $downloadBlockedReason = 'Custom scenario download availability could not be determined.';
        }

        $items[] = [
            'id'                 => DashboardDatasetKey::makeCustomScenarioKey($scenarioId),
            'dataset_type'       => 'custom',
            'source_id'          => $scenarioId,
            'run_label'          => $cs['name'],
            'is_default'         => false,
            'is_baseline'        => false,
            'run_date'           => null,
            'visibility'         => 'private',
            'is_owner'           => true,
            'enabled_subbasins'  => [],

            'description'              => $cs['description'] ?? '',
            'model_run_author'         => '',
            'publication_url'          => null,
            'license_name'             => null,
            'is_downloadable'          => $isDownloadable,
            'downloadable_from_date'   => $downloadableFromDate,
            'is_downloadable_now'      => $isDownloadable,
            'download_blocked_reason'  => $downloadBlockedReason,
        ];
    }

    echo json_encode([
        'ok'   => true,
        'runs' => $items,
    ]);

} catch (Throwable $e) {
    error_log('[runs_list] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}