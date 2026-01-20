<?php
// src/api/mca_presets_admin.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/McaPresetRepository.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// CSRF
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$csrf = $_POST['csrf'] ?? '';
if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0); // adjust to your auth
$action = $_POST['action'] ?? '';

try {
    switch ($action) {

        case 'create_set': {
            $studyAreaId = (int)($_POST['study_area_id'] ?? 0);
            $name        = trim((string)($_POST['name'] ?? ''));
            if ($studyAreaId <= 0 || $name === '') {
                http_response_code(422);
                echo json_encode(['error' => 'study_area_id and name are required']);
                exit;
            }
            $id = McaPresetRepository::createUserSet($studyAreaId, $userId, $name);
            echo json_encode(['ok' => true, 'preset_set_id' => $id]);
            break;
        }

        case 'clone_default_to_user': {
            $studyAreaId = (int)($_POST['study_area_id'] ?? 0);
            $name        = trim((string)($_POST['name'] ?? 'My MCA preset'));
            if ($studyAreaId <= 0) {
                http_response_code(422);
                echo json_encode(['error' => 'study_area_id is required']);
                exit;
            }
            $id = McaPresetRepository::cloneDefaultToUser($studyAreaId, $userId, $name);
            echo json_encode(['ok' => true, 'preset_set_id' => $id]);
            break;
        }

        case 'save_items': {
            $presetSetId = (int)($_POST['preset_set_id'] ?? 0);
            $itemsJson   = (string)($_POST['items_json'] ?? '');
            if ($presetSetId <= 0 || $itemsJson === '') {
                http_response_code(422);
                echo json_encode(['error' => 'preset_set_id and items_json are required']);
                exit;
            }
            $items = json_decode($itemsJson, true);
            if (!is_array($items)) {
                http_response_code(422);
                echo json_encode(['error' => 'items_json must be valid JSON']);
                exit;
            }

            McaPresetRepository::assertCanEdit($presetSetId, $userId);
            McaPresetRepository::saveItems($presetSetId, $items);

            echo json_encode(['ok' => true]);
            break;
        }

        case 'save_scenarios': {
            $presetSetId  = (int)($_POST['preset_set_id'] ?? 0);
            $scenariosJson = (string)($_POST['scenarios_json'] ?? '');
            if ($presetSetId <= 0 || $scenariosJson === '') {
                http_response_code(422);
                echo json_encode(['error' => 'preset_set_id and scenarios_json are required']);
                exit;
            }
            $scenarios = json_decode($scenariosJson, true);
            if (!is_array($scenarios)) {
                http_response_code(422);
                echo json_encode(['error' => 'scenarios_json must be valid JSON']);
                exit;
            }

            McaPresetRepository::assertCanEdit($presetSetId, $userId);
            McaPresetRepository::saveScenarios($presetSetId, $scenarios);

            echo json_encode(['ok' => true]);
            break;
        }

        case 'delete_set': {
            $presetSetId = (int)($_POST['preset_set_id'] ?? 0);
            if ($presetSetId <= 0) {
                http_response_code(422);
                echo json_encode(['error' => 'preset_set_id is required']);
                exit;
            }
            McaPresetRepository::assertCanEdit($presetSetId, $userId);
            McaPresetRepository::deleteSet($presetSetId, $userId);
            echo json_encode(['ok' => true]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }

} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('[mca_presets_admin] ' . $e->getMessage());
    http_response_code(409);
    echo json_encode(['error' => 'Conflict']);
} catch (Throwable $e) {
    error_log('[mca_presets_admin] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
