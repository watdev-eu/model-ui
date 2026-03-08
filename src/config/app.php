<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

// Load .env from project root
$paths = [
    dirname(__DIR__, 2) . '/.env', // /var/www/.env
    dirname(__DIR__, 1) . '/.env', // /var/www/html/.env
    __DIR__ . '/.env',             // /var/www/html/config/.env
];
foreach ($paths as $envFile) {
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line === '' || $line[0] === '#') continue;
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            putenv("$k=$v");
        }
        break;
    }
}

function env(string $key, $default = null) {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

date_default_timezone_set(env('APP_TZ', 'UTC'));

define('UPLOAD_DIR', env('UPLOAD_DIR', __DIR__ . '/../var/uploads'));
if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0775, true);
}

function app_version_short(): string {
    $sha = getenv('APP_VERSION') ?: env('APP_VERSION', 'unknown');
    return substr((string)$sha, 0, 7);
}

function app_build_date(): string {
    return (string)(getenv('APP_BUILD_DATE') ?: env('APP_BUILD_DATE', ''));
}

function current_user(): ?array {
    return $_SESSION['auth']['user'] ?? null;
}

function current_user_roles(): array {
    $u = current_user();
    return is_array($u) ? array_values(array_unique(array_map('strval', $u['roles'] ?? []))) : [];
}

function is_logged_in(): bool {
    return current_user() !== null;
}

function has_role(string $role): bool {
    return in_array($role, current_user_roles(), true);
}

function require_login(): void {
    if (is_logged_in()) return;
    $_SESSION['post_login_redirect'] = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: /login.php');
    exit;
}

function require_admin(): void {
    if (!has_role('admin')) {
        http_response_code(403);
        exit('Forbidden');
    }
}