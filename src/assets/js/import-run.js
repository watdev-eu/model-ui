// assets/js/import-run.js

(function () {
    const inspectForm = document.getElementById('inspectForm');
    const finalizeForm = document.getElementById('finalizeForm');
    const btnInspect = document.getElementById('btnInspect');
    const btnFinalize = document.getElementById('btnFinalize');
    const inspectResult = document.getElementById('inspectResult');
    const importTokenInput = document.getElementById('importToken');
    const studyAreaSelect = document.getElementById('studyAreaSelect');
    const isDownloadable = document.getElementById('isDownloadable');
    const downloadableFromDate = document.getElementById('downloadableFromDate');
    const unknownCropsBlock = document.getElementById('unknownCropsBlock');
    const unknownCropsFields = document.getElementById('unknownCropsFields');
    const subbasinChecklist = document.getElementById('subbasinChecklist');
    const btnSelectDetected = document.getElementById('btnSelectDetected');
    const btnSelectDetectedOnly = document.getElementById('btnSelectDetectedOnly');
    const btnClearSubs = document.getElementById('btnClearSubs');

    const uploadOriginalBlock = document.getElementById('uploadOriginalBlock');
    const uploadCsvBlock = document.getElementById('uploadCsvBlock');
    const uploadGithubBlock = document.getElementById('uploadGithubBlock');

    const githubRepoUrl = document.getElementById('githubRepoUrl');
    const githubToken = document.getElementById('githubToken');
    const githubRefSelect = document.getElementById('githubRefSelect');
    const githubScenarioSelect = document.getElementById('githubScenarioSelect');
    const btnLoadGithubBranches = document.getElementById('btnLoadGithubBranches');
    const btnLoadGithubScenarios = document.getElementById('btnLoadGithubScenarios');

    let inspectData = null;
    let selectedSubbasins = new Set();
    let allSubbasins = [];
    let detectedSubbasins = [];
    let map, vectorSource, selectionLayer;
    let inspectRequestEpoch = 0;

    function setButtonBusy(button, busy, busyText, idleText) {
        if (!button) return;
        if (!button.dataset.idleText) {
            button.dataset.idleText = idleText || button.textContent.trim();
        }

        if (busy) {
            button.disabled = true;
            button.dataset.wasBusy = '1';
            button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${escapeHtml(busyText)}`;
        } else {
            button.disabled = false;
            button.innerHTML = escapeHtml(button.dataset.idleText);
            delete button.dataset.wasBusy;
        }
    }

    function ensureStatusBox() {
        let el = document.getElementById('importStatusMessage');
        if (el) return el;

        el = document.createElement('div');
        el.id = 'importStatusMessage';
        el.className = 'alert alert-info d-none mt-3';
        inspectForm.insertAdjacentElement('afterend', el);
        return el;
    }

    function setStatusMessage(message, type = 'info') {
        const el = ensureStatusBox();
        el.className = `alert alert-${type} mt-3`;
        el.textContent = message;
        el.classList.remove('d-none');
    }

    function clearStatusMessage() {
        const el = document.getElementById('importStatusMessage');
        if (!el) return;
        el.textContent = '';
        el.classList.add('d-none');
    }

    function setFinalizeStatusMessage(message, type = 'info') {
        const el = document.getElementById('finalizeStatusMessage');
        if (!el) return;

        el.className = `alert alert-${type} mt-3`;
        el.textContent = message;
        el.classList.remove('d-none');
    }

    function resetInspectionState() {
        inspectData = null;
        detectedSubbasins = [];
        selectedSubbasins = new Set();

        importTokenInput.value = '';
        inspectResult.classList.add('d-none');
        clearStatusMessage();

        setPreview('preview-cio', '');
        setPreview('preview-period', '');
        setPreview('preview-hru', '');
        setPreview('preview-rch', '');
        setPreview('preview-snu', '');

        unknownCropsFields.innerHTML = '';
        unknownCropsBlock.classList.add('d-none');

        document.querySelectorAll('#step2 .form-control, #step2 .form-select, #step2 .form-check-input').forEach(el => {
            el.disabled = true;
        });

        document.getElementById('step2').classList.add('opacity-50');
        document.getElementById('step3').classList.add('opacity-50');

        btnFinalize.disabled = true;
        btnSelectDetected.disabled = true;
        btnSelectDetectedOnly.disabled = true;
        btnClearSubs.disabled = true;

        renderSubbasinChecklist();
        refreshMapStyles();
        inspectRequestEpoch++;
    }

    function currentSource() {
        return inspectForm.querySelector('input[name="import_source"]:checked')?.value || 'original';
    }

    function toggleSourceUi() {
        const source = currentSource();

        uploadOriginalBlock.classList.toggle('d-none', source !== 'original');
        uploadCsvBlock.classList.toggle('d-none', source !== 'csv');
        uploadGithubBlock.classList.toggle('d-none', source !== 'github');

        inspectForm.querySelectorAll('.source-original').forEach(el => {
            el.required = source === 'original' && ['cio_file', 'hru_file', 'snu_file'].includes(el.name);
        });

        inspectForm.querySelectorAll('.source-csv').forEach(el => {
            el.required = source === 'csv' && ['hru_csv_file', 'snu_csv_file'].includes(el.name);
        });

        btnInspect.disabled = false;
        btnInspect.textContent = source === 'github' ? 'Inspect GitHub files' : 'Inspect files';

        inspectForm.querySelectorAll('.source-github').forEach(el => {
            el.disabled = source !== 'github';
            if (el.name === 'github_repo_url' || el.name === 'scenario_path') {
                el.required = source === 'github';
            }
        });

        resetInspectionState();
    }

    function enableStep2And3() {
        document.querySelectorAll('#step2 .form-control, #step2 .form-select, #step2 .form-check-input').forEach(el => {
            el.disabled = false;
        });
        document.getElementById('step2').classList.remove('opacity-50');
        document.getElementById('step3').classList.remove('opacity-50');
        btnFinalize.disabled = false;
        btnSelectDetected.disabled = false;
        btnSelectDetectedOnly.disabled = false;
        btnClearSubs.disabled = false;

        downloadableFromDate.disabled = !isDownloadable.checked;
        if (!isDownloadable.checked) {
            downloadableFromDate.value = '';
        }
    }

    function escapeHtml(v) {
        return String(v ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function setPreview(id, html) {
        const el = document.getElementById(id);
        if (el) {
            el.innerHTML = html || '<span class="text-muted">No preview available.</span>';
        }
    }

    function renderCioSummary(cio) {
        const html = `
            <div><strong>Simulation years:</strong> ${escapeHtml(cio?.simulation_years ?? '')}</div>
            <div><strong>Begin year:</strong> ${escapeHtml(cio?.begin_year ?? '')}</div>
            <div><strong>Begin julian day:</strong> ${escapeHtml(cio?.begin_julian_day ?? '')}</div>
            <div><strong>Skip years:</strong> ${escapeHtml(cio?.skip_years ?? '')}</div>
            <div><strong>Printed begin year:</strong> ${escapeHtml(cio?.printed_begin_year ?? '')}</div>
            <div><strong>Printed begin date:</strong> ${escapeHtml(cio?.printed_begin_date ?? '')}</div>
        `;
        setPreview('preview-cio', html);
    }

    function renderPeriodSummary(start, end) {
        setPreview(
            'preview-period',
            `<div><strong>Detected start:</strong> ${escapeHtml(start || '')}</div>
             <div><strong>Detected end:</strong> ${escapeHtml(end || '')}</div>`
        );
    }

    function renderUnknownCrops(codes) {
        unknownCropsFields.innerHTML = '';
        if (!codes || !codes.length) {
            unknownCropsBlock.classList.add('d-none');
            return;
        }

        unknownCropsBlock.classList.remove('d-none');

        codes.forEach(code => {
            const div = document.createElement('div');
            div.className = 'col-md-6';
            div.innerHTML = `
                <div class="border rounded p-3 h-100">
                    <label class="form-label">Crop name for <code>${escapeHtml(code)}</code></label>
                    <input type="text"
                           class="form-control unknown-crop-name mb-2"
                           data-code="${escapeHtml(code)}"
                           required>
    
                    <label class="form-label">Dry matter fraction</label>
                    <input type="number"
                           class="form-control unknown-crop-dry-matter"
                           data-code="${escapeHtml(code)}"
                           min="0"
                           max="1"
                           step="0.001"
                           placeholder="e.g. 0.86">
    
                    <div class="form-text">
                        Optional. Leave empty for fallback 1.
                    </div>
                </div>
            `;
            unknownCropsFields.appendChild(div);
        });
    }

    function readUnknownCropNames() {
        const result = {};
        document.querySelectorAll('.unknown-crop-name').forEach(input => {
            result[input.dataset.code] = input.value.trim();
        });
        return result;
    }

    function readUnknownCropDryMatterFractions() {
        const result = {};
        document.querySelectorAll('.unknown-crop-dry-matter').forEach(input => {
            result[input.dataset.code] = input.value.trim();
        });
        return result;
    }

    function renderSubbasinChecklist() {
        if (!allSubbasins.length) {
            subbasinChecklist.innerHTML = '<div class="text-muted">Choose a study area to load subbasins.</div>';
            return;
        }

        const detectedSet = new Set(detectedSubbasins);
        subbasinChecklist.innerHTML = allSubbasins.map(sub => `
            <div class="form-check">
                <input class="form-check-input subbasin-check" type="checkbox" value="${sub}" id="sub_${sub}" ${selectedSubbasins.has(sub) ? 'checked' : ''}>
                <label class="form-check-label" for="sub_${sub}">
                    Subbasin ${sub} ${detectedSet.has(sub) ? '<span class="badge text-bg-info ms-1">detected</span>' : ''}
                </label>
            </div>
        `).join('');

        document.querySelectorAll('.subbasin-check').forEach(cb => {
            cb.addEventListener('change', () => {
                const sub = parseInt(cb.value, 10);
                if (cb.checked) selectedSubbasins.add(sub);
                else selectedSubbasins.delete(sub);
                refreshMapStyles();
            });
        });
    }

    function selectedStyle(feature) {
        const sub = feature.get('Subbasin');
        const selected = selectedSubbasins.has(Number(sub));
        return new ol.style.Style({
            stroke: new ol.style.Stroke({
                color: selected ? '#0d6efd' : '#666',
                width: selected ? 2.5 : 1
            }),
            fill: new ol.style.Fill({
                color: selected ? 'rgba(13,110,253,0.28)' : 'rgba(108,117,125,0.10)'
            }),
            text: new ol.style.Text({
                text: String(sub ?? ''),
                font: '12px sans-serif',
                fill: new ol.style.Fill({ color: '#222' })
            })
        });
    }

    function refreshMapStyles() {
        if (!selectionLayer) return;
        selectionLayer.setStyle(selectedStyle);
        renderSubbasinChecklist();
    }

    async function loadStudyAreaSubbasins(studyAreaId) {
        const res = await fetch(`/api/study_area_subbasins_geo.php?study_area_id=${encodeURIComponent(studyAreaId)}`, {
            credentials: 'include'
        });
        const data = await res.json();
        if (!res.ok) {
            throw new Error(data.error || 'Failed to load subbasins');
        }

        const format = new ol.format.GeoJSON();
        const features = format.readFeatures(data);
        vectorSource.clear();
        vectorSource.addFeatures(features);

        allSubbasins = features
            .map(f => parseInt(f.get('Subbasin'), 10))
            .filter(v => Number.isInteger(v) && v > 0)
            .sort((a, b) => a - b);

        renderSubbasinChecklist();

        if (features.length) {
            map.getView().fit(vectorSource.getExtent(), { padding: [20, 20, 20, 20], duration: 250 });
        }
    }

    if (btnLoadGithubBranches) {
        btnLoadGithubBranches.addEventListener('click', loadGithubBranches);
    }

    if (btnLoadGithubScenarios) {
        btnLoadGithubScenarios.addEventListener('click', loadGithubScenarios);
    }

    if (githubRefSelect) {
        githubRefSelect.addEventListener('change', () => {
            githubScenarioSelect.innerHTML = '<option value="">Choose scenario…</option>';
            resetInspectionState();
        });
    }

    if (githubScenarioSelect) {
        githubScenarioSelect.addEventListener('change', resetInspectionState);
    }

    function initMap() {
        vectorSource = new ol.source.Vector();
        selectionLayer = new ol.layer.Vector({
            source: vectorSource,
            style: selectedStyle
        });

        map = new ol.Map({
            target: 'subbasinMap',
            layers: [
                new ol.layer.Tile({ source: new ol.source.OSM() }),
                selectionLayer
            ],
            view: new ol.View({
                center: [0, 0],
                zoom: 2
            })
        });

        map.on('singleclick', function (evt) {
            map.forEachFeatureAtPixel(evt.pixel, function (feature) {
                const sub = parseInt(feature.get('Subbasin'), 10);
                if (!sub) return;
                if (selectedSubbasins.has(sub)) selectedSubbasins.delete(sub);
                else selectedSubbasins.add(sub);
                refreshMapStyles();
            });
        });
    }

    function postFormWithProgress(url, fd, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();

            xhr.open('POST', url, true);
            xhr.withCredentials = true;
            xhr.timeout = 0;

            xhr.upload.onprogress = event => {
                if (!event.lengthComputable) {
                    onProgress?.('Uploading files...');
                    return;
                }

                const pct = Math.round((event.loaded / event.total) * 100);
                onProgress?.(`Uploading files... ${pct}%`);
            };

            xhr.onload = () => {
                let data;

                try {
                    data = JSON.parse(xhr.responseText || '{}');
                } catch (_) {
                    data = {
                        ok: false,
                        error: `Server returned HTTP ${xhr.status}, but not valid JSON.`
                    };
                }

                resolve({
                    ok: xhr.status >= 200 && xhr.status < 300,
                    status: xhr.status,
                    statusText: xhr.statusText,
                    data
                });
            };

            xhr.onerror = () => reject(new Error('Network error during upload.'));
            xhr.ontimeout = () => reject(new Error('Upload timed out.'));

            xhr.send(fd);
        });
    }

    function buildInspectionErrorMessage(data, status) {
        const parts = [];

        parts.push(data?.error || `Inspection failed with HTTP ${status}.`);

        if (data?.detail) {
            parts.push(data.detail);
        }

        if (data?.request_id) {
            parts.push(`Reference: ${data.request_id}`);
        }

        return parts.join(' ');
    }

    function githubFormParams() {
        const params = new URLSearchParams();
        params.set('github_repo_url', githubRepoUrl?.value || '');
        params.set('github_token', githubToken?.value || '');
        params.set('github_ref', githubRefSelect?.value || 'output');
        return params;
    }

    async function fetchJson(url, options = {}) {
        const res = await fetch(url, {
            credentials: 'include',
            ...options
        });

        const data = await res.json();

        if (!res.ok || !data.ok) {
            throw new Error(data.error || 'Request failed.');
        }

        return data;
    }

    async function loadGithubBranches() {
        if (!githubRepoUrl.value.trim()) {
            showToast('Please enter a GitHub repository URL first.', true);
            githubRepoUrl.focus();
            return;
        }

        setButtonBusy(btnLoadGithubBranches, true, 'Loading...', 'Load branches');

        try {
            const params = githubFormParams();
            const data = await fetchJson('/api/github_repo_branches.php?' + params.toString());

            githubRefSelect.innerHTML = '';
            githubScenarioSelect.innerHTML = '<option value="">Choose scenario…</option>';

            if (!data.branches || !data.branches.length) {
                githubRefSelect.innerHTML = '<option value="">No branches found</option>';
                return;
            }

            data.branches.forEach(branch => {
                const option = document.createElement('option');
                option.value = branch;
                option.textContent = branch;
                if (branch === data.default_branch) {
                    option.selected = true;
                }
                githubRefSelect.appendChild(option);
            });

            showToast('Branches loaded.');
        } catch (err) {
            console.error(err);
            showToast(err.message || 'Failed to load branches.', true);
        } finally {
            setButtonBusy(btnLoadGithubBranches, false, 'Loading...', 'Load branches');
        }
    }

    async function loadGithubScenarios() {
        if (!githubRepoUrl.value.trim()) {
            showToast('Please enter a GitHub repository URL first.', true);
            githubRepoUrl.focus();
            return;
        }

        if (!githubRefSelect.value.trim()) {
            showToast('Please choose a branch first.', true);
            githubRefSelect.focus();
            return;
        }

        setButtonBusy(btnLoadGithubScenarios, true, 'Loading...', 'Load scenarios');

        try {
            const params = githubFormParams();
            const data = await fetchJson('/api/github_output_scenarios.php?' + params.toString());

            githubScenarioSelect.innerHTML = '<option value="">Choose scenario…</option>';

            if (!data.scenarios || !data.scenarios.length) {
                showToast('No scenario folders with output.zip were found.', true);
                return;
            }

            data.scenarios.forEach(scenario => {
                const option = document.createElement('option');
                option.value = scenario.path;
                option.textContent = scenario.name;
                githubScenarioSelect.appendChild(option);
            });

            showToast('Scenarios loaded.');
        } catch (err) {
            console.error(err);
            showToast(err.message || 'Failed to load scenarios.', true);
        } finally {
            setButtonBusy(btnLoadGithubScenarios, false, 'Loading...', 'Load scenarios');
        }
    }

    btnInspect.addEventListener('click', async () => {
        const source = currentSource();

        if (!inspectForm.reportValidity()) {
            return;
        }

        const myEpoch = ++inspectRequestEpoch;
        const fd = new FormData(inspectForm);

        setButtonBusy(btnInspect, true, 'Inspecting files...', source === 'github' ? 'Inspect GitHub files' : 'Inspect files');

        setStatusMessage(
            source === 'github'
                ? 'Downloading and inspecting GitHub output.zip. This can take a little while.'
                : 'Uploading and inspecting files. This can take a little while for large SWAT outputs.',
            'info'
        );

        try {
            const inspectUrl = source === 'github'
                ? '/api/import_github_inspect.php'
                : '/api/import_inspect.php';

            const result = await postFormWithProgress(inspectUrl, fd, message => {
                if (myEpoch === inspectRequestEpoch) {
                    setStatusMessage(
                        source === 'github'
                            ? 'Contacting GitHub and preparing output.zip...'
                            : message,
                        'info'
                    );
                }
            });

            if (myEpoch !== inspectRequestEpoch) {
                return;
            }

            const data = result.data;

            setStatusMessage('Server is inspecting files...', 'info');

            if (!result.ok || !data.ok) {
                const message = buildInspectionErrorMessage(data, result.status);
                setStatusMessage(message, result.status >= 500 ? 'danger' : 'warning');
                showToast(data.error || 'Inspection failed.', true);
                return;
            }

            inspectData = data;
            importTokenInput.value = data.import_token;
            detectedSubbasins = (data.detected_subbasins || [])
                .map(v => parseInt(v, 10))
                .filter(v => v > 0);

            inspectResult.classList.remove('d-none');
            renderCioSummary(data.cio || {});
            renderPeriodSummary(data.period_start_guess, data.period_end_guess);
            setPreview('preview-hru', data.inspections?.hru?.preview_html || '');
            setPreview('preview-rch', data.inspections?.rch?.preview_html || '<span class="text-muted">No RCH file uploaded.</span>');
            setPreview('preview-snu', data.inspections?.snu?.preview_html || '');

            renderUnknownCrops(data.unknown_crops || []);
            enableStep2And3();

            setStatusMessage('Files inspected successfully. You can now complete the metadata and select subbasins.', 'success');
            showToast('Files inspected successfully.');
        } catch (err) {
            if (myEpoch !== inspectRequestEpoch) {
                return;
            }

            console.error(err);
            const message = err?.message || 'Server error during inspection.';
            setStatusMessage(message, 'danger');
            showToast(message, true);
        } finally {
            if (myEpoch === inspectRequestEpoch) {
                setButtonBusy(btnInspect, false, 'Inspecting files...', source === 'github' ? 'Inspect GitHub files' : 'Inspect files');
                btnInspect.textContent = currentSource() === 'github' ? 'Inspect GitHub files' : 'Inspect files';
            }
        }
    });

    studyAreaSelect.addEventListener('change', async () => {
        const studyAreaId = parseInt(studyAreaSelect.value, 10);
        selectedSubbasins = new Set();

        if (!studyAreaId) {
            allSubbasins = [];
            selectedSubbasins = new Set();
            if (vectorSource) {
                vectorSource.clear();
            }
            renderSubbasinChecklist();
            refreshMapStyles();
            return;
        }

        try {
            await loadStudyAreaSubbasins(studyAreaId);
        } catch (err) {
            console.error(err);
            showToast(err.message || 'Failed to load map/subbasins.', true);
        }
    });

    isDownloadable.addEventListener('change', () => {
        downloadableFromDate.disabled = !isDownloadable.checked;
        if (!isDownloadable.checked) {
            downloadableFromDate.value = '';
        }
    });

    btnSelectDetected.addEventListener('click', () => {
        detectedSubbasins.forEach(sub => {
            if (allSubbasins.includes(sub)) selectedSubbasins.add(sub);
        });
        refreshMapStyles();
    });

    btnSelectDetectedOnly.addEventListener('click', () => {
        const detectedSet = new Set(detectedSubbasins);
        selectedSubbasins.clear();
        allSubbasins.forEach(sub => {
            if (detectedSet.has(sub)) {
                selectedSubbasins.add(sub);
            }
        });
        refreshMapStyles();
    });

    btnClearSubs.addEventListener('click', () => {
        selectedSubbasins.clear();
        refreshMapStyles();
    });

    btnFinalize.addEventListener('click', async () => {
        const fd = new FormData(finalizeForm);
        fd.set('selected_subbasins_json', JSON.stringify(Array.from(selectedSubbasins).sort((a, b) => a - b)));
        fd.set('unknown_crop_names_json', JSON.stringify(readUnknownCropNames()));
        fd.set('unknown_crop_dry_matter_json', JSON.stringify(readUnknownCropDryMatterFractions()));

        if (!finalizeForm.reportValidity()) {
            return;
        }
        if (!fd.get('import_token')) {
            showToast('Please inspect the files first.', true);
            return;
        }
        if (!fd.get('study_area')) {
            showToast('Please choose a study area.', true);
            return;
        }
        if (!fd.get('run_label')) {
            showToast('Please enter a run name.', true);
            return;
        }
        if (!fd.get('run_date')) {
            showToast('Please enter a model run date.', true);
            return;
        }
        if (!fd.get('model_run_author')) {
            showToast('Please enter a model run author.', true);
            return;
        }
        if (!fd.get('license_name')) {
            showToast('Please choose a license.', true);
            return;
        }
        if (!fd.get('visibility')) {
            showToast('Please choose a visibility.', true);
            return;
        }
        if (!fd.get('is_baseline')) {
            showToast('Please choose whether this is a baseline run.', true);
            return;
        }
        if (!fd.get('description')) {
            showToast('Please enter a description.', true);
            return;
        }
        if (selectedSubbasins.size === 0) {
            showToast('Please select at least one subbasin.', true);
            return;
        }

        for (const input of document.querySelectorAll('.unknown-crop-name')) {
            if (!input.value.trim()) {
                showToast(`Please enter a name for crop ${input.dataset.code}.`, true);
                input.focus();
                return;
            }
        }

        for (const input of document.querySelectorAll('.unknown-crop-dry-matter')) {
            const value = input.value.trim();
            if (value === '') continue;

            const num = Number(value);
            if (!Number.isFinite(num) || num <= 0 || num > 1) {
                showToast(`Dry matter fraction for crop ${input.dataset.code} must be between 0 and 1.`, true);
                input.focus();
                return;
            }
        }

        setButtonBusy(btnFinalize, true, 'Importing run...', 'Import run');
        setFinalizeStatusMessage('Importing run and writing normalized results to the database. Please wait.', 'info');

        try {
            const res = await fetch('/api/import_finalize.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            });
            const data = await res.json();

            if (!res.ok || !data.ok) {
                setFinalizeStatusMessage(data.error || 'Import failed.', 'danger');
                showToast(data.error || 'Import failed.', true);
                return;
            }

            setFinalizeStatusMessage(`Run imported successfully. Run ID: ${data.run_id}`, 'success');
            //showToast(`Run imported successfully. Run ID: ${data.run_id}`);
            //window.location.reload();
        } catch (err) {
            console.error(err);
            setFinalizeStatusMessage('Server error during import.', 'danger');
            showToast('Server error during import.', true);
        } finally {
            setButtonBusy(btnFinalize, false, 'Importing run...', 'Import run');
        }
    });

    inspectForm.querySelectorAll('input[name="import_source"]').forEach(el => {
        el.addEventListener('change', toggleSourceUi);
    });

    initMap();
    toggleSourceUi();
})();