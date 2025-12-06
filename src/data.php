<?php
// src/data.php
declare(strict_types=1);

$pageTitle   = 'Data management';
$pageButtons = [];

require_once __DIR__ . '/config/app.php';

// ---- SESSION + CSRF MUST BE BEFORE ANY OUTPUT ----
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
// --------------------------------------------------

// now safe to include layout (which outputs HTML)
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/classes/CropRepository.php';
require_once __DIR__ . '/classes/SwatRunRepository.php';

// Protect this page
//require_admin();

$crops = CropRepository::all();

// helper to split crops into N roughly equal columns
$columns = 2; // (or 3 if you kept that)
$perCol  = (int)ceil(max(1, count($crops)) / $columns);

// Placeholder arrays – later we’ll load these from repositories / DB
$studyAreas = [];
$runs       = SwatRunRepository::all();

function humanStudyArea(string $area): string {
    return ucfirst($area);
}
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
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">Study areas</h2>
            <button type="button" class="btn btn-sm btn-primary" disabled>
                + Add study area
            </button>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Placeholder for managing study areas (name, boundaries, metadata, etc.).
            </p>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Description</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$studyAreas): ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted">
                                No study areas configured yet
                            </td>
                        </tr>
                    <?php else: foreach ($studyAreas as $code => $name): ?>
                        <tr>
                            <td class="mono"><?= htmlspecialchars($code) ?></td>
                            <td><?= htmlspecialchars($name) ?></td>
                            <td class="text-muted">—</td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-secondary" disabled>Edit</button>
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
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">Model runs</h2>
            <button type="button" class="btn btn-sm btn-primary" disabled>
                + Import / register run
            </button>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Placeholder for managing imported model runs (visibility, default flags, etc.).
            </p>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Run</th>
                        <th>Area</th>
                        <th>Period</th>
                        <th>Time step</th>
                        <th>Visibility</th>
                        <th>Default</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$runs): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">
                                No model runs yet
                            </td>
                        </tr>
                    <?php else: foreach ($runs as $r): ?>
                        <tr>
                            <td class="fw-medium"><?= htmlspecialchars($r['run_label']) ?></td>
                            <td><?= htmlspecialchars(humanStudyArea($r['study_area'])) ?></td>
                            <td>
                                <?php
                                $start = $r['period_start'] ?? null;
                                $end   = $r['period_end'] ?? null;
                                echo htmlspecialchars(($start ?: '—') . ' → ' . ($end ?: '—'));
                                ?>
                            </td>
                            <td>
                                <span class="badge bg-secondary">
                                    <?= htmlspecialchars($r['time_step']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-light text-muted">placeholder</span>
                            </td>
                            <td>
                                <?php if (!empty($r['is_default'])): ?>
                                    <span class="badge bg-success">Default</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-secondary" disabled>Edit</button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
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

<?php include __DIR__ . '/includes/footer.php'; ?>

<script src="/assets/js/data-crops.js"></script>
