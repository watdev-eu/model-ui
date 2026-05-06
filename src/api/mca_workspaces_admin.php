<?php
// api/mca_workspaces_admin.php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/McaWorkspaceRepository.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'You must be logged in.',
    ]);
    exit;
}

$csrf = (string)($_POST['csrf'] ?? '');
if (!$csrf || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$userId = Auth::userId();
if ($userId === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = (string)($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'create':
            $studyAreaId   = (int)($_POST['study_area_id'] ?? 0);
            $name          = trim((string)($_POST['name'] ?? ''));
            $description   = trim((string)($_POST['description'] ?? '')) ?: null;
            $presetSetId   = (int)($_POST['preset_set_id'] ?? 0);
            $variableSetId = (int)($_POST['variable_set_id'] ?? 0);
            $isDefault     = !empty($_POST['is_default']);

            $presetItems = json_decode((string)($_POST['preset_items_json'] ?? '[]'), true);
            if (!is_array($presetItems)) $presetItems = [];

            $variables = json_decode((string)($_POST['variables_json'] ?? '[]'), true);
            if (!is_array($variables)) $variables = [];

            $cropVariables = json_decode((string)($_POST['crop_variables_json'] ?? '[]'), true);
            if (!is_array($cropVariables)) $cropVariables = [];

            $cropRefFactors = json_decode((string)($_POST['crop_ref_factors_json'] ?? '[]'), true);
            if (!is_array($cropRefFactors)) $cropRefFactors = [];

            $runInputs = json_decode((string)($_POST['run_inputs_json'] ?? '[]'), true);
            if (!is_array($runInputs)) $runInputs = [];

            $datasetIds = json_decode((string)($_POST['dataset_ids_json'] ?? '[]'), true);
            if (!is_array($datasetIds)) $datasetIds = [];

            if ($studyAreaId <= 0 || $name === '' || $presetSetId <= 0 || $variableSetId <= 0) {
                throw new InvalidArgumentException('study_area_id, name, preset_set_id and variable_set_id are required');
            }

            $id = McaWorkspaceRepository::create(
                $studyAreaId,
                $userId,
                $name,
                $description,
                $presetSetId,
                $variableSetId,
                $datasetIds,
                $isDefault,
                $presetItems,
                $variables,
                $cropVariables,
                $cropRefFactors,
                $runInputs
            );

            echo json_encode(['ok' => true, 'workspace_id' => $id]);
            break;

        case 'update':
            $workspaceId   = (int)($_POST['workspace_id'] ?? 0);
            $name          = trim((string)($_POST['name'] ?? ''));
            $description   = trim((string)($_POST['description'] ?? '')) ?: null;
            $presetSetId   = (int)($_POST['preset_set_id'] ?? 0);
            $variableSetId = (int)($_POST['variable_set_id'] ?? 0);
            $isDefault     = !empty($_POST['is_default']);

            $presetItems = json_decode((string)($_POST['preset_items_json'] ?? '[]'), true);
            if (!is_array($presetItems)) $presetItems = [];

            $variables = json_decode((string)($_POST['variables_json'] ?? '[]'), true);
            if (!is_array($variables)) $variables = [];

            $cropVariables = json_decode((string)($_POST['crop_variables_json'] ?? '[]'), true);
            if (!is_array($cropVariables)) $cropVariables = [];

            $cropRefFactors = json_decode((string)($_POST['crop_ref_factors_json'] ?? '[]'), true);
            if (!is_array($cropRefFactors)) $cropRefFactors = [];

            $runInputs = json_decode((string)($_POST['run_inputs_json'] ?? '[]'), true);
            if (!is_array($runInputs)) $runInputs = [];

            $datasetIds = json_decode((string)($_POST['dataset_ids_json'] ?? '[]'), true);
            if (!is_array($datasetIds)) $datasetIds = [];

            if ($workspaceId <= 0 || $name === '' || $presetSetId <= 0 || $variableSetId <= 0) {
                throw new InvalidArgumentException('workspace_id, name, preset_set_id and variable_set_id are required');
            }

            McaWorkspaceRepository::update(
                $workspaceId,
                $userId,
                $name,
                $description,
                $presetSetId,
                $variableSetId,
                $datasetIds,
                $isDefault,
                $presetItems,
                $variables,
                $cropVariables,
                $cropRefFactors,
                $runInputs
            );

            echo json_encode(['ok' => true]);
            break;

        case 'delete':
            $workspaceId = (int)($_POST['workspace_id'] ?? 0);
            if ($workspaceId <= 0) {
                throw new InvalidArgumentException('workspace_id is required');
            }

            McaWorkspaceRepository::delete($workspaceId, $userId);
            echo json_encode(['ok' => true]);
            break;

        default:
            throw new InvalidArgumentException('Unknown action');
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
/*} catch (Throwable $e) {
    error_log('[mca_workspaces_admin] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}*/
} catch (Throwable $e) {
    error_log('[mca_workspaces_admin] ' . $e->getMessage());
    error_log($e->getTraceAsString());

    // PostgreSQL unique violation: duplicate workspace name for user/study area
    if ($e instanceof PDOException && $e->getCode() === '23505') {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'error' => 'workspace_name_exists',
            'message' => 'A workspace with this name already exists for this study area. Please choose a different name.',
        ]);
        exit;
    }

    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'The workspace could not be saved because of a server error. Please try again.',
    ]);
    exit;
}