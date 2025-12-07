<?php
// src/api/runs_admin.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/SwatRunRepository.php';

header('Content-Type: application/json');

// Only admins
//require_admin();

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
$id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;

try {
    if ($id <= 0 && $action !== 'noop') {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid run id']);
        exit;
    }

    switch ($action) {
        case 'toggle_default':
            $isDefault = SwatRunRepository::toggleDefault($id);
            $run       = SwatRunRepository::find($id); // to get updated visibility & area

            echo json_encode([
                'ok'         => true,
                'is_default' => $isDefault,
                'visibility' => $run['visibility'] ?? 'private',
                'study_area' => $run['study_area'] ?? null,
            ]);
            break;

        case 'toggle_visibility':
            $visibility = SwatRunRepository::toggleVisibility($id);
            echo json_encode([
                'ok'         => true,
                'visibility' => $visibility,
            ]);
            break;

        case 'delete':
            SwatRunRepository::delete($id);
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
    error_log('[runs_admin] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}