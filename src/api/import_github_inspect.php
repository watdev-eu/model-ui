<?php
// api/import_github_inspect.php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/GitHubOutputImportService.php';

header('Content-Type: application/json');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!Auth::canAdvanced()) {
    http_response_code(Auth::isLoggedIn() ? 403 : 401);
    echo json_encode([
        'ok' => false,
        'error' => Auth::isLoggedIn()
            ? 'You are not authorised to perform this action.'
            : 'You must be logged in.',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$csrf = $_POST['csrf'] ?? '';
if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

try {
    echo json_encode(GitHubOutputImportService::inspectScenario($_POST));
} catch (Throwable $e) {
    $requestId = bin2hex(random_bytes(6));

    error_log(sprintf(
        '[import_github_inspect:%s] %s in %s:%d%s%s',
        $requestId,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        PHP_EOL,
        $e->getTraceAsString()
    ));

    $message = $e->getMessage();
    $isDebug = (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '') !== 'production');

    http_response_code(422);

    echo json_encode([
        'ok' => false,
        'error' => $message,
        'detail' => $isDebug ? $message : null,
        'request_id' => $requestId,
    ]);
}