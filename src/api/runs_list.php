<?php
// src/api/runs_list.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/SwatRunRepository.php';

header('Content-Type: application/json');

$studyArea = strtolower(trim($_GET['study_area'] ?? ''));

if ($studyArea === '') {
    http_response_code(400);
    echo json_encode(['error' => 'study_area is required']);
    exit;
}

try {
    $runs = SwatRunRepository::forStudyArea($studyArea);

    // Optional: filter here, e.g. only public runs
    // $runs = array_values(array_filter($runs, fn($r) => $r['visibility'] === 'public'));

    echo json_encode(['runs' => $runs]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}