<?php
// api/import_inspect.php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/SwatRawRunInspectService.php';

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
    $result = SwatRawRunInspectService::inspectUploadedFiles($_FILES);
    echo json_encode($result);
    exit;
} catch (Throwable $e) {
    error_log('[import_inspect] ' . $e->getMessage());

    $message = $e->getMessage();
    $status = str_starts_with($message, 'Missing required file:') ? 422 : 500;

    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}