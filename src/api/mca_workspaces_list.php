<?php
// api/mca_workspaces_list.php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/McaWorkspaceRepository.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

Auth::requireLogin();
$userId = Auth::userId();
if ($userId === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$studyAreaId = (int)($_GET['study_area_id'] ?? 0);
if ($studyAreaId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'study_area_id is required']);
    exit;
}

try {
    $rows = McaWorkspaceRepository::listForStudyArea($studyAreaId, $userId);
    echo json_encode(['ok' => true, 'workspaces' => $rows]);
} catch (Throwable $e) {
    error_log('[mca_workspaces_list] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}