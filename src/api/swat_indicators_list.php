<?php
// src/api/swat_indicators_list.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/SwatIndicatorRegistry.php';

header('Content-Type: application/json');

try {
    echo json_encode([
        'indicators' => SwatIndicatorRegistry::list(),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}