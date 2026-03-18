<?php
// modals/custom_scenarios.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function shortDate(?string $value): string {
    if (!$value) return '—';
    try {
        return (new DateTimeImmutable($value))->format('Y-m-d');
    } catch (Throwable $e) {
        return h($value);
    }
}

$studyAreaId   = isset($_GET['study_area_id']) ? (int)$_GET['study_area_id'] : 0;
$studyAreaName = trim((string)($_GET['study_area_name'] ?? ''));
$view          = trim((string)($_GET['view'] ?? 'list'));

if ($studyAreaId <= 0) {
    echo "<div class='p-3 text-danger'>No study area selected.</div>";
    return;
}

if ($view === 'create') {
    $availableRuns = [
        ['id' => 11, 'label' => 'Baseline'],
        ['id' => 12, 'label' => 'High irrigation efficiency'],
        ['id' => 13, 'label' => 'Reduced fertilizer input'],
        ['id' => 14, 'label' => 'Drought response package'],
    ];
    ?>
    <script>
        ModalUtils.setModalTitle("New custom scenario — <?= h($studyAreaName ?: ('Study area #' . $studyAreaId)) ?>");
    </script>

    <div class="p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="text-muted small">
                Create a custom scenario by assigning existing scenarios to subbasins.
            </div>
            <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                id="backToScenarioListBtn">
                <i class="bi bi-arrow-left me-1"></i>
                Back
            </button>
        </div>

        <div class="row g-3">
            <div class="col-12">
                <div class="mb-3">
                    <label for="customScenarioName" class="form-label">Scenario name</label>
                    <input
                        type="text"
                        class="form-control"
                        id="customScenarioName"
                        placeholder="Enter a name">
                </div>

                <div class="mb-3">
                    <label for="customScenarioDescription" class="form-label">Description</label>
                    <textarea
                        class="form-control"
                        id="customScenarioDescription"
                        rows="3"
                        placeholder="Describe this custom scenario"></textarea>
                </div>
            </div>

            <div class="col-12 col-xl-8">
                <div class="border rounded p-2">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <div>
                            <div class="fw-semibold">Subbasin assignments</div>
                            <div class="small text-muted">
                                Select a source scenario and click a subbasin to assign it.
                            </div>
                        </div>

                        <div style="min-width: 260px;">
                            <label for="assignmentRunSelect" class="form-label form-label-sm mb-1">Assign scenario</label>
                            <select id="assignmentRunSelect" class="form-select form-select-sm">
                                <option value="">Choose a scenario…</option>
                                <?php foreach ($availableRuns as $run): ?>
                                    <option value="<?= (int)$run['id'] ?>">
                                        <?= h($run['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="customScenarioMap" style="height: 420px;" class="rounded border"></div>

                    <div class="small text-muted mt-2" id="customScenarioMapHint">
                        No subbasin selected yet.
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-4">
                <div class="border rounded p-2 h-100">
                    <div class="fw-semibold mb-2">Assignments</div>

                    <div id="customScenarioAssignmentList" class="small text-muted">
                        No subbasins assigned yet.
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                Cancel
            </button>
            <button type="button" class="btn btn-primary" id="saveCustomScenarioBtn">
                <i class="bi bi-save me-1"></i>
                Save scenario
            </button>
        </div>
    </div>

    <script
        type="module"
        data-modal-script
        src="/assets/js/dashboard/custom-scenario-create.js">
    </script>

    <script>
        window.__CUSTOM_SCENARIO_CREATE__ = {
            studyAreaId: <?= (int)$studyAreaId ?>,
            studyAreaName: <?= json_encode($studyAreaName) ?>,
            subbasinGeoUrl: <?= json_encode("/api/study_area_subbasins_geo.php?study_area_id=" . $studyAreaId) ?>,
            availableRuns: <?= json_encode($availableRuns) ?>
        };
    </script>
    <?php
    return;
}

// Mock data for now
$scenarios = [
    [
        'id' => 101,
        'name' => 'High irrigation efficiency',
        'created_at' => '2026-03-01 09:14',
        'updated_at' => '2026-03-06 14:32',
        'description' => 'Test scenario with increased irrigation efficiency and reduced conveyance losses.',
    ],
    [
        'id' => 102,
        'name' => 'Reduced fertilizer input',
        'created_at' => '2026-02-20 11:48',
        'updated_at' => '2026-02-28 08:10',
        'description' => 'Scenario used to evaluate lower fertilizer application rates across selected crops.',
    ],
    [
        'id' => 103,
        'name' => 'Drought response package',
        'created_at' => '2026-01-17 16:05',
        'updated_at' => '2026-03-03 10:27',
        'description' => 'Combined water-saving and crop-management adjustments for drought years.',
    ],
];
?>

<script>
    ModalUtils.setModalTitle("Custom scenarios — <?= h($studyAreaName ?: ('Study area #' . $studyAreaId)) ?>");
</script>

<div class="p-3">
    <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
        <div>
            <div class="fw-semibold">User-created scenarios</div>
            <div class="text-muted small">
                Showing mock scenarios for the selected study area.
            </div>
        </div>

        <button
            type="button"
            class="btn btn-sm btn-primary"
            id="newScenarioBtn">
            <i class="bi bi-plus-lg me-1"></i>
            New scenario
        </button>
    </div>

    <?php if (!$scenarios): ?>
        <div class="alert alert-light border mb-0">
            <div class="fw-semibold mb-1">No custom scenarios yet</div>
            <div class="small text-muted">
                Create a new scenario to get started.
            </div>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                <tr>
                    <th>Scenario name</th>
                    <th class="text-nowrap">Created</th>
                    <th class="text-nowrap">Modified</th>
                    <th class="text-center">Info</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($scenarios as $scenario): ?>
                    <tr>
                        <td class="fw-semibold">
                            <?= h($scenario['name']) ?>
                        </td>

                        <td class="text-nowrap small text-muted">
                            <?= shortDate($scenario['created_at']) ?>
                        </td>

                        <td class="text-nowrap small text-muted">
                            <?= shortDate($scenario['updated_at']) ?>
                        </td>

                        <td class="text-center">
                            <button
                                type="button"
                                class="btn btn-sm btn-link text-secondary p-0"
                                data-bs-toggle="popover"
                                data-bs-trigger="hover focus"
                                data-bs-placement="left"
                                data-bs-html="true"
                                title="Description"
                                data-bs-content="<?= h($scenario['description']) ?>">
                                <i class="bi bi-info-circle"></i>
                                <span class="visually-hidden">Show description</span>
                            </button>
                        </td>

                        <td class="text-end text-nowrap">
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                data-bs-toggle="tooltip"
                                title="Edit scenario">
                                <i class="bi bi-pencil"></i>
                            </button>

                            <button
                                type="button"
                                class="btn btn-sm btn-outline-danger ms-1"
                                data-bs-toggle="tooltip"
                                title="Remove scenario">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
    document.querySelectorAll('#ajaxModal [data-bs-toggle="popover"]').forEach(el => {
        bootstrap.Popover.getOrCreateInstance(el, {
            container: 'body'
        });
    });
</script>
<script>
    document.getElementById('newScenarioBtn')?.addEventListener('click', () => {
        ModalUtils.reloadModal(
            `/modals/custom_scenarios.php?study_area_id=<?= (int)$studyAreaId ?>&study_area_name=<?= rawurlencode($studyAreaName) ?>&view=create`
        );
    });
</script>