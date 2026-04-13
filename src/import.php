<?php
// import.php

declare(strict_types=1);

$pageTitle   = 'Import model outputs';
$pageButtons = [];

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/classes/StudyAreaRepository.php';
require_once __DIR__ . '/classes/RunLicenseRepository.php';

Auth::requireAdvanced();

$allAreas      = StudyAreaRepository::all();
$studyAreas    = array_filter($allAreas, fn($row) => !empty($row['enabled']));
$licenses      = RunLicenseRepository::all();
$user          = Auth::user();
$defaultAuthor = trim((string)($user['display_name'] ?? ''));
?>

    <div class="card mb-3">
        <div class="card-body">
            <h1 class="title">Import model run</h1>
            <p class="text-muted mb-4">
                Import a SWAT run from original output files, CSV exports, or later from GitHub.
                The system will inspect the files, detect crops and subbasins, and then import the normalized results.
            </p>

            <div id="importWizard" data-default-author="<?= h($defaultAuthor) ?>">
                <section class="mb-4" id="step1">
                    <h4 class="mb-3">Step 1 — Choose source and inspect files</h4>

                    <form id="inspectForm" class="row g-3" enctype="multipart/form-data" onsubmit="return false;">
                        <input type="hidden" name="csrf" value="<?= h($csrfToken ?? '') ?>">

                        <div class="col-12">
                            <label class="form-label d-block">Import source</label>
                            <div class="btn-group" role="group" aria-label="Import source">
                                <input type="radio" class="btn-check" name="import_source" id="srcOriginal" value="original" checked>
                                <label class="btn btn-outline-primary" for="srcOriginal">Original SWAT output files</label>

                                <input type="radio" class="btn-check" name="import_source" id="srcCsv" value="csv">
                                <label class="btn btn-outline-primary" for="srcCsv">CSV files</label>

                                <input type="radio" class="btn-check" name="import_source" id="srcGithub" value="github">
                                <label class="btn btn-outline-primary" for="srcGithub">GitHub</label>
                            </div>
                        </div>

                        <div id="uploadOriginalBlock" class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">file.cio</label>
                                <input type="file" name="cio_file" class="form-control source-original" accept=".cio,.txt">
                                <div class="form-text">Required for original output import. Used to derive simulation timing metadata.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">output.hru</label>
                                <input type="file" name="hru_file" class="form-control source-original" accept=".hru,.txt">
                                <div class="form-text">Required for original output import.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">output.rch</label>
                                <input type="file" name="rch_file" class="form-control source-original" accept=".rch,.txt">
                                <div class="form-text">Optional for study areas without reach results.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">output.snu</label>
                                <input type="file" name="snu_file" class="form-control source-original" accept=".snu,.txt">
                                <div class="form-text">Required for original output import.</div>
                            </div>
                        </div>

                        <div id="uploadCsvBlock" class="row g-3 d-none">

                            <div class="col-md-6">
                                <label class="form-label">hru.csv</label>
                                <input type="file" name="hru_csv_file" class="form-control source-csv" accept=".csv,.txt">
                                <div class="form-text">Required for CSV import.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">rch.csv</label>
                                <input type="file" name="rch_csv_file" class="form-control source-csv" accept=".csv,.txt">
                                <div class="form-text">Optional for study areas without reach results.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">snu.csv</label>
                                <input type="file" name="snu_csv_file" class="form-control source-csv" accept=".csv,.txt">
                                <div class="form-text">Required for CSV import.</div>
                            </div>
                        </div>

                        <div id="uploadGithubBlock" class="row g-3 d-none">
                            <div class="col-md-6">
                                <label class="form-label">GitHub repository URL</label>
                                <input type="url" name="github_repo_url" class="form-control" placeholder="https://github.com/org/repo" disabled>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Branch / tag</label>
                                <input type="text" name="github_ref" class="form-control" placeholder="main" disabled>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Folder path</label>
                                <input type="text" name="github_path" class="form-control" placeholder="outputs/" disabled>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Personal access token</label>
                                <input type="password" name="github_token" class="form-control" disabled>
                            </div>

                            <div class="col-12">
                                <div class="alert alert-secondary mb-0">
                                    GitHub import UI will be implemented later.
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <button id="btnInspect" type="button" class="btn btn-primary">
                                Inspect files
                            </button>
                        </div>
                    </form>

                    <div id="inspectResult" class="mt-4 d-none">
                        <div class="alert alert-success">
                            Files were parsed successfully. Continue with metadata and assignment.
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-lg-6">
                                <h6>Import summary</h6>
                                <div id="preview-cio" class="border rounded p-2 bg-light small"></div>
                            </div>
                            <div class="col-lg-6">
                                <h6>Detected period</h6>
                                <div id="preview-period" class="border rounded p-2 bg-light small"></div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-lg-4">
                                <h6>HRU preview</h6>
                                <div id="preview-hru" class="border rounded p-2 bg-light small" style="max-height:260px;overflow:auto;"></div>
                            </div>
                            <div class="col-lg-4">
                                <h6>RCH preview</h6>
                                <div id="preview-rch" class="border rounded p-2 bg-light small" style="max-height:260px;overflow:auto;"></div>
                            </div>
                            <div class="col-lg-4">
                                <h6>SNU preview</h6>
                                <div id="preview-snu" class="border rounded p-2 bg-light small" style="max-height:260px;overflow:auto;"></div>
                            </div>
                        </div>
                    </div>
                </section>

                <hr>

                <section class="mb-4 opacity-50" id="step2">
                    <h4 class="mb-3">Step 2 — Metadata</h4>

                    <form id="finalizeForm" class="row g-3" onsubmit="return false;">
                        <input type="hidden" name="csrf" value="<?= h($csrfToken ?? '') ?>">
                        <input type="hidden" name="import_token" id="importToken">

                        <div class="col-md-4">
                            <label class="form-label">Study area</label>
                            <select name="study_area" id="studyAreaSelect" class="form-select" required disabled>
                                <option value="">Choose…</option>
                                <?php foreach ($studyAreas as $area): ?>
                                    <option value="<?= (int)$area['id'] ?>">
                                        <?= h($area['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Run name</label>
                            <input type="text" name="run_label" class="form-control" required disabled>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Model run date</label>
                            <input type="date" name="run_date" class="form-control" value="<?= date('Y-m-d') ?>" required disabled>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Model run author</label>
                            <input type="text" name="model_run_author" class="form-control" value="<?= h($defaultAuthor) ?>" required disabled>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Publication / GitHub link</label>
                            <input type="url" name="publication_url" class="form-control" placeholder="https://..." disabled>
                            <div class="form-text">Optional.</div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">License</label>
                            <select name="license_name" class="form-select" required disabled>
                                <option value="">Choose…</option>
                                <?php foreach ($licenses as $license): ?>
                                    <option value="<?= h($license['name']) ?>"><?= h($license['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Visibility</label>
                            <select name="visibility" class="form-select" required disabled>
                                <option value="private" selected>Private</option>
                                <option value="public">Public</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Baseline run</label>
                            <select name="is_baseline" class="form-select" required disabled>
                                <option value="0" selected>No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>

                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="isDownloadable" name="is_downloadable" value="1" disabled>
                                <label class="form-check-label" for="isDownloadable">
                                    Dataset is downloadable
                                </label>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Downloadable from date</label>
                            <input type="date" name="downloadable_from_date" id="downloadableFromDate" class="form-control" disabled>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3" required disabled></textarea>
                        </div>
                    </form>
                </section>

                <hr>

                <section class="mb-4 opacity-50" id="step3">
                    <h4 class="mb-3">Step 3 — Crops and subbasins</h4>

                    <div id="unknownCropsBlock" class="d-none mb-4">
                        <h5>Unknown crop codes</h5>
                        <p class="text-muted">
                            These crop codes were found in the uploaded files but are not yet in the database.
                            Please provide names before importing.
                        </p>
                        <div id="unknownCropsFields" class="row g-3"></div>
                    </div>

                    <div class="row g-3">
                        <div class="col-lg-7">
                            <label class="form-label">Subbasin map</label>
                            <div id="subbasinMap" class="border rounded" style="height: 480px;"></div>
                            <div class="form-text">
                                Click subbasins to select or deselect them.
                            </div>
                        </div>

                        <div class="col-lg-5">
                            <label class="form-label">Selected subbasins</label>
                            <div class="d-flex gap-2 mb-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSelectDetected" disabled>Select detected</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSelectDetectedOnly" disabled>Select detected</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnClearSubs" disabled>Clear</button>
                            </div>
                            <div id="subbasinChecklist" class="border rounded p-2" style="max-height: 420px; overflow:auto;"></div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button id="btnFinalize" type="button" class="btn btn-success" disabled>Import run</button>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ol@v9.2.4/ol.css">
    <script src="https://cdn.jsdelivr.net/npm/ol@v9.2.4/dist/ol.js"></script>
    <script>
        window.WATDEV_IMPORT_BOOTSTRAP = {
            csrfToken: <?= json_encode($csrfToken ?? '') ?>,
            defaultAuthor: <?= json_encode($defaultAuthor) ?>
        };
    </script>
    <script src="/assets/js/import-run.js"></script>

<?php include __DIR__ . '/includes/footer.php'; ?>