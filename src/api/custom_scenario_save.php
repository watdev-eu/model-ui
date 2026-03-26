<?php
// api/custom_scenario_save.php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CustomScenarioRepository.php';

header('Content-Type: application/json; charset=utf-8');

Auth::requireLogin();

$userId = Auth::userId();
if ($userId === null) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON body']);
    exit;
}

$scenarioId = isset($data['id']) ? (int)$data['id'] : 0;
$studyAreaId = (int)($data['studyAreaId'] ?? 0);
$name = trim((string)($data['name'] ?? ''));
$description = trim((string)($data['description'] ?? ''));
$assignments = $data['assignments'] ?? [];
$confirmedUseBaselineForMissing = !empty($data['confirmedUseBaselineForMissing']);

if ($studyAreaId <= 0) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid study area']);
    exit;
}

if ($name === '') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Scenario name is required']);
    exit;
}

if (mb_strlen($name) > 160) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Scenario name is too long']);
    exit;
}

if (mb_strtolower($name) === 'baseline') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'The name "Baseline" is reserved']);
    exit;
}

if (!is_array($assignments)) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Assignments must be an object']);
    exit;
}

try {
    $baselineRunId = CustomScenarioRepository::getBaselineRunId($studyAreaId);
    if (!$baselineRunId) {
        http_response_code(422);
        echo json_encode([
            'status' => 'error',
            'message' => 'No baseline run configured for this study area',
        ]);
        exit;
    }

    $normalizedAssignments = [];
    foreach ($assignments as $sub => $runId) {
        $subInt = (int)$sub;
        $runInt = (int)$runId;

        if ($subInt <= 0 || $runInt <= 0) {
            continue;
        }

        $normalizedAssignments[$subInt] = $runInt;
    }

    $submittedSubIds = array_keys($normalizedAssignments);
    $submittedRunIds = array_values($normalizedAssignments);

    $validSubIds = CustomScenarioRepository::validateSubIds($studyAreaId, $submittedSubIds);
    $validRunIds = CustomScenarioRepository::validateRunIds($studyAreaId, $submittedRunIds);

    sort($validSubIds);
    $checkSubIds = $submittedSubIds;
    sort($checkSubIds);

    if ($validSubIds !== $checkSubIds) {
        http_response_code(422);
        echo json_encode([
            'status' => 'error',
            'message' => 'One or more selected subbasins are invalid',
        ]);
        exit;
    }

    $uniqueRunIds = array_values(array_unique($submittedRunIds));
    sort($uniqueRunIds);
    sort($validRunIds);

    if ($validRunIds !== $uniqueRunIds) {
        http_response_code(422);
        echo json_encode([
            'status' => 'error',
            'message' => 'One or more selected scenarios are invalid for this study area',
        ]);
        exit;
    }

    $totalSubbasins = CustomScenarioRepository::countSubbasins($studyAreaId);
    if (count($normalizedAssignments) < $totalSubbasins && !$confirmedUseBaselineForMissing) {
        echo json_encode([
            'status' => 'confirm_required',
            'message' => 'Not all subbasins are assigned. Baseline will be used for the remaining subbasins.',
        ]);
        exit;
    }

    if ($scenarioId > 0) {
        CustomScenarioRepository::update(
            $scenarioId,
            $userId,
            $name,
            $description !== '' ? $description : null,
            $normalizedAssignments
        );

        echo json_encode([
            'status' => 'ok',
            'id' => $scenarioId,
            'message' => 'Scenario updated successfully',
        ]);
        exit;
    }

    $newId = CustomScenarioRepository::create(
        $studyAreaId,
        $userId,
        $name,
        $description !== '' ? $description : null,
        $normalizedAssignments
    );

    echo json_encode([
        'status' => 'ok',
        'id' => $newId,
        'message' => 'Scenario created successfully',
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23505') {
        http_response_code(409);
        echo json_encode([
            'status' => 'error',
            'message' => 'A scenario with this name already exists for this study area',
        ]);
        exit;
    }

    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ]);
}