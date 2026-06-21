<?php
// src/api/crops_admin.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CropRepository.php';

header('Content-Type: application/json');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!Auth::isAdmin()) {
    http_response_code(Auth::isLoggedIn() ? 403 : 401);
    echo json_encode([
        'error' => Auth::isLoggedIn()
            ? 'You are not authorised to perform this action.'
            : 'You must be logged in to perform this action.',
    ]);
    exit;
}

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// CSRF check
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$csrf = $_POST['csrf'] ?? '';
if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'save':
            $code          = $_POST['code'] ?? '';
            $name          = $_POST['name'] ?? '';
            $originalCode  = $_POST['original_code'] ?? $code;

            $code = trim($code);
            $name = trim($name);
            $originalCode = trim($originalCode);

            $dryMatterRaw = trim((string)($_POST['dry_matter_fraction'] ?? ''));
            $dryMatterFraction = null;

            if ($dryMatterRaw !== '') {
                if (!is_numeric($dryMatterRaw)) {
                    http_response_code(422);
                    echo json_encode(['error' => 'Dry matter fraction must be a number']);
                    exit;
                }

                $dryMatterFraction = (float)$dryMatterRaw;

                if ($dryMatterFraction <= 0 || $dryMatterFraction > 1) {
                    http_response_code(422);
                    echo json_encode(['error' => 'Dry matter fraction must be between 0 and 1']);
                    exit;
                }
            }

            if ($code === '' || !preg_match('/^[A-Za-z0-9_]{1,8}$/', $code)) {
                http_response_code(422);
                echo json_encode(['error' => 'Invalid code']);
                exit;
            }

            try {
                if ($originalCode !== '' && $originalCode !== $code) {
                    CropRepository::renameAndUpdate($originalCode, $code, $name, $dryMatterFraction);
                } else {
                    CropRepository::upsert($code, $name, $dryMatterFraction);
                }
            } catch (PDOException $e) {
                // Most likely unique violation on duplicate code
                http_response_code(409);
                echo json_encode(['error' => 'Code already exists']);
                exit;
            }

            echo json_encode([
                'ok'   => true,
                'crop' => [
                    'code' => $code,
                    'name' => $name,
                    'dry_matter_fraction' => $dryMatterFraction,
                ],
            ]);
            break;

        case 'delete':
            $code = trim($_POST['code'] ?? '');
            if ($code === '') {
                http_response_code(422);
                echo json_encode(['error' => 'Code is required']);
                exit;
            }
            CropRepository::delete($code);
            echo json_encode(['ok' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }

} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[crops_admin] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}