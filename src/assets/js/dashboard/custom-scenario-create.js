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

    if (!mapEl || !runSelectEl || !assignmentListEl) return;

    const availableRuns = Array.isArray(cfg.availableRuns) ? cfg.availableRuns : [];
    const runMetaById = new Map(availableRuns.map(r => [Number(r.id), r]));

    const assignments = new Map(); // subbasinId -> runId
    const colorByRunId = new Map();
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

    availableRuns.forEach((run, idx) => {
        colorByRunId.set(Number(run.id), palette[idx % palette.length]);
    });

    backBtn?.addEventListener('click', () => {
        const params = new URLSearchParams({
            study_area_id: String(cfg.studyAreaId || 0),
            study_area_name: cfg.studyAreaName || '',
        });
        ModalUtils.reloadModal(`/modals/custom_scenarios.php?${params.toString()}`);
    });

    function renderAssignments() {
        if (!assignments.size) {
            assignmentListEl.innerHTML = `<div class="text-muted">No subbasins assigned yet.</div>`;
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
                vectorLayer.changed();
                renderAssignments();
            });
        });
    }

    function selectedRunId() {
        const v = Number(runSelectEl.value);
        return Number.isFinite(v) && v > 0 ? v : null;
    }

    function setHint(text) {
        if (hintEl) hintEl.textContent = text;
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
                const type = geom?.type;
                const c = geom?.coordinates;
                if (!type || !c) return null;
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

    let vectorLayer = null;
    let map = null;

    fetch(cfg.subbasinGeoUrl)
        .then(r => {
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            return r.json();
        })
        .then(gj => {
            const fmt = new ol.format.GeoJSON();
            const features = fmt.readFeatures(gj, {
                dataProjection: guessGeoJSONProjection(gj),
                featureProjection: 'EPSG:3857',
            });

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
        })
        .catch(err => {
            console.error('[custom-scenario-create] failed to load map', err);
            mapEl.innerHTML = `<div class="alert alert-danger m-2 mb-0">Failed to load study area map.</div>`;
        });

    renderAssignments();

    document.getElementById('saveCustomScenarioBtn')?.addEventListener('click', () => {
        const payload = {
            name: document.getElementById('customScenarioName')?.value?.trim() || '',
            description: document.getElementById('customScenarioDescription')?.value?.trim() || '',
            studyAreaId: cfg.studyAreaId,
            assignments: Object.fromEntries(assignments),
        };

        console.log('[custom-scenario-create] mock save payload', payload);
        showToast('Mock scenario captured. Save endpoint comes next.', false, null, 'OK', 3000);
    });
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

document.addEventListener('DOMContentLoaded', initCustomScenarioCreate);
initCustomScenarioCreate();