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

        btnInspect.disabled = source === 'github';

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
                <label class="form-label">Crop name for <code>${escapeHtml(code)}</code></label>
                <input type="text" class="form-control unknown-crop-name" data-code="${escapeHtml(code)}" required>
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

    btnInspect.addEventListener('click', async () => {
        if (currentSource() === 'github') {
            showToast('GitHub import is not implemented yet.', true);
            return;
        }

        if (!inspectForm.reportValidity()) {
            return;
        }

        const myEpoch = ++inspectRequestEpoch;
        const fd = new FormData(inspectForm);

        setButtonBusy(btnInspect, true, 'Inspecting files...', 'Inspect files');
        setStatusMessage('Uploading and inspecting files. This can take a little while for large SWAT outputs.', 'info');

        try {
            const res = await fetch('/api/import_inspect.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            });

            const data = await res.json();

            if (myEpoch !== inspectRequestEpoch) {
                return;
            }

            if (!res.ok || !data.ok) {
                setStatusMessage(data.error || 'Inspection failed.', 'danger');
                showToast(data.error || 'Inspection failed.', true);
                return;
            }

            inspectData = data;
            importTokenInput.value = data.import_token;
            detectedSubbasins = (data.detected_subbasins || []).map(v => parseInt(v, 10)).filter(v => v > 0);

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

            // Keep model run date on today; do not overwrite it with detected period start.
        } catch (err) {
            if (myEpoch !== inspectRequestEpoch) {
                return;
            }
            console.error(err);
            setStatusMessage('Server error during inspection.', 'danger');
            showToast('Server error during inspection.', true);
        } finally {
            if (myEpoch === inspectRequestEpoch) {
                setButtonBusy(btnInspect, false, 'Inspecting files...', 'Inspect files');
                if (currentSource() === 'github') {
                    btnInspect.disabled = true;
                }
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

        setButtonBusy(btnFinalize, true, 'Importing run...', 'Import run');
        setStatusMessage('Importing run and writing normalized results to the database. Please wait.', 'info');

        try {
            const res = await fetch('/api/import_finalize.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            });
            const data = await res.json();

            if (!res.ok || !data.ok) {
                setStatusMessage(data.error || 'Import failed.', 'danger');
                showToast(data.error || 'Import failed.', true);
                return;
            }

            setStatusMessage(`Run imported successfully. Run ID: ${data.run_id}`, 'success');
            showToast(`Run imported successfully. Run ID: ${data.run_id}`);
            window.location.reload();
        } catch (err) {
            console.error(err);
            setStatusMessage('Server error during import.', 'danger');
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