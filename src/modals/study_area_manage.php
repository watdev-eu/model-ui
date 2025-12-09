<?php
// src/modals/study_area_manage.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/StudyAreaRepository.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$pdo = Database::pdo();
$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$area = $id > 0 ? StudyAreaRepository::find($id) : null;
if (!$area) {
    ?>
    <div class="p-3 text-danger">Study area not found.</div>
    <?php
    return;
}

// Fetch subbasins as GeoJSON FeatureCollection
$stmtSub = $pdo->prepare("
    SELECT
        sub,
        properties,
        ST_AsGeoJSON(geom) AS geom_json
    FROM study_area_subbasins
    WHERE study_area_id = :id
");
$stmtSub->execute([':id' => $id]);

$subFeatures = [];
while ($row = $stmtSub->fetch(PDO::FETCH_ASSOC)) {
    $props = json_decode($row['properties'] ?? '{}', true) ?: [];
    $props = array_merge(['Subbasin' => (int)$row['sub']], $props);

    $subFeatures[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode($row['geom_json'], true),
        'properties' => $props,
    ];
}
$subFC = [
    'type'     => 'FeatureCollection',
    'features' => $subFeatures,
];

// Fetch reaches as GeoJSON FeatureCollection
$stmtRiv = $pdo->prepare("
    SELECT
        rch,
        sub,
        properties,
        ST_AsGeoJSON(geom) AS geom_json
    FROM study_area_reaches
    WHERE study_area_id = :id
");
$stmtRiv->execute([':id' => $id]);

$rivFeatures = [];
while ($row = $stmtRiv->fetch(PDO::FETCH_ASSOC)) {
    $props = json_decode($row['properties'] ?? '{}', true) ?: [];
    $props = array_merge([
        'RCH'      => (int)$row['rch'],
        'Subbasin' => (int)$row['sub'],
    ], $props);

    $rivFeatures[] = [
        'type'       => 'Feature',
        'geometry'   => json_decode($row['geom_json'], true),
        'properties' => $props,
    ];
}
$rivFC = [
    'type'     => 'FeatureCollection',
    'features' => $rivFeatures,
];

$isEnabled = !empty($area['enabled']);
?>

<script>
    ModalUtils.setModalTitle("Study area: <?= h($area['name']) ?>");
</script>

<div id="studyAreaManageRoot"
     class="p-3"
     data-id="<?= (int)$area['id'] ?>"
     data-name="<?= h($area['name']) ?>"
     data-enabled="<?= $isEnabled ? '1' : '0' ?>"
     data-csrf="<?= h($csrfToken) ?>">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <span class="me-2">Status:</span>
            <span id="studyAreaStatusBadge"
                  class="badge <?= $isEnabled ? 'bg-success' : 'bg-secondary' ?>">
                <?= $isEnabled ? 'Enabled' : 'Disabled' ?>
            </span>
        </div>
        <button type="button"
                class="btn btn-sm btn-outline-secondary"
                id="studyAreaToggleBtn">
            <?= $isEnabled ? 'Disable area' : 'Enable area' ?>
        </button>
    </div>

    <div class="mb-3 border rounded" style="height: 400px; overflow: hidden;">
        <div id="studyAreaMap" style="width: 100%; height: 100%;"></div>
    </div>

    <div class="d-flex justify-content-between mt-3">
        <button type="button"
                class="btn btn-outline-secondary btn-sm"
                data-bs-dismiss="modal">
            Close
        </button>
        <button type="button"
                class="btn btn-danger btn-sm"
                id="studyAreaDeleteBtn"
                data-bs-toggle="collapse"
                data-bs-target="#studyAreaDeleteConfirm">
            <i class="bi bi-trash me-1"></i>
            Remove study area
        </button>
    </div>

    <div class="collapse mt-3" id="studyAreaDeleteConfirm">
        <div class="alert alert-danger mb-0">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    Are you sure you want to remove
                    <strong><?= h($area['name']) ?></strong>?
                    <br>
                    <span class="small">
                    This will remove its subbasins and reaches and cannot be undone.
                </span>
                </div>
                <div class="ms-3">
                    <button type="button"
                            class="btn btn-outline-light btn-sm me-2"
                            id="studyAreaDeleteCancelBtn">
                        Cancel
                    </button>
                    <button type="button"
                            class="btn btn-light btn-sm text-danger"
                            id="studyAreaDeleteConfirmBtn">
                        Delete
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    window.StudyAreaManageData = {
        subbasins: <?= json_encode($subFC, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        reaches: <?= json_encode($rivFC, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };
</script>

<script src="/assets/js/study-area-manage.js"
        data-modal-script
        data-init-function="initStudyAreaManage"></script>