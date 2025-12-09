<?php
// src/import.php
$pageTitle   = 'Import model outputs';
$pageButtons = [];

require_once __DIR__ . '/config/app.php';
// require_admin();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/classes/StudyAreaRepository.php';

// Fetch enabled study areas
$allAreas    = StudyAreaRepository::all();
$studyAreas  = array_filter($allAreas, fn($row) => !empty($row['enabled']));
?>

    <div class="card mb-3">
        <div class="card-body">
            <h1 class="title">Import SWAT outputs / KPI CSVs</h1>
            <p class="text-muted mb-4">
                Upload the CSV files.
            </p>

            <form id="importForm" class="row g-3" enctype="multipart/form-data" onsubmit="return false;">
                <div class="col-12">
                    <h5 class="mb-2">Run metadata</h5>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Study area</label>
                    <select name="study_area" class="form-select" required>
                        <?php if ($studyAreas): ?>
                            <?php foreach ($studyAreas as $area): ?>
                                <option value="<?= (int)$area['id'] ?>">
                                    <?= h($area['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="">No enabled study areas found</option>
                        <?php endif; ?>
                    </select>
                    <div class="form-text">
                        Only enabled study areas are listed.
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Scenario name</label>
                    <input name="run_label" class="form-control"
                           placeholder="e.g. baseline-2025-09" required>
                    <div class="form-text">
                        Must be unique within the selected study area.
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Run date</label>
                    <input type="date" name="run_date" class="form-control"
                           value="<?php echo date('Y-m-d'); ?>" required>
                    <div class="form-text">
                        Date when the model run was created.
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Visibility</label>
                    <select name="visibility" class="form-select">
                        <option value="private" selected>Private</option>
                        <option value="public">Public</option>
                    </select>
                    <div class="form-text">
                        Public runs are visible to all users.
                    </div>
                </div>

                <div class="col-12 mt-3">
                    <label class="form-label">Comments / description</label>
                    <textarea name="description" class="form-control" rows="3"
                              placeholder="Short description of this scenario (assumptions, notes, etc.)"></textarea>
                </div>

                <div class="col-12"><hr></div>

                <!-- CSV format options -->
                <div class="col-12">
                    <h5 class="mb-2">CSV format</h5>
                    <p class="form-text">
                        Configure how the CSVs are formatted. For the SWAT outputs you pasted,
                        <strong>field separator = semicolon</strong> is correct.
                        The first row of each CSV is expected to be a header row.
                    </p>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Field separator</label>
                    <select name="field_sep" id="fieldSep" class="form-select" required>
                        <option value=";">Semicolon (<code>;</code>)</option>
                        <option value=",">Comma (<code>,</code>)</option>
                        <option value="\t">Tab</option>
                    </select>
                </div>

                <div class="col-12"><hr></div>

                <div class="col-12">
                    <h5 class="mb-2">KPI CSV files</h5>
                    <p class="form-text">
                        Upload one or more CSVs. Each file must use the same CSV format as configured above.
                        Required columns are validated automatically from the header row.
                    </p>
                </div>

                <div class="col-md-4">
                    <label class="form-label">HRU CSV</label>
                    <input type="file" name="hru_csv" class="form-control csv-input" accept=".csv,.txt">
                </div>
                <div class="col-md-4">
                    <label class="form-label">RCH CSV</label>
                    <input type="file" name="rch_csv" class="form-control csv-input" accept=".csv,.txt">
                </div>
                <div class="col-md-4">
                    <label class="form-label">SNU CSV</label>
                    <input type="file" name="snu_csv" class="form-control csv-input" accept=".csv,.txt">
                </div>

                <!-- Per-file preview areas -->
                <div class="col-12 mt-4">
                    <h5 class="mb-2">Previews & header checks</h5>
                </div>

                <div class="col-md-4">
                    <h6 class="mb-1">HRU preview</h6>
                    <div id="preview-hru" class="border rounded small p-2 bg-light"
                         style="max-height: 240px; overflow:auto;">
                        <span class="text-muted">Select an HRU CSV to show a preview.</span>
                    </div>
                    <div id="previewWarning-hru" class="small mt-1 text-muted"></div>
                </div>

                <div class="col-md-4">
                    <h6 class="mb-1">RCH preview</h6>
                    <div id="preview-rch" class="border rounded small p-2 bg-light"
                         style="max-height: 240px; overflow:auto;">
                        <span class="text-muted">Select an RCH CSV to show a preview.</span>
                    </div>
                    <div id="previewWarning-rch" class="small mt-1 text-muted"></div>
                </div>

                <div class="col-md-4">
                    <h6 class="mb-1">SNU preview</h6>
                    <div id="preview-snu" class="border rounded small p-2 bg-light"
                         style="max-height: 240px; overflow:auto;">
                        <span class="text-muted">Select a SNU CSV to show a preview.</span>
                    </div>
                    <div id="previewWarning-snu" class="small mt-1 text-muted"></div>
                </div>

                <div class="col-12 d-flex gap-2 mt-3">
                    <button id="btnImport" class="btn btn-primary">Import</button>
                    <button id="btnReset" type="reset" class="btn btn-outline-secondary">Reset</button>
                </div>

                <div id="status" class="col-12 mt-2"></div>
            </form>
        </div>
    </div>

    <script>
        (function(){
            const btnImport      = document.getElementById('btnImport');
            const btnReset       = document.getElementById('btnReset');
            const statusBox      = document.getElementById('status');
            const form           = document.getElementById('importForm');
            const fieldSepSel    = document.getElementById('fieldSep');

            // Expected headers per CSV (order + names)
            // Which SWAT columns must be present in each CSV (case-insensitive)
            const REQUIRED_COLUMNS = {
                hru: [
                    'LULC', 'HRU', 'HRUGIS', 'SUB', 'YEAR',
                    'MON', 'AREAkm2', 'IRRmm', 'SA_IRRmm',
                    'DA_IRRmm', 'YLDt_ha', 'BIOMt_ha', 'SYLDt_ha',
                    'NUP_kg_ha', 'PUPkg_ha', 'NO3Lkg_ha',
                    'N_APPkg_ha', 'P_APPkg_ha', 'N_AUTOkg_ha',
                    'P_AUTOkg_ha', 'NGRZkg_ha', 'PGRZkg_ha',
                    'NCFRTkg_ha', 'PCFRTkg_ha'
                ],
                rch: [
                    // SUB, YEAR, MON, AREAkm2, FLOW_INcms, FLOW_OUTcms, ..., SED_OUTtons, ...
                    'SUB', 'YEAR', 'MON', 'AREAkm2',
                    'FLOW_OUTcms', 'NO3_OUTkg', 'SED_OUTtons'
                ],
                snu: [
                    'YEAR', 'DAY', 'HRUGIS', 'SOL_RSD',
                    'SOL_P', 'NO3', 'ORG_N', 'ORG_P', 'CN'
                ]
            };

            function showAlert(html, type='info') {
                statusBox.innerHTML =
                    `<div class="alert alert-${type} d-flex align-items-center" role="alert">
                       <div>${html}</div>
                     </div>`;
            }

            function normalizeFieldSepValue(v) {
                if (v === '\\t') return '\t';
                return v;
            }

            const filesConfig = {
                hru: {
                    label: 'HRU',
                    input: form.querySelector('input[name="hru_csv"]'),
                    previewBox: document.getElementById('preview-hru'),
                    warningBox: document.getElementById('previewWarning-hru')
                },
                rch: {
                    label: 'RCH',
                    input: form.querySelector('input[name="rch_csv"]'),
                    previewBox: document.getElementById('preview-rch'),
                    warningBox: document.getElementById('previewWarning-rch')
                },
                snu: {
                    label: 'SNU',
                    input: form.querySelector('input[name="snu_csv"]'),
                    previewBox: document.getElementById('preview-snu'),
                    warningBox: document.getElementById('previewWarning-snu')
                }
            };

            // Tracks header validity per selected file (null = no file, true = ok, false = error)
            const fileValidity = { hru: null, rch: null, snu: null };

            function resetPreview(key) {
                const cfg = filesConfig[key];
                cfg.previewBox.innerHTML =
                    `<span class="text-muted">Select a ${cfg.label} CSV to show a preview.</span>`;
                cfg.warningBox.textContent = '';
                cfg.warningBox.className = 'small mt-1 text-muted';
                fileValidity[key] = null;
            }

            function updateImportButtonState() {
                // Disable import if any *selected* file has invalid headers
                let anyInvalid = false;
                Object.keys(filesConfig).forEach(key => {
                    const cfg = filesConfig[key];
                    if (cfg.input.files && cfg.input.files[0] && fileValidity[key] === false) {
                        anyInvalid = true;
                    }
                });
                btnImport.disabled = anyInvalid;
                btnImport.title = anyInvalid
                    ? 'Fix header errors in the previews before importing.'
                    : '';
            }

            function getMissingRequiredColumns(detected, requiredList) {
                const detectedLower = detected.map(h => h.trim().toLowerCase());
                const requiredLower = requiredList.map(h => h.trim().toLowerCase());

                const missing = [];
                requiredLower.forEach((req, idx) => {
                    if (!detectedLower.includes(req)) {
                        // Report back the original required name, not the lowercased one
                        missing.push(requiredList[idx]);
                    }
                });
                return missing;
            }

            async function updatePreviewFor(key) {
                const cfg = filesConfig[key];
                const file = (cfg.input.files && cfg.input.files[0]) ? cfg.input.files[0] : null;

                if (!file) {
                    resetPreview(key);
                    updateImportButtonState();
                    return;
                }

                const separator = normalizeFieldSepValue(fieldSepSel.value);

                const text = await new Promise((resolve, reject) => {
                    const reader = new FileReader();
                    reader.onload = e => resolve(e.target.result);
                    reader.onerror = reject;
                    // Read just first 64 KB, more than enough for a preview
                    reader.readAsText(file.slice(0, 64 * 1024));
                });

                const lines = text.split(/\r?\n/).filter(l => l.trim() !== '');
                if (!lines.length) {
                    cfg.previewBox.innerHTML = '<span class="text-muted">File appears to be empty.</span>';
                    cfg.warningBox.textContent = '';
                    cfg.warningBox.className = 'small mt-1 text-muted';
                    fileValidity[key] = false;
                    updateImportButtonState();
                    return;
                }

                const maxRows  = Math.min(lines.length, 6); // header + up to 5 data rows
                const rows = [];

                for (let i = 0; i < maxRows; i++) {
                    const line = lines[i];
                    const cells = line.split(separator).map(c => {
                        c = c.trim();
                        if (c.startsWith('"') && c.endsWith('"')) {
                            c = c.slice(1, -1);
                        }
                        return c;
                    });
                    rows.push(cells);
                }

                const headerRow = rows[0] || [];
                const dataRows  = rows.slice(1);
                const colCount  = headerRow.length;

                let html = '<table class="table table-sm table-bordered table-hover mb-0"><tbody>';
                rows.forEach((cells, idx) => {
                    const isHeaderRow = idx === 0;
                    html += '<tr>';
                    cells.forEach(c => {
                        html += isHeaderRow
                            ? `<th class="bg-light">${c || '&nbsp;'}</th>`
                            : `<td>${c || '&nbsp;'}</td>`;
                    });
                    html += '</tr>';
                });
                html += '</tbody></table>';

                cfg.previewBox.innerHTML = html;

                // Column / header checks
                const required = REQUIRED_COLUMNS[key] || [];
                const missing = getMissingRequiredColumns(headerRow, required);

                if (colCount <= 1) {
                    cfg.warningBox.className = 'small mt-1 text-danger';
                    cfg.warningBox.textContent =
                        'Only one column detected. The field separator may be wrong for this file.';
                    fileValidity[key] = false;
                } else if (missing.length) {
                    cfg.warningBox.className = 'small mt-1 text-danger';
                    cfg.warningBox.innerHTML =
                        `Some required columns are missing for ${cfg.label}:<br>` +
                        `<code>${missing.join(', ')}</code><br>` +
                        `Detected columns (${colCount}):<br>` +
                        `<code>${headerRow.join(', ')}</code>`;
                    fileValidity[key] = false;
                } else {
                    cfg.warningBox.className = 'small mt-1 text-success';
                    cfg.warningBox.textContent =
                        `Header OK. Found all required columns (${required.length}) in a total of ${colCount} columns.`;
                    fileValidity[key] = true;
                }

                updateImportButtonState();
            }

            function updateAllPreviews() {
                Object.keys(filesConfig).forEach(key => {
                    const cfg = filesConfig[key];
                    if (cfg.input.files && cfg.input.files[0]) {
                        updatePreviewFor(key);
                    } else {
                        resetPreview(key);
                    }
                });
                updateImportButtonState();
            }

            // React to file changes and format changes
            Object.keys(filesConfig).forEach(key => {
                const cfg = filesConfig[key];
                cfg.input.addEventListener('change', () => {
                    updatePreviewFor(key);
                });
            });

            // Handle reset
            btnReset.addEventListener('click', () => {
                // Let the browser clear the form first
                setTimeout(() => {
                    Object.keys(filesConfig).forEach(key => resetPreview(key));
                    statusBox.innerHTML = '';
                    updateImportButtonState();
                }, 0);
            });

            // --- Submit handler ---

            btnImport.addEventListener('click', async () => {
                const fd = new FormData(form);

                if (!fd.get('study_area') || !fd.get('run_label')) {
                    showAlert('Please fill in <b>Study area</b> and <b>Scenario name</b>.', 'warning');
                    return;
                }

                const hasAny =
                    filesConfig.hru.input.files.length ||
                    filesConfig.rch.input.files.length ||
                    filesConfig.snu.input.files.length;

                if (!hasAny) {
                    showAlert('Please select at least one CSV (HRU / RCH / SNU).', 'warning');
                    return;
                }

                // If any selected file has invalid headers, block submit (defensive)
                let anyInvalid = false;
                Object.keys(filesConfig).forEach(key => {
                    const cfg = filesConfig[key];
                    if (cfg.input.files.length && fileValidity[key] === false) {
                        anyInvalid = true;
                    }
                });
                if (anyInvalid) {
                    showAlert('Fix the header errors in the previews before importing.', 'warning');
                    return;
                }

                showAlert('<span class="spinner-border spinner-border-sm me-2"></span>Importing… This may take a moment.', 'primary');
                btnImport.disabled = true;

                try {
                    const res  = await fetch('/api/import_run.php', {
                        method: 'POST',
                        body: fd,
                        credentials: 'include'
                    });
                    const data = await res.json();
                    if (data.ok) {
                        showAlert(`✅ Imported successfully. <b>Run ID:</b> ${data.run_id}`, 'success');
                        form.reset();
                        Object.keys(filesConfig).forEach(key => resetPreview(key));
                    } else {
                        showAlert(`❌ Import failed: ${data.error || 'Unknown error.'}`, 'danger');
                    }
                } catch (e) {
                    console.error(e);
                    showAlert('❌ Network or server error during import.', 'danger');
                } finally {
                    btnImport.disabled = false;
                    updateImportButtonState();
                }
            });

            // Initial state
            Object.keys(filesConfig).forEach(key => resetPreview(key));
            updateImportButtonState();
        })();
    </script>

<?php include __DIR__ . '/includes/footer.php'; ?>