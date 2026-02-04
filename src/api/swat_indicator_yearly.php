<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/SwatResultsRepository.php';

header('Content-Type: application/json');

$runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : 0;
$indicator = trim((string)($_GET['indicator'] ?? ''));

if ($runId <= 0 || $indicator === '') {
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
    echo json_encode(SwatResultsRepository::getYearly($runId, $indicator, $opts));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}