<?php
// src/modals/study_area_add.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

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
?>

<script>
    ModalUtils.setModalTitle("Add study area");
</script>

<div class="p-3">
    <form id="studyAreaAddForm" enctype="multipart/form-data" class="mb-3">
        <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">

        <div class="mb-3">
            <label for="studyAreaName" class="form-label">Name</label>
            <input type="text"
                   class="form-control form-control-sm"
                   id="studyAreaName"
                   name="name"
                   placeholder="e.g. Egypt"
                   required>
        </div>

        <div class="mb-3">
            <label class="form-label" for="studyAreaSubbasins">
                Subbasins GeoJSON
            </label>
            <input type="file"
                   class="form-control form-control-sm"
                   id="studyAreaSubbasins"
                   name="subbasins"
                   accept=".geojson,application/json"
                   required>
            <div class="form-text">
                FeatureCollection with <code>MultiPolygon</code> features.
                Must contain a <code>Subbasin</code> property.
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label" for="studyAreaReaches">
                Reaches GeoJSON
            </label>
            <input type="file"
                   class="form-control form-control-sm"
                   id="studyAreaReaches"
                   name="reaches"
                   accept=".geojson,application/json"
                   required>
            <div class="form-text">
                FeatureCollection with <code>MultiLineString</code> features.
                Should contain <code>Subbasin</code> and <code>SubbasinR</code> properties
                (other attributes are stored in metadata).
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-4">
            <button type="button"
                    class="btn btn-outline-secondary btn-sm"
                    data-bs-dismiss="modal">
                Cancel
            </button>
            <button type="submit"
                    class="btn btn-primary btn-sm"
                    id="studyAreaSubmitBtn">
                Import study area
            </button>
        </div>

        <div class="small mt-2 text-muted" id="studyAreaAddStatus"></div>
    </form>
</div>

<script src="/assets/js/study-area-add.js"
        data-modal-script
        data-init-function="initStudyAreaAdd"></script>