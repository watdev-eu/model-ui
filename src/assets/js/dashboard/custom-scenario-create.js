// assets/js/dashboard/custom-scenario-create.js
function initCustomScenarioCreate() {
    const cfg = window.__CUSTOM_SCENARIO_CREATE__;
    if (!cfg) {
        console.warn('[custom-scenario-create] missing config');
        return;
    }

    const mapEl = document.getElementById('customScenarioMap');
    const runSelectEl = document.getElementById('assignmentRunSelect');
    const assignmentListEl = document.getElementById('customScenarioAssignmentList');
    const hintEl = document.getElementById('customScenarioMapHint');
    const backBtn = document.getElementById('backToScenarioListBtn');
    const saveBtn = document.getElementById('saveCustomScenarioBtn');
    const progressEl = document.getElementById('customScenarioAssignmentProgress');
    const nameEl = document.getElementById('customScenarioName');
    const descriptionEl = document.getElementById('customScenarioDescription');

    let confirmModalEl = null;
    let confirmModal = null;

    function ensureConfirmModal() {
        if (confirmModalEl) return;

        confirmModalEl = document.createElement('div');
        confirmModalEl.className = 'modal fade';
        confirmModalEl.tabIndex = -1;
        confirmModalEl.setAttribute('aria-hidden', 'true');
        confirmModalEl.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Incomplete scenario assignment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2" id="customScenarioConfirmText">
                            Not all subbasins are assigned.
                        </p>
                        <div class="alert alert-warning mb-0 small">
                            All unassigned subbasins will use the <strong>Baseline</strong> scenario.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-action="cancel">
                            Cancel
                        </button>
                        <button type="button" class="btn btn-primary" data-action="confirm">
                            Continue and save
                        </button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(confirmModalEl);
        confirmModal = new bootstrap.Modal(confirmModalEl, {
            backdrop: 'static',
            keyboard: true,
        });
    }

    function showBootstrapConfirm({
                                      title = 'Please confirm',
                                      message = 'Are you sure?',
                                      confirmText = 'Confirm',
                                      cancelText = 'Cancel',
                                  }) {
        ensureConfirmModal();

        return new Promise((resolve) => {
            const titleEl = confirmModalEl.querySelector('.modal-title');
            const textEl = confirmModalEl.querySelector('#customScenarioConfirmText');
            const confirmBtn = confirmModalEl.querySelector('[data-action="confirm"]');
            const cancelBtn = confirmModalEl.querySelector('[data-action="cancel"]');

            titleEl.textContent = title;
            textEl.textContent = message;
            confirmBtn.textContent = confirmText;
            cancelBtn.textContent = cancelText;

            let settled = false;

            const cleanup = () => {
                confirmBtn.removeEventListener('click', onConfirm);
                cancelBtn.removeEventListener('click', onCancel);
                confirmModalEl.removeEventListener('hidden.bs.modal', onHidden);
            };

            const finish = (value) => {
                if (settled) return;
                settled = true;
                cleanup();
                resolve(value);
            };

            const onConfirm = () => {
                confirmModal.hide();
                finish(true);
            };

            const onCancel = () => {
                confirmModal.hide();
                finish(false);
            };

            const onHidden = () => {
                finish(false);
            };

            confirmBtn.addEventListener('click', onConfirm);
            cancelBtn.addEventListener('click', onCancel);
            confirmModalEl.addEventListener('hidden.bs.modal', onHidden, { once: true });

            confirmModal.show();
        });
    }

    if (!mapEl || !runSelectEl || !assignmentListEl || !saveBtn) return;

    const assignments = new Map(
        Object.entries(cfg.initialAssignments || {}).map(([sub, runId]) => [String(sub), Number(runId)])
    );

    const colorByRunId = new Map();
    const runMetaById = new Map();
    const palette = [
        [99, 110, 250, 0.65],
        [239, 85, 59, 0.65],
        [0, 204, 150, 0.65],
        [171, 99, 250, 0.65],
        [255, 161, 90, 0.65],
        [25, 211, 243, 0.65],
        [255, 102, 146, 0.65],
        [182, 232, 128, 0.65],
    ];

    let vectorLayer = null;
    let map = null;
    let totalSubbasins = 0;

    backBtn?.addEventListener('click', () => {
        ModalUtils.reloadModal(cfg.listModalUrl);
    });

    function setHint(text) {
        if (hintEl) hintEl.textContent = text;
    }

    function updateProgress() {
        if (!progressEl) return;
        const assigned = assignments.size;
        const unassigned = Math.max(0, totalSubbasins - assigned);

        if (totalSubbasins <= 0) {
            progressEl.textContent = 'Loading…';
            return;
        }

        if (unassigned > 0) {
            progressEl.innerHTML = `
                Assigned <span class="mono">${assigned}</span> / <span class="mono">${totalSubbasins}</span><br>
                <span class="mono">${unassigned}</span> subbasins unassigned  → Baseline will be used
            `;
        } else {
            progressEl.innerHTML = `
                Assigned <span class="mono">${assigned}</span> / <span class="mono">${totalSubbasins}</span>
            `;
        }
    }

    function selectedRunId() {
        const v = Number(runSelectEl.value);
        return Number.isFinite(v) && v > 0 ? v : null;
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, ch => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
        }[ch]));
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/"/g, '&quot;');
    }

    function renderAssignments() {
        if (!assignments.size) {
            assignmentListEl.innerHTML = `<div class="text-muted">No subbasins assigned yet.</div>`;
            updateProgress();
            return;
        }

        const rows = [...assignments.entries()]
            .sort((a, b) => Number(a[0]) - Number(b[0]))
            .map(([subId, runId]) => {
                const run = runMetaById.get(Number(runId));
                const label = run?.label || `Run ${runId}`;
                return `
                    <div class="d-flex justify-content-between align-items-start gap-2 border-bottom py-2">
                        <div>
                            <div class="fw-semibold">Subbasin ${escapeHtml(subId)}</div>
                            <div class="text-muted">${escapeHtml(label)}</div>
                        </div>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-danger"
                            data-remove-sub="${escapeAttr(subId)}"
                            title="Clear assignment">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                `;
            })
            .join('');

        assignmentListEl.innerHTML = rows;

        assignmentListEl.querySelectorAll('[data-remove-sub]').forEach(btn => {
            btn.addEventListener('click', () => {
                const subId = btn.getAttribute('data-remove-sub');
                assignments.delete(String(subId));
                vectorLayer?.changed();
                renderAssignments();
                updateProgress();
            });
        });

        updateProgress();
    }

    async function loadAvailableRuns() {
        const res = await fetch(cfg.runsListUrl, {
            headers: { 'Accept': 'application/json' }
        });

        if (!res.ok) {
            throw new Error(`Failed to load runs: HTTP ${res.status}`);
        }

        const json = await res.json();
        const runs = Array.isArray(json.runs) ? json.runs : [];

        runMetaById.clear();
        runSelectEl.innerHTML = '<option value="">Choose a scenario…</option>';

        const usableRuns = runs.filter(r => {
            const label = String(r.run_label || '').trim();
            return label.toLowerCase() !== 'baseline';
        });

        usableRuns.forEach((run, idx) => {
            const id = Number(run.id);
            const label = String(run.run_label || `Run ${id}`);

            runMetaById.set(id, {
                id,
                label,
                is_default: !!run.is_default,
            });

            colorByRunId.set(id, palette[idx % palette.length]);

            const opt = document.createElement('option');
            opt.value = String(id);
            opt.textContent = label;
            runSelectEl.appendChild(opt);
        });
    }

    if (typeof proj4 !== 'undefined' && ol?.proj?.proj4) {
        if (!proj4.defs['EPSG:32636']) {
            proj4.defs('EPSG:32636', '+proj=utm +zone=36 +datum=WGS84 +units=m +no_defs +type=crs');
        }
        ol.proj.proj4.register(proj4);
    }

    function guessGeoJSONProjection(gj) {
        try {
            const coordsScan = (geom) => {
                const c = geom?.coordinates;
                if (!c) return null;

                const scanArray = (arr) => {
                    for (const el of arr) {
                        if (Array.isArray(el)) {
                            const v = scanArray(el);
                            if (v != null) return v;
                        } else if (typeof el === 'number') {
                            return Math.abs(el);
                        }
                    }
                    return null;
                };

                return scanArray(c);
            };

            let sampleAbs = null;
            if (gj?.type === 'FeatureCollection' && Array.isArray(gj.features)) {
                for (const f of gj.features) {
                    sampleAbs = coordsScan(f.geometry);
                    if (sampleAbs != null) break;
                }
            }

            if (sampleAbs != null && sampleAbs > 200) return 'EPSG:3857';
        } catch (_) {}

        return 'EPSG:4326';
    }

    function getProp(f, key) {
        if (!f) return undefined;
        if (typeof f.get === 'function') return f.get(key);
        if (typeof f.getProperties === 'function') {
            const p = f.getProperties();
            return p ? p[key] : undefined;
        }
        return undefined;
    }

    function styleFn(feature) {
        const subId = String(getProp(feature, 'Subbasin'));
        const assignedRunId = assignments.get(subId);

        let fillColor = [230, 230, 230, 0.45];
        if (assignedRunId && colorByRunId.has(Number(assignedRunId))) {
            fillColor = colorByRunId.get(Number(assignedRunId));
        }

        return new ol.style.Style({
            stroke: new ol.style.Stroke({
                color: '#555',
                width: 1.2,
            }),
            fill: new ol.style.Fill({
                color: fillColor,
            }),
            text: new ol.style.Text({
                text: subId,
                font: '12px sans-serif',
                fill: new ol.style.Fill({ color: '#222' }),
                stroke: new ol.style.Stroke({ color: '#fff', width: 3 }),
            }),
        });
    }

    async function loadMap() {
        const res = await fetch(cfg.subbasinGeoUrl, {
            headers: { 'Accept': 'application/json' }
        });
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }

        const gj = await res.json();

        const fmt = new ol.format.GeoJSON();
        const features = fmt.readFeatures(gj, {
            dataProjection: guessGeoJSONProjection(gj),
            featureProjection: 'EPSG:3857',
        });

        totalSubbasins = features.length;
        updateProgress();

        const source = new ol.source.Vector({ features });
        vectorLayer = new ol.layer.Vector({
            source,
            style: styleFn,
        });

        map = new ol.Map({
            target: mapEl,
            layers: [
                new ol.layer.Tile({ source: new ol.source.OSM() }),
                vectorLayer,
            ],
            view: new ol.View({
                center: [0, 0],
                zoom: 5,
            }),
        });

        const extent = source.getExtent();
        if (extent && extent.every(Number.isFinite)) {
            map.getView().fit(extent, {
                padding: [20, 20, 20, 20],
                duration: 250,
                maxZoom: 10,
            });
        }

        map.on('singleclick', (evt) => {
            const feature = map.forEachFeatureAtPixel(evt.pixel, f => f);
            if (!feature) return;

            const subId = String(getProp(feature, 'Subbasin'));
            const runId = selectedRunId();

            if (!runId) {
                setHint(`Subbasin ${subId} clicked. Select a scenario first.`);
                return;
            }

            assignments.set(subId, runId);
            vectorLayer.changed();
            renderAssignments();

            const label = runMetaById.get(runId)?.label || `Run ${runId}`;
            setHint(`Assigned "${label}" to subbasin ${subId}.`);
        });

        setHint('Select a scenario and click a subbasin to assign it.');
    }

    async function saveScenario(confirmedUseBaselineForMissing = false) {
        const payload = {
            id: Number(cfg.scenarioId || 0),
            name: nameEl?.value?.trim() || '',
            description: descriptionEl?.value?.trim() || '',
            studyAreaId: Number(cfg.studyAreaId || 0),
            assignments: Object.fromEntries(assignments),
            confirmedUseBaselineForMissing,
        };

        const res = await fetch(cfg.saveUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        });

        const json = await res.json().catch(() => ({}));

        if (json?.status === 'confirm_required') {
            const assigned = assignments.size;
            const unassigned = Math.max(0, totalSubbasins - assigned);

            const ok = await showBootstrapConfirm({
                title: 'Incomplete scenario assignment',
                message:
                    json.message ||
                    `Only ${assigned} of ${totalSubbasins} subbasins are assigned. ` +
                    `${unassigned} unassigned subbasins will use the Baseline scenario. Do you want to continue?`,
                confirmText: 'Continue and save',
                cancelText: 'Go back',
            });

            if (!ok) return;

            await saveScenario(true);
            return;
        }

        if (!res.ok || json?.status !== 'ok') {
            throw new Error(json?.message || `Save failed (HTTP ${res.status})`);
        }

        const savedScenarioId = Number(json.id || cfg.scenarioId || 0);
        const action = Number(cfg.scenarioId || 0) > 0 ? 'updated' : 'created';

        showToast(json.message || 'Scenario saved successfully.', false, null, 'OK', 3000);

        document.dispatchEvent(new CustomEvent('watdev:custom-scenarios-changed', {
            detail: {
                studyAreaId: Number(cfg.studyAreaId || 0),
                action,
                scenarioId: savedScenarioId,
                datasetId: savedScenarioId ? `custom:${savedScenarioId}` : null,
            }
        }));

        ModalUtils.reloadModal(cfg.listModalUrl);
    }

    saveBtn.addEventListener('click', async () => {
        try {
            saveBtn.disabled = true;
            await saveScenario(false);
        } catch (err) {
            console.error('[custom-scenario-create] save failed', err);
            showToast(err?.message || 'Failed to save scenario.', true, null, 'OK', 5000);
        } finally {
            saveBtn.disabled = false;
        }
    });

    Promise.all([
        loadAvailableRuns(),
        loadMap(),
    ])
        .then(() => {
            renderAssignments();
        })
        .catch(err => {
            console.error('[custom-scenario-create] init failed', err);
            mapEl.innerHTML = `<div class="alert alert-danger m-2 mb-0">Failed to load custom scenario editor.</div>`;
            setHint('Failed to initialize editor.');
        });
}

initCustomScenarioCreate();