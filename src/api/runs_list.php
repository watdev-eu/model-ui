<?php
// src/api/runs_list.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/SwatRunRepository.php';

header('Content-Type: application/json');

$studyArea = trim($_GET['study_area'] ?? '');

if ($studyArea === '') {
    http_response_code(400);
    echo json_encode(['error' => 'study_area is required']);
    exit;
}

try {
    // This will accept either an id ("3") or a legacy name ("egypt")
    $runs = SwatRunRepository::forStudyArea($studyArea);

    // Keep only defaults OR public runs
    $runs = array_values(array_filter($runs, static function (array $r): bool {
        $isDefault  = !empty($r['is_default']);        // 1 / true
        $visibility = $r['visibility'] ?? null;
        return $isDefault || $visibility === 'public';
    }));

    // Sort – defaults first, then by newest date
    usort($runs, static function (array $a, array $b): int {
        $aDefault = !empty($a['is_default']);
        $bDefault = !empty($b['is_default']);

        if ($aDefault !== $bDefault) {
            return $aDefault ? -1 : 1; // defaults first
        }

        $da = $a['run_date'] ?? '';
        $db = $b['run_date'] ?? '';

        // newest first
        return strcmp($db, $da);
    });

    echo json_encode(['runs' => $runs]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}