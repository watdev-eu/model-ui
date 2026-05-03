<?php
// src/api/runs_admin.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/SwatRunRepository.php';

header('Content-Type: application/json');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!Auth::canAdmin()) {
    http_response_code(Auth::isLoggedIn() ? 403 : 401);
    echo json_encode([
        'error' => Auth::isLoggedIn()
            ? 'You are not authorised to perform this action.'
            : 'You must be logged in.',
    ]);
    exit;
}

// CSRF check
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
                'study_area' => isset($run['study_area']) ? (int)$run['study_area'] : null,
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

        case 'update_metadata':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(422);
                echo json_encode(['error' => 'Invalid run id']);
                exit;
            }

            $run = SwatRunRepository::updateMetadata($id, [
                'run_label' => $_POST['run_label'] ?? '',
                'run_date' => $_POST['run_date'] ?? '',
                'model_run_author' => $_POST['model_run_author'] ?? '',
                'publication_url' => $_POST['publication_url'] ?? '',
                'license_id' => $_POST['license_id'] ?? '',
                'visibility' => $_POST['visibility'] ?? 'private',
                'is_default' => ($_POST['is_default'] ?? '0') === '1',
                'is_downloadable' => isset($_POST['is_downloadable']),
                'downloadable_from_date' => $_POST['downloadable_from_date'] ?? '',
                'description' => $_POST['description'] ?? '',
            ]);

            echo json_encode([
                'ok' => true,
                'run' => $run,
            ]);
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
    echo json_encode([
        'error' => 'Server error',
        'detail' => $e->getMessage(), // temporary during development
    ]);
}