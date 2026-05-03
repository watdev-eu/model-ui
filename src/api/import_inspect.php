<?php
// api/import_inspect.php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/SwatRawRunInspectService.php';

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
    echo json_encode([
        'ok' => false,
        'error' => 'Method not allowed']);
    exit;
}

$csrf = $_POST['csrf'] ?? '';
if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid CSRF token',
    ]);
    exit;
}

try {
    $result = SwatRawRunInspectService::inspectUploadedFiles($_FILES, $_POST);
    echo json_encode($result);
    exit;
} catch (Throwable $e) {
    $requestId = bin2hex(random_bytes(6));

    error_log(sprintf(
        '[import_inspect:%s] %s in %s:%d%s%s',
        $requestId,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        PHP_EOL,
        $e->getTraceAsString()
    ));

    $message = $e->getMessage();

    $isClientError = (
        str_starts_with($message, 'Missing required file:') ||
        str_contains($message, 'no valid HRU rows could be parsed') ||
        str_contains($message, 'no valid SNU rows could be parsed') ||
        str_starts_with($message, 'Unsupported import source.')
    );

    $isDebug = (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '') !== 'production');

    http_response_code($isClientError ? 422 : 500);

    echo json_encode([
        'ok' => false,
        'error' => $isClientError
            ? $message
            : 'Server error during inspection.',
        'detail' => $isDebug ? $message : null,
        'request_id' => $requestId,
    ]);
    exit;
}