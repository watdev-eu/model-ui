<?php
// src/api/crops_list.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/CropRepository.php';

header('Content-Type: application/json');

try {
    // No parameters required – dashboard just needs all crop codes + names
    $crops = CropRepository::all();

    echo json_encode([
        'ok'    => true,
        'crops' => array_map(static function (array $row): array {
            return [
                'code' => $row['code'],
                'name' => $row['name'],
            ];
        }, $crops),
    ]);
} catch (Throwable $e) {
    error_log('[crops_list] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}