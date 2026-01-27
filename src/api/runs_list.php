<?php
// src/api/runs_list.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/SwatRunRepository.php';

header('Content-Type: application/json');

// Accept new param: ?study_area_id=14
$studyAreaId = isset($_GET['study_area_id']) ? (int)$_GET['study_area_id'] : 0;

// Accept legacy param: ?study_area=dashboard
$studyArea = trim($_GET['study_area'] ?? '');

try {
    if ($studyAreaId > 0) {
        // New way – preferred
        $runs = SwatRunRepository::forStudyAreaId($studyAreaId);
    } elseif ($studyArea !== '') {
        // Legacy fallback
        $runs = SwatRunRepository::forStudyArea($studyArea);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'study_area_id or study_area is required']);
        exit;
    }

    // Keep only default or public runs
    $runs = array_values(array_filter($runs, function ($r) {
        return !empty($r['is_default']) || ($r['visibility'] ?? '') === 'public';
    }));

    echo json_encode([
        'ok' => true,
        'runs' => array_map(function ($r) {
            return [
                'id'        => (int)$r['id'],
                'run_label' => $r['run_label'],
                'is_default'=> (bool)$r['is_default'],
                'is_baseline'=> (bool)($r['is_baseline'] ?? false),
                'run_date'  => $r['run_date'] ?? null,
            ];
        }, $runs)
    ]);

} catch (Throwable $e) {
    error_log('[runs_list] '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}