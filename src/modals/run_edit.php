<?php
// modals/run_edit.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/SwatRunRepository.php';
require_once __DIR__ . '/../classes/RunLicenseRepository.php';

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

Auth::requireAdmin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$run = $id > 0 ? SwatRunRepository::find($id) : null;

$selectedSubbasins = $run ? SwatRunRepository::selectedSubbasins((int)$run['id']) : [];

if (!$run) {
    echo "<div class='p-3 text-danger'>Run not found.</div>";
    return;
}

$licenses = RunLicenseRepository::all();
$csrf = $_SESSION['csrf_token'] ?? '';
?>

<script>
    ModalUtils.setModalTitle(<?= json_encode('Edit scenario — ' . ($run['run_label'] ?? '')) ?>);
</script>

<div class="p-3">
    <form id="runEditForm" class="row g-3" onsubmit="return false;">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="update_metadata">
        <input type="hidden" name="id" value="<?= (int)$run['id'] ?>">

        <div class="col-md-6">
            <label class="form-label">Study area</label>
            <input type="text" class="form-control" value="<?= h($run['study_area_name'] ?? '') ?>" disabled>
            <div class="form-text">Study area cannot be changed after import.</div>
        </div>

        <div class="col-md-6">
            <label class="form-label">Run name</label>
            <input type="text" name="run_label" class="form-control" required value="<?= h($run['run_label'] ?? '') ?>">
        </div>

        <div class="col-md-4">
            <label class="form-label">Model run date</label>
            <input type="date" name="run_date" class="form-control" value="<?= h(substr((string)($run['run_date'] ?? ''), 0, 10)) ?>">
        </div>

        <div class="col-md-4">
            <label class="form-label">Model run author</label>
            <input type="text" name="model_run_author" class="form-control" value="<?= h($run['model_run_author'] ?? '') ?>">
        </div>

        <div class="col-md-4">
            <label class="form-label">License</label>
            <select name="license_id" class="form-select">
                <option value="">Choose…</option>
                <?php foreach ($licenses as $license): ?>
                    <?php
                    $licenseId = (int)$license['id'];
                    $currentLicenseId = (int)($run['license_id'] ?? 0);
                    ?>
                    <option value="<?= $licenseId ?>" <?= $currentLicenseId === $licenseId ? 'selected' : '' ?>>
                        <?= h($license['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">Publication / GitHub link</label>
            <input type="url" name="publication_url" class="form-control" placeholder="https://..." value="<?= h($run['publication_url'] ?? '') ?>">
        </div>

        <div class="col-md-3">
            <label class="form-label">Visibility</label>
            <select name="visibility" id="runEditVisibility" class="form-select" required>
                <option value="private" <?= (($run['visibility'] ?? 'private') === 'private') ? 'selected' : '' ?>>Private</option>
                <option value="public" <?= (($run['visibility'] ?? 'private') === 'public') ? 'selected' : '' ?>>Public</option>
            </select>
        </div>

        <div class="col-md-3">
            <label class="form-label">Baseline run</label>
            <select name="is_baseline" id="runEditIsBaseline" class="form-select" required>
                <option value="0" <?= empty($run['is_baseline']) ? 'selected' : '' ?>>No</option>
                <option value="1" <?= !empty($run['is_baseline']) ? 'selected' : '' ?>>Yes</option>
            </select>
            <div class="form-text">Used as the baseline scenario. This does not affect default/public status.</div>
        </div>

        <div class="col-md-6 d-flex align-items-end">
            <div class="form-check">
                <input class="form-check-input"
                       type="checkbox"
                       id="runEditIsDownloadable"
                       name="is_downloadable"
                       value="1"
                    <?= !empty($run['is_downloadable']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="runEditIsDownloadable">
                    Dataset is downloadable
                </label>
            </div>
        </div>

        <div class="col-md-6">
            <label class="form-label">Downloadable from date</label>
            <input type="date"
                   name="downloadable_from_date"
                   id="runEditDownloadableFromDate"
                   class="form-control"
                   value="<?= h(substr((string)($run['downloadable_from_date'] ?? ''), 0, 10)) ?>">
        </div>

        <div class="col-12">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="4"><?= h($run['description'] ?? '') ?></textarea>
        </div>

        <div class="col-12">
            <hr>
            <h5 class="mb-2">Subbasins</h5>
            <p class="text-muted small mb-3">
                Select the subbasins where this scenario is available.
            </p>

            <input type="hidden"
                   id="runEditStudyAreaId"
                   value="<?= (int)($run['study_area_id'] ?? $run['study_area'] ?? 0) ?>">

            <input type="hidden"
                   id="runEditInitialSubbasins"
                   value="<?= h(json_encode($selectedSubbasins)) ?>">

            <div class="row g-3">
                <div class="col-lg-7">
                    <label class="form-label">Subbasin map</label>
                    <div id="runEditSubbasinMap" class="border rounded" style="height: 420px;"></div>
                    <div class="form-text">Click subbasins to select or deselect them.</div>
                </div>

                <div class="col-lg-5">
                    <label class="form-label">Selected subbasins</label>

                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="runEditSelectAllSubs">
                            Select all
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="runEditClearSubs">
                            Clear selection
                        </button>
                    </div>

                    <div id="runEditSubbasinChecklist"
                         class="border rounded p-2"
                         style="max-height: 380px; overflow:auto;">
                        <div class="text-muted">Loading subbasins…</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div id="runEditStatus" class="alert d-none mb-0"></div>
        </div>
    </form>

    <div class="d-flex justify-content-end gap-2 mt-3">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
            Cancel
        </button>
        <button type="button" class="btn btn-primary" id="runEditSaveBtn">
            <i class="bi bi-save me-1"></i>
            Save changes
        </button>
    </div>
</div>