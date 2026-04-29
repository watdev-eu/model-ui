<?php
// api/import_finalize.php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (!ob_get_level()) {
    ob_start();
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/SwatRawRunImportService.php';

header('Content-Type: application/json');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!Auth::canAdvanced()) {
    http_response_code(Auth::isLoggedIn() ? 403 : 401);
    echo json_encode([
        'error' => Auth::isLoggedIn()
            ? 'You are not authorised to perform this action.'
            : 'You must be logged in.',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$csrf = $_POST['csrf'] ?? '';
if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

function importFinalizeStatusForMessage(string $message): int
{
    $validationStarts = [
        'Invalid import token.',
        'Import session not found or expired.',
        'Import session metadata is invalid.',
        'Study area is required.',
        'Run name is required.',
        'Model run date is required.',
        'Model run author is required.',
        'Selected subbasins are invalid.',
        'Please select at least one subbasin.',
        'Unknown crop names are invalid.',
        'License is required.',
        'Description is required.',
        'Missing import timing metadata.',
        'Missing parsed file.cio metadata.',
        'One or more required uploaded files are missing.',
        'A run with this name already exists for this study area.',
        'Invalid or disabled study area.',
    ];

    foreach ($validationStarts as $prefix) {
        if (str_starts_with($message, $prefix)) {
            return 422;
        }
    }

    if (
        str_starts_with($message, 'Selected subbasin ') ||
        str_starts_with($message, 'Subbasin ') ||
        str_starts_with($message, 'Please provide a name for crop code ')
    ) {
        return 422;
    }

    return 500;
}

try {
    $result = SwatRawRunImportService::importFromSession($_POST);

    if (ob_get_level()) {
        ob_clean();
    }

    echo json_encode($result);
    exit;
} catch (Throwable $e) {
    error_log('[import_finalize] ' . $e->getMessage());

    if (ob_get_level()) {
        ob_clean();
    }

    $message = $e->getMessage();
    $status = importFinalizeStatusForMessage($message);

    http_response_code($status);

    echo json_encode([
        'error' => $status === 422 ? $message : 'Server error'
    ]);
    exit;
}