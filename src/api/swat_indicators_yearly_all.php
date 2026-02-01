<?php
// src/api/swat_indicators_yearly_all.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/../classes/SwatResultsRepository.php';

header('Content-Type: application/json');

$runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : 0;
if ($runId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'run_id is required']);
    exit;
}

try {
    echo json_encode(SwatResultsRepository::getYearlyAll($runId));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}