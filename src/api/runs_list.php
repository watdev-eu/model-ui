<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/SwatRunRepository.php';
require_once __DIR__ . '/../classes/CustomScenarioRepository.php';
require_once __DIR__ . '/../classes/DashboardDatasetKey.php';

header('Content-Type: application/json');

$studyAreaId = isset($_GET['study_area_id']) ? (int)$_GET['study_area_id'] : 0;
$studyArea = trim($_GET['study_area'] ?? '');

try {
    if ($studyAreaId > 0) {
        $runs = SwatRunRepository::forStudyAreaId($studyAreaId);
    } elseif ($studyArea !== '') {
        $runs = SwatRunRepository::forStudyArea($studyArea);
        if ($runs) {
            $studyAreaId = (int)($runs[0]['study_area_id'] ?? 0);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'study_area_id or study_area is required']);
        exit;
    }

    $runs = array_values(array_filter($runs, function ($r) {
        return !empty($r['is_default']) || ($r['visibility'] ?? '') === 'public';
    }));

    $userId = Auth::isLoggedIn() ? Auth::userId() : null;
    $customScenarios = ($studyAreaId > 0)
        ? CustomScenarioRepository::listForStudyAreaPublicAndUser($studyAreaId, $userId)
        : [];

    $items = [];

    foreach ($runs as $r) {
        $items[] = [
            'id'          => DashboardDatasetKey::makeRunKey((int)$r['id']),
            'dataset_type'=> 'run',
            'source_id'   => (int)$r['id'],
            'run_label'   => $r['run_label'],
            'is_default'  => (bool)$r['is_default'],
            'is_baseline' => (bool)($r['is_baseline'] ?? false),
            'run_date'    => $r['run_date'] ?? null,
        ];
    }

    foreach ($customScenarios as $cs) {
        $items[] = [
            'id'          => DashboardDatasetKey::makeCustomScenarioKey((int)$cs['id']),
            'dataset_type'=> 'custom',
            'source_id'   => (int)$cs['id'],
            'run_label'   => $cs['name'],
            'is_default'  => false,
            'is_baseline' => false,
            'run_date'    => null,
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