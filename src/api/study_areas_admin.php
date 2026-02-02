<?php
// src/api/study_areas_admin.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/StudyAreaRepository.php';

header('Content-Type: application/json');

// Only admins
//require_admin();

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// CSRF check
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$csrf = $_POST['csrf'] ?? '';
if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$action = $_POST['action'] ?? '';

/**
 * Try to detect SRID from GeoJSON:
 * 1. Use "crs" object if present (EPSG:xxxx in name/urn)
 * 2. Fallback heuristic on coordinate ranges:
 *    - if abs(x) <= 180 and abs(y) <= 90 => guess 4326 (lat/lon)
 *    - else => guess 3857
 */
function detect_geojson_srid(array $data): int
{
    // 1) Check explicit CRS
    if (isset($data['crs']) && is_array($data['crs'])) {
        $props = $data['crs']['properties'] ?? [];
        $name  = $props['name'] ?? '';

        if (is_string($name)) {
            // Match EPSG:XXXX or EPSG::XXXX in URN
            if (preg_match('/EPSG[:]{1,2}(\d+)/i', $name, $m)) {
                return (int) $m[1];
            }
        }
    }

    // 2) Heuristic: sample first coordinate
    $coords = null;

    foreach ($data['features'] ?? [] as $f) {
        if (!isset($f['geometry']['coordinates'])) {
            continue;
        }
        $coords = $f['geometry']['coordinates'];
        break;
    }

    // Unwrap nested arrays until we hit a [x, y]
    while (is_array($coords) && isset($coords[0]) && is_array($coords[0])) {
        $coords = $coords[0];
    }

    if (is_array($coords) && count($coords) >= 2) {
        $x = $coords[0];
        $y = $coords[1];

        if (is_numeric($x) && is_numeric($y)) {
            $x = (float) $x;
            $y = (float) $y;

            // If it "looks like" lon/lat degrees, assume WGS84
            if (abs($x) <= 180 && abs($y) <= 90) {
                return 4326;
            }
        }
    }

    // 3) Fallback: assume it's already WebMercator
    return 3857;
}

/**
 * Helper: read & validate an uploaded GeoJSON FeatureCollection
 * Returns: ['features' => [...], 'srid' => int]
 */
function read_geojson_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Upload failed for ' . ($file['name'] ?? 'file'));
    }

    $contents = file_get_contents($file['tmp_name']);
    if ($contents === false) {
        throw new RuntimeException('Could not read uploaded file');
    }

    $data = json_decode($contents, true);
    if (!is_array($data) || ($data['type'] ?? '') !== 'FeatureCollection') {
        throw new InvalidArgumentException('Expected a GeoJSON FeatureCollection');
    }
    if (empty($data['features']) || !is_array($data['features'])) {
        throw new InvalidArgumentException('GeoJSON has no features');
    }

    $srid = detect_geojson_srid($data);

    return [
        'features' => $data['features'],
        'srid'     => $srid,
    ];
}

