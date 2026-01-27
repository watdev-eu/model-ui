<?php
// config/app.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

// Load .env from project root
$paths = [ dirname(__DIR__,1).'/.env', __DIR__.'/.env' ];
foreach ($paths as $envFile) {
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line==='' || $line[0]==='#') continue;
            [$k,$v] = array_map('trim', explode('=', $line, 2));
            putenv("$k=$v");
        }
        break;
    }
}

function env(string $key, $default=null) {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

// sane defaults
date_default_timezone_set(env('APP_TZ','UTC'));

define('UPLOAD_DIR', env('UPLOAD_DIR', __DIR__.'/../var/uploads'));
if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);

// very small auth helper you can improve later
function require_admin(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['is_admin'])) { http_response_code(403); exit('Forbidden'); }
}