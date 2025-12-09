<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$areaId = isset($_GET['study_area_id']) ? (int)$_GET['study_area_id'] : 0;
if ($areaId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'study_area_id is required']);
    exit;
}

$pdo = Database::pdo();

$stmt = $pdo->prepare("
    SELECT
        sub,
        ST_AsGeoJSON(ST_Transform(geom, 3857), 6) AS geom,
        properties
    FROM study_area_subbasins
    WHERE study_area_id = :id
    ORDER BY sub
");
$stmt->execute([':id' => $areaId]);

$features = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $geom = json_decode($row['geom'], true);

    $props = $row['properties'] ?? null;
    if (is_string($props)) {
        $props = json_decode($props, true) ?: [];
    } elseif (!is_array($props)) {
        $props = [];
    }

    // Ensure the property used by the JS exists
    $props['Subbasin'] = (int)$row['sub'];

    $features[] = [
        'type'       => 'Feature',
        'geometry'   => $geom,
        'properties' => $props,
    ];
}

echo json_encode([
    'type'     => 'FeatureCollection',
    'features' => $features,
    'crs'      => [
        'type'       => 'name',
        'properties' => ['name' => 'EPSG:3857'],
    ],
]);