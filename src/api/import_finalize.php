<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (!ob_get_level()) {
    ob_start();
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/SwatRawRunImportService.php';

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

    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}