<?php
// src/data.php
declare(strict_types=1);

$pageTitle   = 'Data management';
$pageButtons = [];

require_once __DIR__ . '/config/app.php';

// ---- SESSION + CSRF ----
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
// --------------------------------------------------

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/classes/CropRepository.php';
require_once __DIR__ . '/classes/SwatRunRepository.php';
require_once __DIR__ . '/classes/StudyAreaRepository.php';

// Protect this page
//require_admin();

$crops = CropRepository::all();

// helper to split crops into N roughly equal columns
$columns = 2; // (or 3 if you kept that)
$perCol  = (int)ceil(max(1, count($crops)) / $columns);

// Placeholder arrays – later we’ll load these from repositories / DB
$studyAreas = StudyAreaRepository::all();
$runs       = SwatRunRepository::all();
?>

    <div class="card mb-3">
        <div class="card-body">
            <h1 class="title mb-3">Data management</h1>
            <p class="text-muted mb-0">
                Configure study areas, crops and model runs used in the dashboard.
            </p>
        </div>
    </div>

    <!-- Study areas -->
    <div class="card mb-4" id="study-areas-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">Study areas</h2>
            <button type="button"
                    class="btn btn-sm btn-primary"
                    data-url="/modals/study_area_add.php">
                + Add study area
            </button>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Manage study areas and their underlying subbasins and reaches.
            </p>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th class="text-center">Subbasins</th>
                        <th class="text-center">Reaches</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody id="studyAreasTbody">
                    <?php if (!$studyAreas): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted">
                                No study areas configured yet
                            </td>
                        </tr>
                    <?php else: foreach ($studyAreas as $area): ?>
                        <tr data-study-area-id="<?= (int)$area['id'] ?>">
                            <td><?= htmlspecialchars($area['name']) ?></td>
                            <td class="text-center">
                    <span class="badge bg-light text-muted">
                        <?= (int)($area['subbasins'] ?? 0) ?>
                    </span>
                            </td>
                            <td class="text-center">
                    <span class="badge bg-light text-muted">
                        <?= (int)($area['reaches'] ?? 0) ?>
                    </span>
                            </td>
                            <td>
                                <?php if (!empty($area['enabled'])): ?>
                                    <span class="badge bg-success">Enabled</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Disabled</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-secondary"
                                        data-url="/modals/study_area_manage.php?id=<?= (int)$area['id'] ?>">
                                    Manage
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Crops -->
    <div class="card mb-4" id="crops-card"
         data-api-url="/api/crops_admin.php"
         data-csrf="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">

        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">Crops</h2>
            <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-light text-muted">
                        <?= count($crops) ?> entries
                    </span>
                <button type="button"
                        class="btn btn-sm btn-primary"
                        data-url="/modals/crops_add.php">
                    + Add crops
                </button>
            </div>
        </div>

        <div class="card-body">
            <p class="text-muted">
                <code>code</code> is what appears in the model outputs;
                <code>name</code> is the human-readable English label.
                You can change both here.
            </p>

            <div class="row" id="crops-list">
                <?php for ($col = 0; $col < $columns; $col++): ?>
                    <div class="col-lg-6 col-md-6 mb-3 crops-column">
                        <table class="table table-sm align-middle mb-0">
                            <tbody>
                            <?php
                            $start = $col * $perCol;
                            $slice = array_slice($crops, $start, $perCol);
                            foreach ($slice as $crop):
                                ?>
                                <tr data-code="<?= htmlspecialchars($crop['code']) ?>">
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="text"
                                                   class="form-control mono crop-code"
                                                   maxlength="8"
                                                   value="<?= htmlspecialchars($crop['code']) ?>"
                                                   placeholder="Code">

                                            <input type="text"
                                                   class="form-control crop-name"
                                                   value="<?= htmlspecialchars($crop['name'] ?? '') ?>"
                                                   placeholder="Name">

                                            <button type="button"
                                                    class="btn btn-outline-success js-crop-save"
                                                    title="Save">
                                                <i class="bi bi-check"></i>
                                            </button>
                                            <button type="button"
                                                    class="btn btn-outline-danger js-crop-delete"
                                                    title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <!-- Model runs -->
    <div class="card mb-4" id="runs-card"
         data-api-url="/api/runs_admin.php"
         data-csrf="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">Model runs</h2>
            <a href="/import.php" class="btn btn-sm btn-primary">
                <i class="bi bi-upload me-1"></i>
                Import / register run
            </a>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Manage imported model runs: visibility, defaults and cleanup rules.
            </p>

            <div class="table-responsive" id="runs-table-wrapper">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Area</th>
                        <th>Scenario</th>
                        <th>Visibility</th>
                        <th>Period</th>
                        <th>Expires</th>
                        <th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$runs): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">
                                No model runs yet
                            </td>
                        </tr>
                    <?php else: foreach ($runs as $r): ?>
                        <tr data-run-id="<?= (int)$r['id'] ?>"
                            data-study-area="<?= (int)($r['study_area_id'] ?? $r['study_area']) ?>"
                            data-run-date="<?= htmlspecialchars($r['run_date'] ?? '') ?>"
                            data-created-at="<?= htmlspecialchars($r['created_at'] ?? '') ?>"
                            data-is-default="<?= !empty($r['is_default']) ? '1' : '0' ?>">
                            <!-- Area -->
                            <td class="run-area">
                                <?= htmlspecialchars($r['study_area_name'] ?? ('Area #' . (int)$r['study_area'])) ?>
                            </td>

                            <!-- Scenario -->
                            <td class="fw-medium run-scenario">
                                <?= htmlspecialchars($r['run_label']) ?>
                                <?php if (!empty($r['is_default'])): ?>
                                    <span class="ms-1 text-warning run-default-star" title="Default run for this area">
                                        <i class="bi bi-star-fill"></i>
                                    </span>
                                <?php else: ?>
                                    <div class="small text-muted run-date">
                                        <?php
                                        $date = $r['run_date'] ?: ($r['created_at'] ?? null);
                                        echo $date ? htmlspecialchars(substr($date, 0, 10)) : '—';
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- Visibility -->
                            <td class="run-visibility">
                                <?php if (($r['visibility'] ?? 'private') === 'public'): ?>
                                    <span class="badge bg-success">
                                        <i class="bi bi-globe2 me-1"></i>Public
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">
                                        <i class="bi bi-lock-fill me-1"></i>Private
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Period -->
                            <td class="run-period">
                                <?php
                                $start = $r['period_start'] ?? null;
                                $end   = $r['period_end']   ?? null;
                                echo htmlspecialchars(($start ?: '—') . ' → ' . ($end ?: '—'));
                                ?>
                            </td>

                            <!-- Expires -->
                            <td class="run-expires">
                                <?php if (!empty($r['is_default'])): ?>
                                    &mdash;
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>

                            <!-- Actions: info + dropdown -->
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <!-- Info button -->
                                    <button type="button"
                                            class="btn btn-outline-secondary"
                                            title="Details"
                                            data-url="/modals/run_info.php?id=<?= (int)$r['id'] ?>">
                                        <i class="bi bi-info-circle"></i>
                                    </button>

                                    <button type="button"
                                            class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split"
                                            data-bs-toggle="dropdown"
                                            aria-expanded="false">
                                        <span class="visually-hidden">Actions</span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <button type="button"
                                                    class="dropdown-item js-run-toggle-default"
                                                    data-id="<?= (int)$r['id'] ?>">
                                                <i class="bi bi-star<?= !empty($r['is_default']) ? '-fill' : '' ?> me-2"></i>
                                                <?= !empty($r['is_default']) ? 'Unset as default' : 'Set as default' ?>
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button"
                                                    class="dropdown-item js-run-toggle-visibility"
                                                    data-id="<?= (int)$r['id'] ?>"
                                                    data-current="<?= htmlspecialchars($r['visibility']) ?>">
                                                <?php if (($r['visibility'] ?? 'private') === 'public'): ?>
                                                    <i class="bi bi-lock me-2"></i>
                                                    Make private
                                                <?php else: ?>
                                                    <i class="bi bi-unlock me-2"></i>
                                                    Make public
                                                <?php endif; ?>
                                            </button>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <button type="button"
                                                    class="dropdown-item text-danger js-run-delete"
                                                    data-id="<?= (int)$r['id'] ?>"
                                                    data-label="<?= htmlspecialchars($r['run_label']) ?>">
                                                <i class="bi bi-trash me-2"></i>
                                                Remove
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MCA defaults -->
    <div class="card mb-4" id="mca-defaults-card"
         data-api-url="/api/mca_defaults_admin.php"
         data-csrf="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">MCA defaults</h2>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="mcaDefaultsReloadBtn">
                    Reload
                </button>
                <button type="button" class="btn btn-sm btn-primary" id="mcaDefaultsSaveBtn" disabled>
                    Save MCA defaults
                </button>
            </div>
        </div>

        <div class="card-body">
            <p class="text-muted">
                Manage study-area MCA defaults and per-crop baseline values used in MCA. Scenario overrides come later.
            </p>

            <div class="row g-3 align-items-end mb-3">
                <div class="col-12 col-md-6">
                    <label class="form-label mb-1">Study area</label>
                    <select class="form-select form-select-sm" id="mcaDefaultsStudyAreaSelect">
                        <option value="">Loading study areas…</option>
                    </select>
                </div>
                <div class="col-12 col-md-6">
                    <div class="small text-muted">
                        Variable set: <span class="mono" id="mcaDefaultsVarSetLabel">—</span>
                    </div>
                    <div class="small text-muted">
                        Crops in runs: <span class="mono" id="mcaDefaultsCropsCount">—</span>
                    </div>
                </div>
            </div>

            <div class="row g-3 align-items-end mb-3">
                <div class="col-12 col-md-6">
                    <label class="form-label mb-1">Scenario (optional override)</label>
                    <select class="form-select form-select-sm" id="mcaDefaultsRunSelect" disabled>
                        <option value="">Use study-area defaults</option>
                    </select>
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-12">
                    <div class="border rounded p-3">
                        <div class="fw-semibold mb-2">Scenario inputs (selected run)</div>

                        <div class="small text-muted mb-2">
                            Select a scenario above to edit run-specific inputs.
                        </div>

                        <div class="row g-2" id="mcaDefaultsRunForm"><!-- filled by JS --></div>

                        <div class="mt-3" id="mcaDefaultsRunCropBlock" style="display:none;">
                            <div class="fw-semibold mb-2">BMP production cost factors (crops in this scenario)</div>
                            <div class="table-responsive" id="mcaDefaultsRunCropTableWrap"><!-- filled by JS --></div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="mcaDefaultsStatus" class="small text-muted mb-2"></div>

            <div class="row g-3">
                <div class="col-12">
                    <div class="border rounded p-3">
                        <div class="fw-semibold mb-2">Global defaults (study area)</div>
                        <div class="row g-2" id="mcaDefaultsGlobalForm">
                            <!-- filled by JS -->
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="border rounded p-3">
                        <div class="fw-semibold mb-2">Crop defaults (from crops present in model runs)</div>
                        <div class="table-responsive" id="mcaDefaultsCropTableWrap">
                            <!-- filled by JS -->
                        </div>
                        <div class="form-text small mt-2">
                            Only crops that occur in imported runs for this study area are listed.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<!-- Crop delete confirmation modal -->
