<?php
// api/github_output_scenarios.php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/GitHubOutputImportService.php';

header('Content-Type: application/json');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!Auth::canAdvanced()) {
    http_response_code(Auth::isLoggedIn() ? 403 : 401);
    echo json_encode(['ok' => false, 'error' => 'Not authorised.']);
    exit;
}

try {
    echo json_encode(GitHubOutputImportService::listScenarios($_GET + $_POST));
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}