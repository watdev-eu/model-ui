<?php
//api/swat_indicator_yearly.php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CustomScenarioRepository.php';
require_once __DIR__ . '/../classes/DashboardDatasetKey.php';
require_once __DIR__ . '/../classes/SwatResultsRepository.php';

header('Content-Type: application/json');

$rawRunId = trim((string)($_GET['run_id'] ?? ''));
$indicator = trim((string)($_GET['indicator'] ?? ''));

if ($rawRunId === '' || $indicator === '') {
    http_response_code(400);
    echo json_encode(['error' => 'run_id and indicator are required']);
    exit;
}

$opts = [];
if (isset($_GET['sub']) && $_GET['sub'] !== '')  $opts['sub']  = (int)$_GET['sub'];
if (isset($_GET['crop']) && $_GET['crop'] !== '') $opts['crop'] = (string)$_GET['crop'];
if (isset($_GET['from']) && $_GET['from'] !== '') $opts['from'] = (string)$_GET['from'];
if (isset($_GET['to']) && $_GET['to'] !== '')     $opts['to']   = (string)$_GET['to'];

try {
    $parsed = DashboardDatasetKey::parse($rawRunId);

    if ($parsed['type'] === 'run') {
        echo json_encode(SwatResultsRepository::getYearly((int)$parsed['id'], $indicator, $opts));
        exit;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!Auth::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'You must be logged in.']);
        exit;
    }

    $userId = Auth::userId();
    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $effectiveRunMap = CustomScenarioRepository::getEffectiveRunMapForUser((int)$parsed['id'], $userId);
    $effectiveRunIds = array_values(array_unique(array_values($effectiveRunMap)));

    $mergedRows = [];
    $meta = null;
    $status = 'ok';

    foreach ($effectiveRunIds as $runId) {
        $result = SwatResultsRepository::getYearly((int)$runId, $indicator, $opts);

        if ($meta === null) {
            $meta = $result['meta'] ?? [];
            $status = $result['status'] ?? 'ok';
        }

        if (($result['status'] ?? 'ok') !== 'ok') {
            continue;
        }

        foreach (($result['rows'] ?? []) as $row) {
            $sub = isset($row['sub']) ? (int)$row['sub'] : 0;
            if ($sub <= 0) continue;

            $effectiveRunForSub = (int)($effectiveRunMap[$sub] ?? 0);
            if ($effectiveRunForSub !== (int)$runId) continue;

            $mergedRows[] = $row;
        }
    }

    usort($mergedRows, static function (array $a, array $b): int {
        $ya = (int)($a['year'] ?? 0);
        $yb = (int)($b['year'] ?? 0);
        if ($ya !== $yb) return $ya <=> $yb;

        $sa = (int)($a['sub'] ?? 0);
        $sb = (int)($b['sub'] ?? 0);
        if ($sa !== $sb) return $sa <=> $sb;

        return strcmp((string)($a['crop'] ?? ''), (string)($b['crop'] ?? ''));
    });

    echo json_encode([
        'status' => $status,
        'meta'   => $meta ?? [],
        'rows'   => $mergedRows,
    ]);
} catch (Throwable $e) {
    error_log('[swat_indicator_yearly] ' . $e->getMessage());

    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}