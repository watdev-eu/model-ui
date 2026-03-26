<?php
// src/api/mca_presets_list.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/McaPresetRepository.php';
require_once __DIR__ . '/../classes/Auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$studyAreaId = (int)($_GET['study_area_id'] ?? 0);
Auth::requireLogin();
$userId = Auth::userId();
if ($userId === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($studyAreaId <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'study_area_id is required']);
    exit;
}

try {
    $presets = McaPresetRepository::listForStudyArea($studyAreaId, $userId);
    echo json_encode(['ok' => true, 'presets' => $presets]);
} catch (Throwable $e) {
    error_log('[mca_presets_list] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}