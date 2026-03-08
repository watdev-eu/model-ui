<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/classes/Auth.php';

try {
    $error = isset($_GET['error']) ? trim((string)$_GET['error']) : '';
    if ($error !== '') {
        throw new RuntimeException('Login failed: ' . $error);
    }

    $code  = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
    $state = isset($_GET['state']) ? trim((string)$_GET['state']) : '';

    if ($code === '' || $state === '') {
        throw new RuntimeException('Missing code or state.');
    }

    Auth::handleCallback($code, $state);

    $redirect = $_SESSION['post_login_redirect'] ?? '/';
    unset($_SESSION['post_login_redirect']);

    if (!is_string($redirect) || $redirect === '' || str_starts_with($redirect, 'http://') || str_starts_with($redirect, 'https://')) {
        $redirect = '/';
    }

    header('Location: ' . $redirect);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Login error</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="p-4">
    <div class="container">
        <div class="alert alert-danger">
            <h1 class="h4 mb-2">Login failed</h1>
            <div><?= htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <a class="btn btn-primary" href="/login.php">Try again</a>
        <a class="btn btn-outline-secondary" href="/">Back to home</a>
    </div>
    </body>
    </html>
    <?php
}