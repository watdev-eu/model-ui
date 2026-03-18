<?php
// modals/custom_scenarios.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/CustomScenarioRepository.php';

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
$scenarioId    = isset($_GET['scenario_id']) ? (int)$_GET['scenario_id'] : 0;

Auth::requireLogin();

$userId = Auth::userId();
if ($userId === null) {
    echo "<div class='p-3 text-danger'>Unauthorized.</div>";
    return;
}

if ($studyAreaId <= 0) {
    echo "<div class='p-3 text-danger'>No study area selected.</div>";
    return;
}

if ($view === 'create') {
    require_once __DIR__ . '/../classes/Auth.php';
    require_once __DIR__ . '/../classes/CustomScenarioRepository.php';

    $userId = Auth::userId();
    $currentScenario = null;
    $currentAssignments = [];

    if ($scenarioId > 0 && $userId !== null) {
        $currentScenario = CustomScenarioRepository::findByIdForUser($scenarioId, $userId);
        if ($currentScenario) {
            $currentAssignments = CustomScenarioRepository::findAssignments($scenarioId, $userId);
        }
    }
    ?>
    <script>
        ModalUtils.setModalTitle(
                <?= json_encode(($currentScenario ? 'Edit custom scenario — ' : 'New custom scenario — ') . ($studyAreaName ?: ('Study area #' . $studyAreaId))) ?>
        );
    </script>

    <div class="p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="text-muted small">
                <?= $currentScenario
                        ? 'Update the custom scenario by adjusting the scenario assignments per subbasin.'
                        : 'Create a custom scenario by assigning existing scenarios to subbasins.' ?>
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
                        placeholder="Enter a name"
                        value="<?= h($currentScenario['name'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label for="customScenarioDescription" class="form-label">Description</label>
                    <textarea
                            class="form-control"
                            id="customScenarioDescription"
                            rows="3"
                            placeholder="Describe this custom scenario"><?= h($currentScenario['description'] ?? '') ?></textarea>
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
                            <div class="small mt-2">
                                <span class="fw-semibold">Assignment progress:</span>
                                <span id="customScenarioAssignmentProgress" class="text-muted">Loading…</span>
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
                <?= $currentScenario ? 'Update scenario' : 'Save scenario' ?>
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
            scenarioId: <?= (int)($currentScenario['id'] ?? 0) ?>,
            initialName: <?= json_encode((string)($currentScenario['name'] ?? '')) ?>,
            initialDescription: <?= json_encode((string)($currentScenario['description'] ?? '')) ?>,
            initialAssignments: <?= json_encode($currentAssignments) ?>,
            subbasinGeoUrl: <?= json_encode("/api/study_area_subbasins_geo.php?study_area_id=" . $studyAreaId) ?>,
            runsListUrl: <?= json_encode("/api/runs_list.php?study_area_id=" . $studyAreaId) ?>,
            saveUrl: <?= json_encode("/api/custom_scenario_save.php") ?>,
            deleteUrl: <?= json_encode("/api/custom_scenario_delete.php") ?>,
            listModalUrl: <?= json_encode("/modals/custom_scenarios.php?study_area_id=" . $studyAreaId . "&study_area_name=" . rawurlencode($studyAreaName)) ?>
        };
    </script>
    <?php
    return;
}

$scenarios = CustomScenarioRepository::listByStudyAreaForUser($studyAreaId, $userId);
?>

<script>
    ModalUtils.setModalTitle("Custom scenarios — <?= h($studyAreaName ?: ('Study area #' . $studyAreaId)) ?>");
</script>

<div class="p-3">
    <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
        <div>
            <div class="fw-semibold">User-created scenarios</div>
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
                                    data-bs-content="<?= h($scenario['description'] ?? '') ?>">
                                <i class="bi bi-info-circle"></i>
                                <span class="visually-hidden">Show description</span>
                            </button>
                        </td>

                        <td class="text-end text-nowrap">
                            <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    data-edit-scenario-id="<?= (int)$scenario['id'] ?>"
                                    data-bs-toggle="tooltip"
                                    title="Edit scenario">
                                <i class="bi bi-pencil"></i>
                            </button>

                            <button
                                    type="button"
                                    class="btn btn-sm btn-outline-danger ms-1"
                                    data-delete-scenario-id="<?= (int)$scenario['id'] ?>"
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
<script>
    document.querySelectorAll('[data-edit-scenario-id]').forEach(btn => {
        btn.addEventListener('click', () => {
            const scenarioId = btn.getAttribute('data-edit-scenario-id');
            if (!scenarioId) return;

            ModalUtils.reloadModal(
                `/modals/custom_scenarios.php?study_area_id=<?= (int)$studyAreaId ?>&study_area_name=<?= rawurlencode($studyAreaName) ?>&view=create&scenario_id=${encodeURIComponent(scenarioId)}`
            );
        });
    });

    document.querySelectorAll('[data-delete-scenario-id]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const scenarioId = parseInt(btn.getAttribute('data-delete-scenario-id') || '0', 10);
            if (!scenarioId) return;

            const ok = window.confirm('Are you sure you want to delete this custom scenario?');
            if (!ok) return;

            try {
                const res = await fetch('/api/custom_scenario_delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ id: scenarioId })
                });

                const json = await res.json().catch(() => ({}));

                if (!res.ok || json.status !== 'ok') {
                    throw new Error(json.message || `Delete failed (HTTP ${res.status})`);
                }

                showToast(json.message || 'Scenario deleted.', false, null, 'OK', 2500);

                ModalUtils.reloadModal(
                    `/modals/custom_scenarios.php?study_area_id=<?= (int)$studyAreaId ?>&study_area_name=<?= rawurlencode($studyAreaName) ?>`
                );
            } catch (err) {
                console.error('[custom-scenarios] delete failed', err);
                showToast(err.message || 'Failed to delete scenario.', true, null, 'OK', 5000);
            }
        });
    });
</script>