<div class="modal fade" id="cropDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Confirm deletion</h5>
                <button type="button"
                        class="btn-close btn-close-white"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete crop
                <span id="cropDeleteCode" class="fw-bold"></span>?
                This cannot be undone.
            </div>
            <div class="modal-footer">
                <button type="button"
                        class="btn btn-outline-secondary btn-sm"
                        data-bs-dismiss="modal">
                    Cancel
                </button>
                <button type="button"
                        class="btn btn-danger btn-sm"
                        id="cropDeleteConfirmBtn">
                    Delete
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Run delete confirmation modal -->
<div class="modal fade" id="runDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Confirm removal</h5>
                <button type="button"
                        class="btn-close btn-close-white"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to remove scenario
                <span id="runDeleteLabel" class="fw-bold"></span>?
                This cannot be undone.
            </div>
            <div class="modal-footer">
                <button type="button"
                        class="btn btn-outline-secondary btn-sm"
                        data-bs-dismiss="modal">
                    Cancel
                </button>
                <button type="button"
                        class="btn btn-danger btn-sm"
                        id="runDeleteConfirmBtn">
                    Remove
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    /* Let dropdowns in the model-runs card overflow the card boundaries */
    #runs-card,
    #runs-card .card-body,
    #runs-card .table-responsive {
        overflow: visible !important;
    }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script src="/assets/js/data-crops.js"></script>
<script src="/assets/js/data-runs.js"></script>
<script src="/assets/js/data-mca-defaults.js"></script>
<script src="https://cdn.jsdelivr.net/npm/ol@latest/dist/ol.js"></script>