try {
    $pdo = Database::pdo();

    switch ($action) {
        case 'create_from_geojson':
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                http_response_code(422);
                echo json_encode(['error' => 'Name is required']);
                exit;
            }

            $rawHas = $_POST['has_rch_results'] ?? null;

            // default true when missing (checkbox default checked)
            if ($rawHas === null) {
                $hasRchResults = true;
            } else {
                // accept "1"/"0" and true/false variants
                $hasRchResults = filter_var($rawHas, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($hasRchResults === null) {
                    throw new InvalidArgumentException('Invalid has_rch_results value');
                }
            }

            $subFile = $_FILES['subbasins'] ?? null;
            $rivFile = $_FILES['reaches']   ?? null;

            if (!$subFile || !$rivFile) {
                http_response_code(422);
                echo json_encode(['error' => 'Both subbasins and reaches GeoJSON files are required']);
                exit;
            }

            $subData = read_geojson_upload($subFile);  // ['features' => ..., 'srid' => int]
            $rivData = read_geojson_upload($rivFile);

            $subFeatures = $subData['features'];
            $rivFeatures = $rivData['features'];

            // If sub & reaches disagree on CRS, we'll just use each one's SRID independently.
            $subSrid = (int) $subData['srid'];
            $rivSrid = (int) $rivData['srid'];

            $pdo->beginTransaction();

            // 1) Create study area row
            $created = StudyAreaRepository::createWithMcaDefaults($name, (bool)$hasRchResults);
            $studyAreaId = (int)$created['study_area_id'];

            // 4) Insert subbasins (always store in 3857)
            $stmtSub = $pdo->prepare("
                INSERT INTO study_area_subbasins (study_area_id, sub, geom, properties)
                VALUES (
                    :study_area_id,
                    :sub,
                    ST_Transform(
                        ST_SetSRID(ST_GeomFromGeoJSON(:geom), :src_srid),
                        3857
                    ),
                    (:props)::jsonb
                )
            ");

            foreach ($subFeatures as $f) {
                if (!isset($f['geometry'])) continue;
                $props = $f['properties'] ?? [];
                $sub   = $props['Subbasin'] ?? null;

                if ($sub === null) {
                    throw new InvalidArgumentException('Subbasin feature missing "Subbasin" property');
                }

                $stmtSub->execute([
                    ':study_area_id' => $studyAreaId,
                    ':sub'           => (int)$sub,
                    ':geom'          => json_encode($f['geometry']),
                    ':props'         => json_encode($props, JSON_UNESCAPED_UNICODE),
                    ':src_srid'      => $subSrid,
                ]);
            }

            // 3) Insert reaches (always store in 3857)
            $stmtRiv = $pdo->prepare("
                INSERT INTO study_area_reaches (study_area_id, rch, sub, geom, properties)
                VALUES (
                    :study_area_id,
                    :rch,
                    :sub,
                    ST_Transform(
                        ST_SetSRID(ST_GeomFromGeoJSON(:geom), :src_srid),
                        3857
                    ),
                    (:props)::jsonb
                )
            ");

            foreach ($rivFeatures as $f) {
                if (!isset($f['geometry'])) continue;

                $props = $f['properties'] ?? [];
                // Use SubbasinR as reach id if present, fallback to Subbasin
                $rch = $props['SubbasinR'] ?? $props['Subbasin'] ?? null;
                $sub = $props['Subbasin']   ?? null;

                if ($rch === null || $sub === null) {
                    throw new InvalidArgumentException('Reach feature missing "Subbasin"/"SubbasinR" properties');
                }

                $stmtRiv->execute([
                    ':study_area_id' => $studyAreaId,
                    ':rch'           => (int)$rch,
                    ':sub'           => (int)$sub,
                    ':geom'          => json_encode($f['geometry']),
                    ':props'         => json_encode($props, JSON_UNESCAPED_UNICODE),
                    ':src_srid'      => $rivSrid,
                ]);
            }

            $pdo->commit();

            // 4) Optionally compute overall geometry (non-fatal if it fails)
            try {
                StudyAreaRepository::rebuildBoundaryFromSubbasins($studyAreaId);
            } catch (Throwable $e) {
                error_log('[study_areas_admin] Failed to build union geom for study area '
                    . $studyAreaId . ': ' . $e->getMessage());
            }

            echo json_encode([
                'ok'        => true,
                'id'        => $studyAreaId,
                'name'      => $name,
                'subbasins' => count($subFeatures),
                'reaches'   => count($rivFeatures),
                'has_rch_results' => (bool)$hasRchResults,
            ]);
            break;

        case 'toggle_enabled':
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id <= 0) {
                http_response_code(422);
                echo json_encode(['error' => 'Invalid study area id']);
                exit;
            }

            $area = StudyAreaRepository::find($id);
            if (!$area) {
                http_response_code(404);
                echo json_encode(['error' => 'Study area not found']);
                exit;
            }

            // Normalize to a clean boolean
            $currentEnabled = (bool)($area['enabled'] ?? false);
            $newEnabled     = !$currentEnabled;

            StudyAreaRepository::setEnabled($id, $newEnabled);

            echo json_encode([
                'ok'      => true,
                'enabled' => $newEnabled,
            ]);
            break;

        case 'delete':
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id <= 0) {
                http_response_code(422);
                echo json_encode(['error' => 'Invalid study area id']);
                exit;
            }

            $pdo->beginTransaction();

            // Remove children first (in case there is no ON DELETE CASCADE)
            $stmt = $pdo->prepare("DELETE FROM study_area_subbasins WHERE study_area_id = :id");
            $stmt->execute([':id' => $id]);

            $stmt = $pdo->prepare("DELETE FROM study_area_reaches WHERE study_area_id = :id");
            $stmt->execute([':id' => $id]);

            $stmt = $pdo->prepare("DELETE FROM study_areas WHERE id = :id");
            $stmt->execute([':id' => $id]);

            $pdo->commit();

            echo json_encode(['ok' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }

} catch (InvalidArgumentException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[study_areas_admin] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}