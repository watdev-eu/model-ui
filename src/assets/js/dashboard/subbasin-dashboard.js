// assets/js/dashboard/subbasin-dashboard.js

import { initMcaController } from './mca-controller.js';
export function initSubbasinDashboard({
                                          els,
                                          studyAreaId,
                                          apiBase = '/api',
                                      }) {
    // ---------- State ----------
    let loadEpoch = 0;
    let isLoading = false;
    let hasRunsForStudyArea = null;

    let busyToken = 0;
    let busyTimer = null;
    let busyShown = false;

    // Track abort controllers for in-flight PRELOAD requests only
    const preloadAbortByRunId = new Map(); // runId -> AbortController

    let mcaEnabled = false;

    // Keep latest run->crops map so MCA can initialize after user enables it
    let lastRunCropsById = {};
    let lastSelectedRunIds = [];

    let currentStudyAreaId = studyAreaId;
    let subbasinGeoUrl = `/api/study_area_subbasins_geo.php?study_area_id=${encodeURIComponent(currentStudyAreaId)}`;
    let riversGeoUrl   = `/api/study_area_reaches_geo.php?study_area_id=${encodeURIComponent(currentStudyAreaId)}`;

    let indicatorDefs = [];     // SWAT indicators
    let mcaIndicatorDefs = [];  // computed MCA viewer indicators
    let subCropIndicatorIds = new Set(); // filled after registry load

    let map, subLayer, subSource, subFeatures = [];
    let vectorLayer;
    let rivLayer, rivSource;
    let years = [];     // list of YEARs in data
    let crops = [];     // list of LULC codes
    let current = {
        runId: null,
        datasetUrl: null,
        indicatorId: null,
        crop: null,
        aggMode: 'crop',
        yearIndex: 0,
        selectedSub: null
    };
    // All data per run_id
    // runsStore[runId] = {
    //   meta: { id, label },
    //   years, crops,
    // }
    const runsStore = new Map();
    let selectedRunIds = [];   // dataset keys, e.g. "12" or "custom:5"
    let mapScenarioIds = [];   // dataset keys, 0–2
    let runsMeta = [];         // metadata from runs_list.php (id, run_label, run_date)

    let cropLookup = {};

    const COLORWAY = [
        '#636EFA', '#EF553B', '#00CC96', '#AB63FA',
        '#FFA15A', '#19D3F3', '#FF6692', '#B6E880'
    ];

    // ---------- Lazy registries (load once) ----------
    let indicatorRegistryLoaded = false;
    let cropLookupLoaded = false;

    // ---- Background preloading of default runs ----
    const runLoadPromises = new Map(); // runId -> Promise
    let preloadEpoch = 0;
    let preloadTotal = 0;
    let preloadDone = 0;
    let preloadInFlight = 0;
    let preloadQueue = [];
    let preloadRunning = false;

    let workspaceLoadConfirmBs = null;
    let pendingWorkspaceSelection = null;

    function initWorkspaceLoadConfirm() {
        if (!els.mcaWorkspaceLoadConfirmModal) return;

        workspaceLoadConfirmBs = new bootstrap.Modal(els.mcaWorkspaceLoadConfirmModal);

        els.mcaWorkspaceLoadConfirmBtn?.addEventListener('click', async () => {
            if (!pendingWorkspaceSelection || !mca) return;

            const { workspaceMode, workspaceId, label } = pendingWorkspaceSelection;

            try {
                workspaceLoadConfirmBs?.hide();

                if (!mcaEnabled) {
                    if (els.mcaEnableSwitch) {
                        els.mcaEnableSwitch.checked = true;
                    }
                    await setMcaEnabled(true);
                }

                if (els.mcaWorkspaceStatus) {
                    els.mcaWorkspaceStatus.textContent =
                        workspaceMode === 'system'
                            ? 'Loading default configuration…'
                            : `Loading workspace "${label}"…`;
                }

                await mca.loadActivePreset(currentStudyAreaId, workspaceId, { workspaceMode });

                const workspaceDatasetIds = mca.getPreferredWorkspaceDatasetIds();
                applyDatasetSelectionToUi(workspaceDatasetIds);
                await handleDatasetSelectionChanged();

                if (els.mcaWorkspaceStatus) {
                    els.mcaWorkspaceStatus.textContent =
                        workspaceMode === 'system'
                            ? 'Default configuration loaded.'
                            : `Workspace "${label}" loaded.`;
                }
            } catch (err) {
                console.error('[MCA workspace load] failed', err);
                if (els.mcaWorkspaceStatus) {
                    els.mcaWorkspaceStatus.textContent = err.message || String(err);
                }
            } finally {
                pendingWorkspaceSelection = null;
            }
        });
    }

    function setPreloadStatus(done, total) {
        if (!els.preloadStatus) return;
        if (!total || done >= total) {
            els.preloadStatus.classList.add('d-none');
            els.preloadStatus.textContent = '';
            return;
        }
        els.preloadStatus.classList.remove('d-none');
        els.preloadStatus.textContent = `Downloading ${done}/${total} default datasets…`;
    }

    async function ensureIndicatorRegistryLoaded() {
        if (indicatorRegistryLoaded) return;
        await loadIndicatorRegistry();
        indicatorRegistryLoaded = true;
    }

    async function ensureCropLookupLoaded() {
        if (cropLookupLoaded) return;
        await loadCropLookup();
        cropLookupLoaded = true;
    }

    async function loadCropLookup() {
        try {
            const res = await fetch(`${apiBase}/crops_list.php`);
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const json = await res.json();
            cropLookup = {};
            for (const row of json.crops || []) {
                if (row.code) cropLookup[row.code] = row.name || row.code;
            }
            console.debug('[CROPS] Loaded', Object.keys(cropLookup).length, 'crop names');
        } catch (err) {
            console.warn('[CROPS] Failed to load crop names:', err);
            cropLookup = {};
        }
    }

    // ---------- Data load ----------
    async function loadIndicatorRegistry() {
        const res = await fetch(`${apiBase}/swat_indicators_list.php`);
        if (!res.ok) throw new Error(`swat_indicators_list HTTP ${res.status}`);
        const json = await res.json();

        indicatorDefs = (json.indicators || []).map(d => ({
            ...d,
            id: d.code ?? d.id,
            isMca: false,
        }));

        subCropIndicatorIds = new Set(
            indicatorDefs
                .filter(d => d.grain === 'sub_crop')
                .map(d => d.id)
        );

        console.debug('[INDICATORS] Loaded', indicatorDefs.length, 'SWAT definitions');
    }

    // ---------- Boot ----------
    let mca = null;
    wireUI();
    initWorkspaceLoadConfirm();
    mca = initMcaController({
        apiBase,
        els: {
            mcaWorkspaceSelect: els.mcaWorkspaceSelect,
            mcaWorkspaceSaveBtn: els.mcaWorkspaceSaveBtn,
            mcaWorkspaceSaveAsBtn: els.mcaWorkspaceSaveAsBtn,
            mcaWorkspaceStatus: els.mcaWorkspaceStatus,

            mcaWorkspaceModal: els.mcaWorkspaceModal,
            mcaWorkspaceForm: els.mcaWorkspaceForm,
            mcaWorkspaceNameInput: els.mcaWorkspaceNameInput,
            mcaWorkspaceModalTitle: els.mcaWorkspaceModalTitle,
            mcaWorkspaceModalHelp: els.mcaWorkspaceModalHelp,
            mcaWorkspaceModalSubmit: els.mcaWorkspaceModalSubmit,
            mcaWorkspaceDefaultInput: els.mcaWorkspaceDefaultInput,

            mcaComputeBtn: els.mcaComputeBtn,
            mcaIndicatorsTableWrap: els.mcaIndicatorsTableWrap,
            mcaWeightSum: els.mcaWeightSum,
            mcaEditError: els.mcaEditError,
            mcaVarsForm: els.mcaVarsForm,
            mcaCropGlobalsWrap: els.mcaCropGlobalsWrap,
            mcaScenarioPickerWrap: els.mcaScenarioPickerWrap,
            mcaScenarioCards: els.mcaScenarioCards,

            mcaVizWrap: els.mcaVizWrap,
            mcaRadarChart: els.mcaRadarChart,
            mcaTotalsChart: els.mcaTotalsChart,
            mcaIndicatorSelect: els.mcaIndicatorSelect,
            mcaRawTsChart: els.mcaRawTsChart,
        }
    });

    document.addEventListener('watdev:mca-results-cleared', () => {
        const oldMcaDefs = mcaIndicatorDefs.slice();
        mcaIndicatorDefs = [];

        for (const [, rd] of runsStore.entries()) {
            for (const d of oldMcaDefs) {
                delete rd.index[d.id];
                rd.indicatorCache.delete(`${d.id}|yearly`);
            }
        }

        populateMetricsCombined();
        updateCropVisibility();
        recomputeAndRedraw();
    });

    document.addEventListener('watdev:mca-computed', async () => {
        mcaIndicatorDefs = mca?.getViewerIndicatorDefs?.() || [];

        for (const [, rd] of runsStore.entries()) {
            for (const d of mcaIndicatorDefs) {
                delete rd.index[d.id];
                rd.indicatorCache.delete(`${d.id}|yearly`);
            }
        }

        populateMetricsCombined();

        if (current.indicatorId && mcaIndicatorDefs.some(d => d.id === current.indicatorId)) {
            await Promise.all(selectedRunIds.map(id => ensureIndicatorLoaded(id, current.indicatorId)));
        }

        recomputeAndRedraw();
    });

    setIdleState();
    if (currentStudyAreaId > 0) {
        bootstrapFromApi().catch(err => console.error('[bootstrap] failed', err));
    }

    // ---------- UI ----------
    function wireUI() {
        els.dataset.addEventListener('change', async () => {
            await handleDatasetSelectionChanged();
        });

        els.metric.addEventListener('change', async () => {
            current.indicatorId = els.metric.value || null;
            updateCropVisibility();

            if (!current.indicatorId || !selectedRunIds.length) {
                recomputeAndRedraw();
                return;
            }

            setBusy(true, 'Loading metric…');
            try {
                await Promise.all(selectedRunIds.map(id => ensureIndicatorLoaded(id, current.indicatorId)));
                recomputeAndRedraw();
            } catch (e) {
                console.error('[metric change] failed', e);
                showToast('Failed to load metric data.', true, null, 'OK', 5000);
            } finally {
                setBusy(false);
            }
        });

        els.crop.addEventListener('change', () => {
            current.crop = els.crop.value || null;
            recomputeAndRedraw();
        });

        let rafId = null;
        els.yearSlider.addEventListener('input', () => {
            current.yearIndex = +els.yearSlider.value;
            els.yearLabel.textContent = scenarioYearLabel(current.yearIndex);
            if (rafId) cancelAnimationFrame(rafId);
            rafId = requestAnimationFrame(() => { recomputeAndRedraw(true); rafId = null; });
        });

        els.aggMode.addEventListener('change', () => {
            current.aggMode = els.aggMode.value; // 'crop' or 'sub'
            updateCropVisibility();
            recomputeAndRedraw();
        });

        // Layer toggles
        els.toggleRivers.addEventListener('change', () => {
            if (rivLayer) rivLayer.setVisible(els.toggleRivers.checked);
        });
        els.toggleSubbasins.addEventListener('change', () => {
            const vis = els.toggleSubbasins.checked;
            if (subLayer) subLayer.setVisible(vis);
            if (vectorLayer) vectorLayer.setVisible(vis);
        });

        if (els.mcaWorkspaceLoadBtn && els.mcaWorkspaceSelect) {
            els.mcaWorkspaceLoadBtn.addEventListener('click', () => {
                if (!mca) return;

                const rawValue = String(els.mcaWorkspaceSelect.value || '');
                const workspaceMode = rawValue === 'system' ? 'system' : 'workspace';
                const workspaceId = /^\d+$/.test(rawValue) ? Number(rawValue) : null;

                const selectedOption = els.mcaWorkspaceSelect.options[els.mcaWorkspaceSelect.selectedIndex];
                const label = selectedOption?.textContent?.trim() || 'selected workspace';

                pendingWorkspaceSelection = {
                    workspaceMode,
                    workspaceId,
                    label,
                };

                if (els.mcaWorkspaceLoadConfirmText) {
                    els.mcaWorkspaceLoadConfirmText.textContent =
                        workspaceMode === 'system'
                            ? 'Loading the default configuration will enable MCA and replace the current unsaved values, selected scenarios, and MCA inputs.'
                            : `Loading "${label}" will enable MCA and replace the current unsaved values, selected scenarios, and MCA inputs.`;
                }

                workspaceLoadConfirmBs?.show();
            });
        }

        // Opacity sliders
        const applySubOpacity = () => {
            const a = Math.max(0, Math.min(1, (+els.opacitySubbasins.value || 0) / 100));
            if (subLayer) subLayer.setOpacity(a);
            if (vectorLayer) vectorLayer.setOpacity(a);
            if (els.opacitySubbasinsVal) els.opacitySubbasinsVal.textContent = `${Math.round(a*100)}%`;
        };
        const applyRivOpacity = () => {
            const a = Math.max(0, Math.min(1, (+els.opacityRivers.value || 0) / 100));
            if (rivLayer) rivLayer.setOpacity(a);
            if (els.opacityRiversVal) els.opacityRiversVal.textContent = `${Math.round(a*100)}%`;
        };
        els.opacitySubbasins.addEventListener('input', applySubOpacity);
        els.opacityRivers.addEventListener('input', applyRivOpacity);

        if (els.mapScenario) {
            els.mapScenario.addEventListener('change', async () => {
                const opts = [...els.mapScenario.options];

                let ids = opts
                    .filter(o => o.selected)
                    .map(o => String(o.value))
                    .filter(v => v !== '');

                // Enforce max 2
                if (ids.length > 2) {
                    ids = ids.slice(0, 2);
                    opts.forEach(o => {
                        const id = String(o.value);
                        o.selected = ids.includes(id);
                    });
                }

                // If user deselects all, fall back to first selected run
                if (!ids.length && selectedRunIds.length) {
                    ids = [selectedRunIds[0]];
                    opts.forEach(o => {
                        const id = String(o.value);
                        o.selected = ids.includes(id);
                    });
                }

                mapScenarioIds = ids;

                if (mapScenarioIds.length) {
                    const baseRunId = mapScenarioIds[0];
                    current.runId = baseRunId;
                    activateRun(baseRunId, { preserveCrop: true });

                    if (current.indicatorId && selectedRunIds.length) {
                        setBusy(true, 'Loading metric…');
                        try {
                            await Promise.all(
                                selectedRunIds.map(id => ensureIndicatorLoaded(id, current.indicatorId))
                            );
                        } finally {
                            setBusy(false);
                        }
                    }
                }

                recomputeAndRedraw();
            });
        }

        if (els.mcaEnableSwitch) {
            // initial state from localStorage (default OFF)
            let init = false;
            try { init = localStorage.getItem('mca_enabled') === '1'; } catch (_) {}
            els.mcaEnableSwitch.checked = init;

            // apply initial
            setMcaEnabled(init);

            els.mcaEnableSwitch.addEventListener('change', () => {
                setMcaEnabled(!!els.mcaEnableSwitch.checked);
            });
        }
    }

    async function setMcaEnabled(on) {
        mcaEnabled = !!on;

        // synchronous UI first
        if (els.mcaEnabledWrap) els.mcaEnabledWrap.style.display = mcaEnabled ? 'block' : 'none';
        if (els.mcaDisabledNote) els.mcaDisabledNote.style.display = mcaEnabled ? 'none' : 'block';

        try { localStorage.setItem('mca_enabled', mcaEnabled ? '1' : '0'); } catch (_) {}

        if (!mcaEnabled) {
            if (mca && els.mcaVizWrap) els.mcaVizWrap.style.display = 'none';
            return;
        }

        // Turning ON: load preset for this study area
        if (!mca) return;
        if (!Number.isFinite(+currentStudyAreaId) || +currentStudyAreaId <= 0) return;

        // Friendly busy text (lightweight)
        if (els.mapNote) els.mapNote.textContent = 'Loading MCA preset…';

        try {
            if (els.mapNote) els.mapNote.textContent = 'Loading MCA preset…';
            await rehydrateMcaFromCurrentRuns();
        } catch (e) {
            console.error('[MCA] enable failed:', e);
        } finally {
            // restore mapNote if needed
        }
    }

    async function rehydrateMcaFromCurrentRuns({
                                                   workspaceId = null,
                                                   workspaceMode = 'auto',
                                                   reloadPreset = true
                                               } = {}) {
        if (!mca) return;
        if (!Number.isFinite(+currentStudyAreaId) || +currentStudyAreaId <= 0) return;

        if (reloadPreset) {
            await mca.loadActivePreset(currentStudyAreaId, workspaceId, { workspaceMode });
        }

        if (!lastSelectedRunIds.length) {
            await mca.setAvailableRuns({
                studyAreaId: currentStudyAreaId,
                selectedRunIds: [],
                runsMeta,
                runCropsById: {},
            });
            mca.setAllowedCrops([]);
            return;
        }

        const cropSet = new Set();
        const runCropsById = {};

        for (const id of lastSelectedRunIds) {
            const rd = runsStore.get(id);
            if (!rd) continue;

            const runCrops = (rd.crops || []).slice();
            runCropsById[id] = runCrops;

            for (const c of runCrops) cropSet.add(c);
        }

        const baselineRunId = mca.getBaselineRunId();

        if (baselineRunId && Number.isFinite(baselineRunId) && baselineRunId > 0) {
            const baselineKey = String(baselineRunId);

            if (!runsStore.has(baselineKey)) {
                console.log(`[MCA] Loading baseline run ${baselineRunId} for crop data`);
                await ensureRunLoaded(baselineKey);
            }

            const baselineData = runsStore.get(baselineKey);
            if (baselineData?.crops && !runCropsById[baselineKey]) {
                runCropsById[baselineKey] = baselineData.crops.slice();
            }
        }

        lastRunCropsById = runCropsById;

        await mca.setAvailableRuns({
            studyAreaId: currentStudyAreaId,
            selectedRunIds: lastSelectedRunIds,
            runsMeta,
            runCropsById,
        });

        mca.setAllowedCrops([...cropSet]);
    }

    // ---------- Data load ----------
    async function loadEffectiveRunBySub(runId, { signal } = {}) {
        if (!isCustomDatasetId(runId)) {
            return new Map(); // normal runs don't need explicit per-sub mapping
        }

        const scenarioId = parseDatasetSourceId(runId);
        const res = await fetch(
            `${apiBase}/custom_scenario_effective_runs.php?scenario_id=${encodeURIComponent(scenarioId)}`,
            { signal }
        );

        if (!res.ok) {
            throw new Error(`custom_scenario_effective_runs HTTP ${res.status}`);
        }

        const json = await res.json();
        const raw = json?.effective_run_by_sub || {};
        return new Map(
            Object.entries(raw).map(([sub, sourceRunId]) => [String(sub), Number(sourceRunId)])
        );
    }

    async function loadRunMeta(runId, { signal } = {}) {
        const [yearsRes, cropsRes, effectiveRunBySub] = await Promise.all([
            fetch(`${apiBase}/run_years.php?run_id=${encodeURIComponent(runId)}`, { signal }),
            fetch(`${apiBase}/run_crops.php?run_id=${encodeURIComponent(runId)}`, { signal }),
            loadEffectiveRunBySub(runId, { signal }),
        ]);

        if (!yearsRes.ok) throw new Error(`run_years HTTP ${yearsRes.status}`);
        if (!cropsRes.ok) throw new Error(`run_crops HTTP ${cropsRes.status}`);

        const yearsJson = await yearsRes.json();
        const cropsJson = await cropsRes.json();

        const years = (yearsJson.years || []).map(Number).filter(n => Number.isFinite(n));
        const crops = (cropsJson.crops || []).map(String);

        return {
            id: runId,
            years: years.sort((a,b)=>a-b),
            crops: crops.sort(),
            indicatorCache: new Map(),
            index: {},
            isCustom: isCustomDatasetId(runId),
            effectiveRunBySub,
        };
    }

    function buildIndexForIndicator(rows, grain) {
        if (grain === 'sub_crop') {
            const m = new Map();
            const sum = new Map();
            const cnt = new Map();

            for (const r of rows || []) {
                const Y = +r.year, S = +r.sub;
                if (!Number.isFinite(Y) || !Number.isFinite(S)) continue;

                const crop = r.crop;
                if (crop && crop !== '-' && crop !== 'NULL') {
                    const v = num(r.value);
                    if (!Number.isFinite(v)) continue;

                    m.set(`${Y}|${S}|${crop}`, v);

                    const k2 = `${Y}|${S}`;
                    sum.set(k2, (sum.get(k2) || 0) + v);
                    cnt.set(k2, (cnt.get(k2) || 0) + 1);
                }
            }

            const mean = new Map();
            for (const [k2, s] of sum.entries()) {
                mean.set(k2, s / (cnt.get(k2) || 1));
            }

            return { grain: 'sub_crop', m, mean };
        }

        // grain=sub
        const m = new Map();
        for (const r of rows || []) {
            const Y = +r.year, S = +r.sub;
            if (!Number.isFinite(Y) || !Number.isFinite(S)) continue;

            const v = num(r.value);
            if (!Number.isFinite(v)) continue;

            m.set(`${Y}|${S}`, v);
        }
        return { grain: 'sub', m };
    }

    async function ensureIndicatorLoaded(runId, indicatorId, { signal } = {}) {
        const rd = runsStore.get(runId);
        if (!rd) return;

        const def = allIndicatorDefs().find(d => d.id === indicatorId);
        if (!def) return;

        const key = `${indicatorId}|yearly`;
        if (rd.indicatorCache.has(key)) return;

        let payload = null;

        if (def.source === 'mca') {
            const rows = mca?.getViewerRows(runId, indicatorId) || [];
            payload = {
                status: 'ok',
                meta: {
                    code: def.id,
                    sector: def.sector,
                    name: def.name,
                    unit: def.unit,
                    description: def.description,
                    source: def.source,
                    grain: def.grain,
                },
                rows,
            };
        } else {
            const res = await fetch(
                `${apiBase}/swat_indicator_yearly.php?run_id=${encodeURIComponent(runId)}&indicator=${encodeURIComponent(indicatorId)}`,
                { signal }
            );
            if (!res.ok) throw new Error(`swat_indicator_yearly HTTP ${res.status}`);
            payload = await res.json();
        }

        rd.indicatorCache.set(key, payload);

        if (payload?.status === 'ok') {
            const grain = def?.grain || payload?.meta?.grain || 'sub';
            rd.index[indicatorId] = buildIndexForIndicator(payload.rows || [], grain);
        } else {
            rd.index[indicatorId] = null;
        }
    }

    function rowsForIndicator(rd, indicatorId) {
        const key = `${indicatorId}|yearly`;
        const payload = rd?.indicatorCache?.get(key);
        if (!payload || payload.status !== 'ok') return [];
        return payload.rows || [];
    }

    async function bootstrapFromApi() {
        showInlineSpinner(els.dataset, 'Loading scenarios…');
        setBusy(true, 'Loading study area…');
        updateHelpText();

        try {
            const [subGJ, rivGJ] = await Promise.all([
                fetch(subbasinGeoUrl).then(r => r.json()),
                fetch(riversGeoUrl).then(r => r.json())
            ]);
            buildMap(subGJ, rivGJ);

            const runIds = await loadRuns();
            hasRunsForStudyArea = runIds.length > 0;

            // Kick off background preloading of DEFAULT runs
            const defaultRunIds = (runsMeta || [])
                .filter(r => !!r.is_default && !isCustomDatasetId(r.id))
                .map(r => String(r.id))
                .filter(id => id !== '');

            startPreloadDefaultRuns(defaultRunIds, { concurrency: 2 });

            // If no runs: keep friendly UI; hint will show "No scenarios..."
            if (!hasRunsForStudyArea) {
                selectedRunIds = [];
                mapScenarioIds = [];
                current.runId = null;
                current.selectedSub = null;
                updateMapScenarioOptions();

                if (els.mapNote) els.mapNote.textContent = 'No model runs found for this study area.';
                if (els.seriesChart) Plotly.purge(els.seriesChart);
                if (els.cropChart)   Plotly.purge(els.cropChart);

                return;
            }

            // Runs exist, but start with nothing selected
            selectedRunIds = [];
            mapScenarioIds = [];
            current.runId = null;
            current.selectedSub = null;
            updateMapScenarioOptions();

            if (els.mapNote) els.mapNote.textContent = 'Select one or more scenarios to load metrics.';
            if (els.metric)  els.metric.innerHTML = `<option value="">Select scenarios first…</option>`;
        } finally {
            setBusy(false);
            updateHelpText();
        }
    }

    function scenarioYearLabel(index) {
        const i = Number(index);
        return Number.isFinite(i) ? `Year ${i}` : 'Year —';
    }

    function timeLabelText() {
        return scenarioYearLabel(current.yearIndex);
    }

    async function loadRuns() {
        const url = `${apiBase}/runs_list.php?study_area_id=${encodeURIComponent(currentStudyAreaId)}`;
        const res = await fetch(url);
        if (!res.ok) throw new Error(`runs_list HTTP ${res.status}`);
        const json = await res.json();
        const runs = json.runs || [];

        runsMeta = runs;  // store for later lookup (labels, etc.)

        runsMeta = runs.map(r => ({
            ...r,
            id: String(r.id),
        }));

        if (!runs.length) {
            els.dataset.innerHTML = '<div class="text-muted small">No runs found for this study area.</div>';
            return [];
        }

        const defaultRuns = runs.filter(r => !!r.is_default && r.dataset_type === 'run');
        const userModelRuns = runs.filter(r => !r.is_default && r.dataset_type === 'run');
        const customScenarioRuns = runs.filter(r => r.dataset_type === 'custom');

        let html = '';

        if (defaultRuns.length) {
            html += '<div class="mb-2">';
            html += '<div class="small fw-semibold text-muted mb-1">Default datasets</div>';
            html += defaultRuns.map(r => {
                const label = escHtml(r.run_label);
                const domId = datasetDomId(r.id);
                return `
                <div class="form-check mb-1">
                    <input class="form-check-input dataset-checkbox" type="checkbox"
                           id="${domId}" data-run-id="${escAttr(r.id)}">
                    <label class="form-check-label" for="${domId}">${label}</label>
                </div>`;
            }).join('');
            html += '</div>';
        }

        if (userModelRuns.length) {
            html += '<div class="mb-2">';
            html += '<div class="small fw-semibold text-muted mb-1">User-uploaded model runs</div>';
            html += userModelRuns.map(r => {
                const labelParts = [r.run_label];
                if (r.run_date) labelParts.push(`(${r.run_date})`);
                const label = escHtml(labelParts.join(' '));
                const domId = datasetDomId(r.id);
                return `
                <div class="form-check mb-1">
                    <input class="form-check-input dataset-checkbox" type="checkbox"
                           id="${domId}" data-run-id="${escAttr(r.id)}">
                    <label class="form-check-label" for="${domId}">${label}</label>
                </div>`;
            }).join('');
            html += '</div>';
        }

        if (customScenarioRuns.length) {
            html += '<div class="mb-2">';
            html += '<div class="small fw-semibold text-muted mb-1">Custom scenarios</div>';
            html += customScenarioRuns.map(r => {
                const label = escHtml(r.run_label);
                const domId = datasetDomId(r.id);
                return `
                <div class="form-check mb-1">
                    <input class="form-check-input dataset-checkbox" type="checkbox"
                           id="${domId}" data-run-id="${escAttr(r.id)}">
                    <label class="form-check-label d-flex align-items-center gap-2" for="${domId}">
                        <span>${label}</span>
                    </label>
                </div>`;
            }).join('');
            html += '</div>';
        }

        els.dataset.innerHTML = html;

        // return list of ids so caller can decide what to auto-select
        return runs.map(r => r.id);
    }

    // ---------- Map ----------
    function registerEPSG32636() {
        try {
            if (typeof proj4 !== 'undefined' && ol?.proj?.proj4) {
                if (!proj4.defs['EPSG:32636']) {
                    // WGS84 / UTM zone 36N
                    proj4.defs('EPSG:32636', '+proj=utm +zone=36 +datum=WGS84 +units=m +no_defs +type=crs');
                }
                ol.proj.proj4.register(proj4);
                // Touch the projection so OL caches it
                ol.proj.get('EPSG:32636');
                console.debug('[CRS] EPSG:32636 registered via proj4');
            } else {
                console.warn('[CRS] proj4 not available; EPSG:32636 cannot be registered.');
            }
        } catch (e) {
            console.warn('[CRS] Registration of EPSG:32636 failed:', e);
        }
    }

    function buildMap(subGJ, rivGJ) {
        registerEPSG32636(); // <-- make sure 32636 is known

        const geoFmt = new ol.format.GeoJSON();

        const subDataProj = guessGeoJSONProjection(subGJ);
        const rivDataProj = guessGeoJSONProjection(rivGJ);

        subFeatures = geoFmt.readFeatures(subGJ, {
            dataProjection: subDataProj,
            featureProjection: 'EPSG:3857',
        }) || [];

        let rivFeatures = geoFmt.readFeatures(rivGJ, {
            dataProjection: rivDataProj,
            featureProjection: 'EPSG:3857',
        }) || [];

        if (!map) {
            // ---------- First time: create sources + layers ----------
            subSource = new ol.source.Vector({ features: subFeatures });
            rivSource = new ol.source.Vector({ features: rivFeatures });

            subLayer = new ol.layer.Vector({
                source: subSource,
                zIndex: 10,
                style: new ol.style.Style({
                    stroke: new ol.style.Stroke({ color: '#444', width: 1.25 }),
                    fill: new ol.style.Fill({ color: [230,230,230,0.6] })
                })
            });

            vectorLayer = new ol.layer.Vector({
                source: subSource,
                zIndex: 20,
                style: subStyleFn
            });

            rivLayer = new ol.layer.Vector({
                source: rivSource,
                style: riverStyle
            });
            rivLayer.setZIndex(1000); // on top

            map = new ol.Map({
                target: 'map',
                layers: [
                    new ol.layer.Tile({ source: new ol.source.OSM(), zIndex: 0 }),
                    subLayer,
                    vectorLayer,
                    rivLayer
                ],
                view: new ol.View({ center: [0,0], zoom: 5 })
            });

            map.on('pointermove', (evt) => {
                const hit = map.forEachFeatureAtPixel(evt.pixel, (f) => f);
                if (hit) {
                    const sid = String(getProp(hit, 'Subbasin'));
                    const v = valueForSub(sid);

                    let scenarioInfoHtml = '';

                    if (mapScenarioIds.length === 1) {
                        const datasetId = mapScenarioIds[0];
                        const label = effectiveScenarioLabelForDatasetSub(datasetId, sid);

                        scenarioInfoHtml =
                            `<b>Scenario</b>: ${escHtml(label)}<br>`;
                    } else if (mapScenarioIds.length === 2) {
                        const labelA = effectiveScenarioLabelForDatasetSub(mapScenarioIds[0], sid);
                        const labelB = effectiveScenarioLabelForDatasetSub(mapScenarioIds[1], sid);

                        scenarioInfoHtml =
                            `<b>Scenario A</b>: ${escHtml(labelA)}<br>` +
                            `<b>Scenario B</b>: ${escHtml(labelB)}<br>`;
                    }

                    if (els.mapInfo) {
                        els.mapInfo.innerHTML =
                            `<b>Subbasin ${escHtml(sid)}</b><br>` +
                            `<b>${escHtml(currentIndicator()?.name || '')}</b>: ${fmt(v)} ${unitText()}<br>` +
                            scenarioInfoHtml +
                            `<span class="mono">${escHtml(timeLabelText())}</span>`;
                    }

                    map.getTargetElement().style.cursor = 'pointer';
                } else {
                    if (els.mapInfo) {
                        els.mapInfo.textContent = 'Hover or click a subbasin';
                    }
                    map.getTargetElement().style.cursor = '';
                }
            });

            map.on('singleclick', (evt) => {
                const hit = map.forEachFeatureAtPixel(evt.pixel, (f) => f);
                if (hit) {
                    current.selectedSub = getProp(hit, 'Subbasin');
                    updateHelpText();
                    drawSeries();
                    vectorLayer.changed();
                }
            });
        } else {
            // ---------- Subsequent calls: reuse layers, replace features ----------
            subSource = subLayer.getSource();
            rivSource = rivLayer.getSource();

            if (subSource) {
                subSource.clear();
                subSource.addFeatures(subFeatures);
            }
            if (rivSource) {
                rivSource.clear();
                rivSource.addFeatures(rivFeatures);
            }

            // Make sure the “thematic” layer uses the updated subSource
            if (vectorLayer) {
                vectorLayer.setSource(subSource);
                vectorLayer.changed();
            }
        }

        // Fit to union extent (subbasins + rivers)
        const extAll = ol.extent.createEmpty();
        const subExt = subSource.getExtent();
        if (subExt && subExt.every(Number.isFinite)) ol.extent.extend(extAll, subExt);
        const rivExt = rivSource.getExtent();
        if (rivExt && rivExt.every(Number.isFinite)) ol.extent.extend(extAll, rivExt);
        if (!ol.extent.isEmpty(extAll)) {
            map.getView().fit(extAll, {
                padding: [18,18,18,18],
                duration: 250,
                maxZoom: 9
            });
        }

        // Apply toggles immediately
        if (els.toggleRivers && rivLayer) {
            rivLayer.setVisible(els.toggleRivers.checked);
        }
        if (els.toggleSubbasins && subLayer && vectorLayer) {
            const vis = els.toggleSubbasins.checked;
            subLayer.setVisible(vis);
            vectorLayer.setVisible(vis);
        }

        // Apply current opacity slider values
        if (els.opacitySubbasins && subLayer && vectorLayer) {
            const a = Math.max(0, Math.min(1, (+els.opacitySubbasins.value || 0) / 100));
            subLayer.setOpacity(a);
            vectorLayer.setOpacity(a);
            if (els.opacitySubbasinsVal) {
                els.opacitySubbasinsVal.textContent = `${Math.round(a * 100)}%`;
            }
        }
        if (els.opacityRivers && rivLayer) {
            const a = Math.max(0, Math.min(1, (+els.opacityRivers.value || 0) / 100));
            rivLayer.setOpacity(a);
            if (els.opacityRiversVal) {
                els.opacityRiversVal.textContent = `${Math.round(a * 100)}%`;
            }
        }
    }

    // Style based on current choropleth values
    let cachedRange = [0,0];
    function subStyleFn(feature) {
        const sid = getProp(feature, 'Subbasin');
        const v = valueForSub(sid);
        const color = Number.isFinite(v) ? colorScale(cachedRange[0], cachedRange[1])(v) : 'rgb(242,242,242)';
        const isSel = current.selectedSub && +sid === +current.selectedSub;
        return new ol.style.Style({
            stroke: new ol.style.Stroke({ color: isSel ? '#000' : '#555', width: isSel ? 2.5 : 1 }),
            fill: new ol.style.Fill({ color })
        });
    }

    function riverStyle(feature, resolution) {
        const w = (resolution < 50) ? 4.5 : (resolution < 150) ? 3.5 : (resolution < 400) ? 2.5 : 1.8;
        return [
            new ol.style.Style({
                stroke: new ol.style.Stroke({ color: '#ffffff', width: w + 2, lineCap: 'round', lineJoin: 'round' })
            }),
            new ol.style.Style({
                stroke: new ol.style.Stroke({ color: '#1f77b4', width: w, lineCap: 'round', lineJoin: 'round' })
            })
        ];
    }

    // ---------- Recompute + redraw ----------
    function recomputeAndRedraw(fast=false) {
        // recompute min/max across subs
        const vals = subFeatures.map(f => valueForSub(getProp(f, 'Subbasin'))).filter(Number.isFinite);
        let vmin = Infinity, vmax = -Infinity;
        for (const f of subFeatures) {
            const v = valueForSub(getProp(f, 'Subbasin'));
            if (!Number.isFinite(v)) continue;
            if (v < vmin) vmin = v;
            if (v > vmax) vmax = v;
        }
        cachedRange = (vmin !== Infinity) ? [vmin, vmax] : [0, 0];

        // legend + map note
        updateLegend();
        const modeLabel = current.aggMode === 'crop'
            ? ` — ${current.crop ? displayCrop(current.crop) : 'selected crop'}`
            : ' — average across all crops';

        const base = `${currentIndicator()?.name || ''}${modeLabel}`;
        els.mapNote.textContent = `${base}${describeMapScenario()} — ${timeLabelText()}`;

        // restyle map
        if (vectorLayer) vectorLayer.changed();

        // refresh timeseries if a sub is selected (skip heavy redraw on slider drag if fast=true)
        if (!fast) drawSeries();

        updateHelpText();
    }

    function updateLegend() {
        const def = currentIndicator();
        els.legendTitle.textContent = `${def?.name || 'Metric'} ${unitText() ? `(${unitText()})` : ''}`;

        // Clear and rebuild the scale with labels under each swatch
        els.legendScale.innerHTML = '';

        const steps = 9; // adjust if you want more/less ticks
        const [vmin, vmax] = cachedRange;

        // If range invalid, just render neutral strip with em-dash labels
        const renderItem = (t, label) => {
            const c = rampViridisRGB(t);
            const wrap = document.createElement('div');
            wrap.className = 'item';
            const sw = document.createElement('div');
            sw.className = 'swatch';
            sw.style.background = `rgb(${c[0]},${c[1]},${c[2]})`;
            const lab = document.createElement('div');
            lab.className = 'tick mono';
            lab.textContent = label;
            wrap.appendChild(sw);
            wrap.appendChild(lab);
            els.legendScale.appendChild(wrap);
        };

        if (!Number.isFinite(vmin) || !Number.isFinite(vmax) || !(vmax > vmin)) {
            for (let i = 0; i < steps; i++) renderItem(i / (steps - 1), '—');
            return;
        }

        for (let i = 0; i < steps; i++) {
            const t = i / (steps - 1);
            const val = vmin + t * (vmax - vmin);
            renderItem(t, fmt(val));
        }
    }

    // ---------- Choropleth value for a subbasin ----------
    function valueForSub(sub) {
        const def = currentIndicator();
        if (!def) return NaN;
        if (!mapScenarioIds.length) return NaN;

        // One scenario: direct value for that run
        if (mapScenarioIds.length === 1) {
            return valueForSubRun(mapScenarioIds[0], sub);
        }
        // Two scenarios: absolute difference between them
        const v1 = valueForSubRun(mapScenarioIds[0], sub);
        const v2 = valueForSubRun(mapScenarioIds[1], sub);

        if (!Number.isFinite(v1) || !Number.isFinite(v2)) return NaN;
        return Math.abs(v2 - v1);
    }

    function num(v) {
        const n = (typeof v === 'number') ? v : parseFloat(String(v).replace(',','.'));
        return Number.isFinite(n) ? n : NaN;
    }

    function valueForSubRun(runId, sub, yearOverride = null) {
        const def = currentIndicator();
        if (!def) return NaN;

        // MCA still special
        if (def.id === 'mca_score' || def.isMca) {
            if (!mca) return NaN;
            const v = mca.getScenarioScore(runId);
            return Number.isFinite(v) ? v : NaN;
        }

        const rd = runsStore.get(runId);
        if (!rd) return NaN;

        const year =
            (yearOverride != null)
                ? yearOverride
                : (rd.years[current.yearIndex] ?? rd.years[rd.years.length - 1]);

        if (!Number.isFinite(+year)) return NaN;

        const idx = rd.index?.[def.id];
        if (!idx) return NaN;

        if (idx.grain === 'sub') {
            const v = idx.m.get(`${year}|${+sub}`);
            return Number.isFinite(v) ? v : NaN;
        }

        // sub_crop
        if (current.aggMode === 'sub') {
            const v = idx.mean.get(`${year}|${+sub}`);
            return Number.isFinite(v) ? v : NaN;
        }

        if (!current.crop) return NaN;
        const v = idx.m.get(`${year}|${+sub}|${current.crop}`);
        return Number.isFinite(v) ? v : NaN;
    }

    // ---------- Time series for clicked sub ----------
    function drawSeries() {
        const def = currentIndicator();
        if (!def || !current.selectedSub || !selectedRunIds.length) {
            updateHelpText();
            if (els.seriesChart) Plotly.purge(els.seriesChart);
            if (els.cropChart)   Plotly.purge(els.cropChart);
            return;
        }

        const traces = [];

        for (const runId of selectedRunIds) {
            const rd = runsStore.get(runId);
            if (!rd) continue;

            const meta = runsMeta.find(r => String(r.id) === String(runId));
            const runLabel = meta ? meta.run_label : `Run ${runId}`;

            const xs = rd.years.map((_, i) => i);
            const ys = rd.years.map(Y => {
                const v = valueForSubRun(runId, current.selectedSub, Y);
                return Number.isFinite(v) ? v : null;
            });

            traces.push({
                type: 'scatter',
                mode: 'lines',
                connectgaps: true,
                line: { width: 2 },
                x: xs,
                y: ys,
                name: runLabel,  // legend + hover
                hovertemplate: `${runLabel}<br>Year %{x}<br>%{y:.3f}<extra></extra>`
            });
        }

        const modeLabel = current.aggMode === 'crop'
            ? displayCrop(current.crop)
            : 'All crops (average)';

        Plotly.newPlot(els.seriesChart, traces, {
            margin: { t: 30, r: 10, b: 40, l: 55 },
            title: `Subbasin ${current.selectedSub} — ${modeLabel} — ${def.name}`,
            xaxis: {
                title: 'Years after implementation',
                tickmode: 'linear',
                dtick: 1
            },
            yaxis: {
                title: `${def.name}${unitText() ? ` (${unitText()})` : ''}`
            },
            showlegend: true,
            colorway: COLORWAY
        }, { displayModeBar: false, responsive: true });

        drawCropChart(def);
        updateHelpText();
    }

    // ---------- UI population ----------
    function populateMetricsCombined() {
        if (!els.metric) return;

        const defs = allIndicatorDefs().map(d => ({ ...d, enabled: true }));
        const previous = current.indicatorId;

        const bySector = new Map();
        for (const d of defs) {
            const sector = d.sector || 'Other';
            if (!bySector.has(sector)) bySector.set(sector, []);
            bySector.get(sector).push(d);
        }

        const html = [...bySector.entries()]
            .sort(([a], [b]) => a.localeCompare(b))
            .map(([sector, list]) => {
                const opts = list
                    .sort((a, b) => String(a.name).localeCompare(String(b.name)))
                    .map(d => `
          <option value="${escAttr(d.id)}">
            ${escHtml(d.name)}
          </option>
        `).join('');
                return `<optgroup label="${escAttr(sector)}">${opts}</optgroup>`;
            }).join('');

        els.metric.innerHTML = html;

        const stillValid = defs.find(d => d.id === previous);
        const pick = stillValid || defs.find(d => !d.isMca) || defs[0] || null;

        if (pick) {
            els.metric.value = pick.id;
            current.indicatorId = pick.id;
        } else {
            current.indicatorId = null;
        }

        updateCropVisibility();
    }

    function populateCrops(preferredCrop = null) {
        if (!els.crop) return;

        if (!crops.length) {
            els.crop.innerHTML = '';
            current.crop = null;
            return;
        }

        els.crop.innerHTML = crops
            .map(c => `<option value="${escAttr(c)}">${escHtml(displayCrop(c))}</option>`)
            .join('');

        let target = preferredCrop && crops.includes(preferredCrop)
            ? preferredCrop
            : crops[0];

        els.crop.value = target;
        current.crop = target;
    }

    function initYearSlider() {
        if (!els.yearSlider) return;

        els.yearSlider.min = 0;
        els.yearSlider.max = Math.max(0, years.length - 1);

        if (current.yearIndex < 0 || current.yearIndex >= years.length) {
            current.yearIndex = 0;
        }

        els.yearSlider.value = current.yearIndex;
        els.yearMin.textContent   = years.length ? scenarioYearLabel(0) : '—';
        els.yearMax.textContent   = years.length ? scenarioYearLabel(years.length - 1) : '—';
        els.yearLabel.textContent = years.length ? scenarioYearLabel(current.yearIndex) : '—';

        els.yearSlider.disabled = years.length <= 1; // enable unless trivial
    }

    function updateCropVisibility() {
        const def = currentIndicator();
        const needsCrop = def?.grain === 'sub_crop';
        const showCropSelect = needsCrop && current.aggMode !== 'sub';

        if (els.cropGroup) els.cropGroup.style.display = showCropSelect ? 'block' : 'none';

        // Help text: prefer description, otherwise fallback
        if (els.indicatorHelp) {
            const fallback = def
                ? `Source: ${String(def.source || '').toUpperCase()} · ${def.grain === 'sub_crop' ? 'Subbasin + crop' : 'Subbasin'}`
                : '';
            els.indicatorHelp.textContent = (def?.description || fallback || '');
        }

        // Guard: if crop required but we have no crops, disable crop selector + show message
        if (needsCrop && current.aggMode !== 'sub') {
            const hasCrops = Array.isArray(crops) && crops.length > 0;
            if (els.crop) els.crop.disabled = !hasCrops;
            if (!hasCrops) {
                current.crop = null;
                if (els.crop) els.crop.value = '';

                if (els.indicatorHelp) {
                    els.indicatorHelp.textContent =
                        (def?.description ? `${def.description} ` : '') +
                        'No crop codes found for this run (cannot display crop-specific values).';
                }
            }
        } else {
            if (els.crop) els.crop.disabled = false;
        }
    }

    // ---------- Helpers ----------
    function setIdleState() {
        hasRunsForStudyArea = null; // unknown yet

        if (els.dataset) {
            els.dataset.innerHTML =
                '<div class="text-muted small">Select a study area first.</div>';
        }

        disableCustomScenarioButton();

        if (els.metric) {
            els.metric.innerHTML = '';
        }
        if (els.crop) {
            els.crop.innerHTML = '';
        }
        if (els.cropGroup) {
            els.cropGroup.style.display = 'none';
        }

        if (els.yearSlider) {
            els.yearSlider.value = 0;
            els.yearSlider.disabled = true;
        }
        if (els.yearMin)   els.yearMin.textContent = '—';
        if (els.yearMax)   els.yearMax.textContent = '—';
        if (els.yearLabel) els.yearLabel.textContent = '—';

        if (els.legendTitle) els.legendTitle.textContent = 'Metric';
        if (els.legendScale) els.legendScale.innerHTML = '';

        if (els.mapNote) els.mapNote.textContent = 'Select a study area to begin.';
        if (els.mapInfo) els.mapInfo.textContent = 'Select a study area, then hover or click a subbasin.';

        if (els.seriesHint) {
            els.seriesHint.textContent = 'Select a study area, then click a subbasin to load time series.';
        }
        if (els.seriesChart) Plotly.purge(els.seriesChart);
        if (els.cropChart)   Plotly.purge(els.cropChart);

        updateHelpText();
    }

    function allIndicatorDefs() {
        return [...indicatorDefs, ...mcaIndicatorDefs];
    }

    function applyDatasetSelectionToUi(datasetIds) {
        const wanted = new Set((datasetIds || []).map(id => String(id).trim()).filter(Boolean));

        if (!els.dataset) return;

        const boxes = els.dataset.querySelectorAll('input.dataset-checkbox[data-run-id]');
        boxes.forEach(box => {
            const rid = String(box.dataset.runId || '').trim();
            box.checked = wanted.has(rid);
        });
    }

    async function handleDatasetSelectionChanged() {
        selectedRunIds = getSelectedRunIdsFromSelect();
        lastSelectedRunIds = selectedRunIds.slice();

        cancelPreloads();

        current.selectedSub = null;
        if (els.seriesChart) Plotly.purge(els.seriesChart);
        if (els.cropChart) Plotly.purge(els.cropChart);

        if (!selectedRunIds.length) {
            current.selectedSub = null;
            current.runId = null;
            mapScenarioIds = [];
            updateMapScenarioOptions();

            if (els.mapNote) {
                els.mapNote.textContent = 'Select at least one scenario.';
            }

            if (els.seriesChart) Plotly.purge(els.seriesChart);
            if (els.cropChart) Plotly.purge(els.cropChart);

            updateHelpText();
            if (vectorLayer) vectorLayer.changed();

            if (mcaEnabled && mca) {
                await mca.setAvailableRuns({
                    studyAreaId: currentStudyAreaId,
                    selectedRunIds: [],
                    runsMeta,
                    runCropsById: {},
                });
                mca.setAllowedCrops([]);
            }
            return;
        }

        showToast('Loading metrics for selected scenarios…', false, null, 'OK', 2500);
        setBusy(true, 'Loading…');
        updateHelpText();

        try {
            if (els.loadingDetail) els.loadingDetail.textContent = 'Loading indicator registry…';
            await ensureIndicatorRegistryLoaded();

            if (els.loadingDetail) els.loadingDetail.textContent = 'Loading crop names…';
            await ensureCropLookupLoaded();

            if (els.loadingDetail) els.loadingDetail.textContent = 'Loading scenario metrics…';
            await Promise.all(selectedRunIds.map(id => ensureRunLoaded(id)));

            const remainingDefaultIds = (runsMeta || [])
                .filter(r => !!r.is_default && !isCustomDatasetId(r.id))
                .map(r => String(r.id))
                .filter(id => id !== '')
                .filter(id => !runsStore.has(id));

            setTimeout(() => {
                startPreloadDefaultRuns(remainingDefaultIds, { concurrency: 2 });
            }, 0);

            if (els.loadingDetail) els.loadingDetail.textContent = 'Rendering…';

            const cropSet = new Set();
            for (const id of selectedRunIds) {
                const rd = runsStore.get(id);
                for (const c of (rd?.crops || [])) cropSet.add(c);
            }

            mapScenarioIds = mapScenarioIds.filter(id => selectedRunIds.includes(id));
            if (!mapScenarioIds.length) mapScenarioIds = [selectedRunIds[0]];
            if (mapScenarioIds.length > 2) mapScenarioIds = mapScenarioIds.slice(0, 2);

            const baseRunId = mapScenarioIds[0];
            current.runId = baseRunId;

            activateRun(baseRunId, { preserveCrop: true });

            if (current.indicatorId) {
                if (els.loadingDetail) els.loadingDetail.textContent = 'Loading selected metric…';
                await Promise.all(selectedRunIds.map(id => ensureIndicatorLoaded(id, current.indicatorId)));
            }

            updateMapScenarioOptions();
            recomputeAndRedraw();

            if (selectedRunIds.every(id => runsStore.has(id))) {
                showToast('Metrics loaded. Click a subbasin on the map to show graphs.', false, null, 'OK', 4000);
            }

            if (mcaEnabled && mca) {
                const runCropsById = {};
                for (const id of selectedRunIds) {
                    const rd = runsStore.get(id);
                    runCropsById[id] = (rd?.crops || []).slice();
                }

                const baselineRunId = mca.getBaselineRunId();

                if (baselineRunId && Number.isFinite(baselineRunId) && baselineRunId > 0) {
                    const baselineKey = String(baselineRunId);

                    if (!runsStore.has(baselineKey)) {
                        console.log(`[MCA] Loading baseline run ${baselineRunId} for crop data`);
                        await ensureRunLoaded(baselineKey);
                    }

                    const baselineData = runsStore.get(baselineKey);
                    if (baselineData?.crops && !runCropsById[baselineKey]) {
                        runCropsById[baselineKey] = baselineData.crops.slice();
                        console.log(`[MCA] Added baseline run ${baselineRunId} crops to runCropsById:`, baselineData.crops);
                    }
                }

                lastRunCropsById = runCropsById;

                await mca.setAvailableRuns({
                    studyAreaId: currentStudyAreaId,
                    selectedRunIds,
                    runsMeta,
                    runCropsById,
                });

                mca.setAllowedCrops([...cropSet]);
            }
        } catch (err) {
            console.error('[dataset change] failed', err);
            showToast('Failed to load metrics. Check console for details.', true, null, 'OK', 6000);
        } finally {
            setBusy(false);
            updateHelpText();
        }
    }

    async function refreshDatasetsAndScenarios({ autoSelectDatasetId = null } = {}) {
        if (!Number.isFinite(+currentStudyAreaId) || +currentStudyAreaId <= 0) return;

        try {
            showInlineSpinner(els.dataset, 'Refreshing scenarios…');

            const previousSelected = new Set(selectedRunIds.map(String));

            const runIds = await loadRuns();
            hasRunsForStudyArea = runIds.length > 0;

            // Re-apply previous selections if they still exist
            applyDatasetSelectionToUi([...previousSelected]);

            // If caller wants to auto-select a specific new dataset, do it too
            if (autoSelectDatasetId != null) {
                const wanted = String(autoSelectDatasetId).trim();
                const boxes = els.dataset?.querySelectorAll('input.dataset-checkbox[data-run-id]') || [];
                boxes.forEach(box => {
                    const rid = String(box.dataset.runId || '').trim();
                    if (rid === wanted) {
                        box.checked = true;
                    }
                });
            }

            // Re-run selection flow so datasets, charts, MCA, map scenario options all update
            await handleDatasetSelectionChanged();

            if (mca && els.mcaWorkspaceSelect) {
                try {
                    await mca.refreshWorkspaceList(currentStudyAreaId);
                } catch (err) {
                    console.error('[MCA] Failed to refresh workspace list', err);
                }
            }
        } catch (err) {
            console.error('[refreshDatasetsAndScenarios] failed', err);
            showToast('Failed to refresh scenarios list.', true, null, 'OK', 5000);
        }
    }

    function updateHelpText() {
        if (!els.seriesHint) return;

        const show = (msg) => {
            els.seriesHint.style.display = 'block';
            els.seriesHint.textContent = msg;
        };

        // 1) no study area
        if (!currentStudyAreaId || currentStudyAreaId <= 0) {
            show('Select a study area to begin.');
            return;
        }

        // 2) loading (runs/map OR metrics)
        if (isLoading) {
            show(selectedRunIds.length ? 'Loading metrics… please wait.' : 'Loading scenarios… please wait.');
            return;
        }

        // 3) study area selected, but it has no runs at all
        if (hasRunsForStudyArea === false) {
            show('No scenarios available for this study area.');
            return;
        }

        // 4) runs exist but no scenarios selected
        if (!selectedRunIds.length) {
            show('Select one or more scenarios to load metrics.');
            return;
        }

        // 5) scenarios selected but no subbasin clicked yet
        if (!current.selectedSub) {
            show('Metrics loaded. Now click a subbasin on the map to show time series graphs.');
            return;
        }

        if (current.selectedSub && current.aggMode === 'sub') {
            show('Showing averages across all crops. Switch to "Crop" to see individual crop results.');
            return;
        }

        // 6) everything ready -> hide the hint
        els.seriesHint.style.display = 'none';
    }

    function getSelectedRunIdsFromSelect() {
        if (!els.dataset) return [];
        const boxes = els.dataset.querySelectorAll('input.dataset-checkbox[data-run-id]');
        return [...boxes]
            .filter(b => b.checked)
            .map(b => String(b.dataset.runId || '').trim())
            .filter(v => v !== '');
    }

    async function ensureRunLoaded(runId, { isPreload = false } = {}) {
        if (runsStore.has(runId)) return;

        const existing = runLoadPromises.get(runId);

        if (existing && !isPreload && preloadAbortByRunId.has(runId)) {
            try { preloadAbortByRunId.get(runId).abort(); } catch (_) {}
            preloadAbortByRunId.delete(runId);
            runLoadPromises.delete(runId);
        } else if (existing) {
            return existing;
        }

        const myEpoch = loadEpoch;   // add this

        let controller = null;
        let signal = undefined;

        if (isPreload) {
            controller = new AbortController();
            signal = controller.signal;
            preloadAbortByRunId.set(runId, controller);
        }

        const p = (async () => {
            const runData = await loadRunMeta(runId, { signal });

            // ignore stale completion after study area switch
            if (myEpoch !== loadEpoch) return;

            runsStore.set(runId, runData);
        })();

        runLoadPromises.set(runId, p);

        try {
            await p;
        } finally {
            runLoadPromises.delete(runId);
            if (isPreload) preloadAbortByRunId.delete(runId);
        }
    }

    function isCustomDatasetId(id) {
        return typeof id === 'string' && id.startsWith('custom:');
    }

    function parseDatasetSourceId(id) {
        if (isCustomDatasetId(id)) {
            return parseInt(id.split(':')[1], 10);
        }
        const n = parseInt(String(id), 10);
        return Number.isFinite(n) ? n : 0;
    }

    function datasetDomId(id) {
        return `run-${String(id).replace(/[^a-zA-Z0-9_-]/g, '_')}`;
    }

    function cancelPreloads() {
        // stop the queue / runner
        preloadEpoch++;
        preloadQueue = [];
        preloadTotal = preloadDone = preloadInFlight = 0;
        setPreloadStatus(0, 0);

        // abort in-flight preload fetches
        for (const [, ctrl] of preloadAbortByRunId.entries()) {
            try { ctrl.abort(); } catch (_) {}
        }
        preloadAbortByRunId.clear();
    }

    function startPreloadDefaultRuns(runIds, { concurrency = 2 } = {}) {
        preloadEpoch++;
        const myEpoch = preloadEpoch;

        // Only preload what isn't already loaded
        const ids = (runIds || [])
            .map(id => String(id))
            .filter(id => id !== '' && !isCustomDatasetId(id));
        preloadQueue = ids.filter(id => !runsStore.has(id));

        preloadTotal = preloadQueue.length;
        preloadDone = 0;
        preloadInFlight = 0;

        setPreloadStatus(preloadDone, preloadTotal);

        if (!preloadQueue.length) return;

        preloadRunning = true;

        const tick = async () => {
            if (myEpoch !== preloadEpoch) return; // cancelled by new study area
            if (!preloadQueue.length && preloadInFlight === 0) {
                preloadRunning = false;
                setPreloadStatus(preloadTotal, preloadTotal);
                return;
            }

            while (preloadInFlight < concurrency && preloadQueue.length) {
                const rid = preloadQueue.shift();
                preloadInFlight++;

                // Fire and forget, but tracked
                ensureRunLoaded(rid, { isPreload: true })
                    .catch(err => {
                        if (err?.name === 'AbortError') return;
                        console.debug('[preload] failed run', rid, err);
                    })
                    .finally(() => {
                        preloadInFlight--;
                        preloadDone++;
                        setPreloadStatus(preloadDone, preloadTotal);
                        tick();
                    });
            }
        };

        tick();
    }

    function updateMapScenarioOptions() {
        if (!els.mapScenario) return;

        const options = selectedRunIds
            .map(id => {
                const meta = runsMeta.find(r => String(r.id) === String(id));
                if (!meta) return null;
                const labelParts = [meta.run_label];
                if (meta.run_date) labelParts.push(`(${meta.run_date})`);
                return {
                    id: String(id),
                    label: labelParts.join(' ')
                };
            })
            .filter(Boolean);

        if (!options.length) {
            els.mapScenario.innerHTML =
                '<option value="" disabled>No scenarios available</option>';
            return;
        }

        const html = options.map(o => {
            const selected = mapScenarioIds.includes(o.id) ? 'selected' : '';
            return `<option value="${escAttr(o.id)}" ${selected}>${escHtml(o.label)}</option>`;
        }).join('');

        els.mapScenario.innerHTML = html;
    }

    function activateRun(runId, { preserveCrop = false } = {}) {
        const rd = runsStore.get(runId);
        if (!rd) return;

        const prevCrop = preserveCrop ? current.crop : null;

        years = rd.years.slice();
        crops = rd.crops.slice();
        current.runId = runId;

        populateCrops(prevCrop);
        initYearSlider();
        populateMetricsCombined();
    }

    function drawCropChart(def) {
        if (!current.selectedSub || !selectedRunIds.length) {
            Plotly.purge(els.cropChart);
            return;
        }

        if (current.aggMode === 'sub') {
            Plotly.purge(els.cropChart);
            return;
        }

        const year = years[current.yearIndex];
        if (!Number.isFinite(+year)) {
            Plotly.purge(els.cropChart);
            return;
        }

        // Only meaningful for sub_crop indicators
        if (def.grain !== 'sub_crop') {
            Plotly.purge(els.cropChart);
            return;
        }

        const traces = [];

        for (const runId of selectedRunIds) {
            const rd = runsStore.get(runId);
            if (!rd) continue;

            const meta = runsMeta.find(r => String(r.id) === String(runId));
            const runLabel = meta ? meta.run_label : `Run ${runId}`;

            const rows = rowsForIndicator(rd, def.id)
                .filter(r => +r.sub === +current.selectedSub && +r.year === +year && r.crop);

            if (!rows.length) continue;

            // build bars per crop
            const x = [];
            const y = [];

            for (const r of rows) {
                x.push(displayCrop(r.crop));
                y.push(num(r.value));
            }

            traces.push({
                type: 'bar',
                x,
                y,
                name: runLabel,
                hovertemplate: `${runLabel}<br>%{x}<br>%{y:.3f}<extra></extra>`
            });
        }

        if (!traces.length) {
            Plotly.purge(els.cropChart);
            return;
        }

        Plotly.newPlot(els.cropChart, traces, {
            margin: { t: 30, r: 10, b: 80, l: 55 },
            title: `Crop breakdown — Subbasin ${current.selectedSub} — ${def.name} — ${scenarioYearLabel(current.yearIndex)}`,
            xaxis: { title: 'Crop', tickangle: -30, automargin: true },
            yaxis: { title: `${def.name}${unitText() ? ` (${unitText()})` : ''}` },
            barmode: 'group',
            showlegend: false,
            colorway: COLORWAY
        }, { displayModeBar: false, responsive: true });
    }

    function currentIndicator() {
        return allIndicatorDefs().find(d => d.id === current.indicatorId) || null;
    }
    function cropSuffix() {
        const def = currentIndicator();
        const needsCrop = def?.grain === 'sub_crop';
        return needsCrop && current.crop ? ` — ${displayCrop(current.crop)}` : '';
    }
    function unitText() {
        return currentIndicator()?.unit || '';
    }

    function getProp(f, key) {
        if (!f) return undefined;
        if (typeof f.get === 'function') return f.get(key);
        if (typeof f.getProperties === 'function') {
            const p = f.getProperties(); return p ? p[key] : undefined;
        }
        const p = f.properties || f.values_ || null;
        return p ? p[key] : undefined;
    }

    function displayCrop(code) {
        const n = cropLookup[code];
        // If we know the name: show just the name.
        // If not: fall back to the raw code.
        return n || code;
    }

    function sourceRunLabelById(sourceRunId) {
        const meta = runsMeta.find(r =>
            r.dataset_type === 'run' && parseInt(String(r.source_id || r.id), 10) === Number(sourceRunId)
        );
        return meta ? meta.run_label : `Run ${sourceRunId}`;
    }

    function effectiveScenarioLabelForDatasetSub(datasetId, sub) {
        const rd = runsStore.get(datasetId);
        if (!rd) return scenarioLabel(datasetId);

        if (!rd.isCustom) {
            return scenarioLabel(datasetId);
        }

        const sourceRunId = rd.effectiveRunBySub?.get(String(sub));
        if (!Number.isFinite(sourceRunId)) {
            return scenarioLabel(datasetId);
        }

        return sourceRunLabelById(sourceRunId);
    }

    function scenarioLabel(runId) {
        const meta = runsMeta.find(r => String(r.id) === String(runId));
        return meta ? meta.run_label : `Run ${runId}`;
    }

    function describeMapScenario() {
        if (!mapScenarioIds.length) return '';
        if (mapScenarioIds.length === 1) {
            return ` — Scenario: ${scenarioLabel(mapScenarioIds[0])}`;
        }
        const a = scenarioLabel(mapScenarioIds[0]);
        const b = scenarioLabel(mapScenarioIds[1]);
        return ` — Difference |${b} − ${a}|`;
    }

    function setBusy(on, msg = 'Loading…', { delayMs = 400 } = {}) {
        // Always disable controls immediately when turning busy on
        // Show the alert only after delayMs to prevent flicker.

        if (on) {
            isLoading = true;

            // token for this busy cycle
            busyToken++;
            const token = busyToken;

            // cancel any previous timers
            if (busyTimer) clearTimeout(busyTimer);
            busyTimer = null;
            busyShown = false;

            // disable key controls immediately
            if (els.dataset) els.dataset.querySelectorAll('input,button').forEach(el => el.disabled = true);
            if (els.metric) els.metric.disabled = true;
            if (els.crop) els.crop.disabled = true;
            if (els.yearSlider) els.yearSlider.disabled = true;

            // set texts now (even if alert appears later)
            if (els.loadingTitle)  els.loadingTitle.textContent = msg;
            if (els.loadingDetail) els.loadingDetail.textContent = 'Fetching indicators, crops, and scenario metrics…';

            // optionally update map note immediately
            if (els.mapNote) els.mapNote.textContent = msg;

            // show alert only if still busy after delay
            busyTimer = setTimeout(() => {
                if (!isLoading) return;
                if (token !== busyToken) return; // a newer busy cycle started

                busyShown = true;
                if (els.loadingAlert) {
                    els.loadingAlert.classList.remove('d-none');
                    els.loadingAlert.classList.add('d-flex');
                }
            }, Math.max(0, delayMs));

            return;
        }

        // turning busy off
        isLoading = false;

        if (busyTimer) clearTimeout(busyTimer);
        busyTimer = null;

        // re-enable controls
        if (els.dataset) els.dataset.querySelectorAll('input,button').forEach(el => el.disabled = false);
        if (els.metric) els.metric.disabled = false;
        if (els.crop) els.crop.disabled = false;
        if (els.yearSlider) els.yearSlider.disabled = false;

        // hide alert (whether it was shown or not)
        if (els.loadingAlert) {
            els.loadingAlert.classList.add('d-none');
            els.loadingAlert.classList.remove('d-flex');
        }
        if (els.loadingTitle)  els.loadingTitle.textContent = '';
        if (els.loadingDetail) els.loadingDetail.textContent = '';
    }

    function disableCustomScenarioButton(title = 'Select a study area first') {
        if (!els.manageCustomScenariosBtn) return;
        els.manageCustomScenariosBtn.disabled = true;
        els.manageCustomScenariosBtn.setAttribute('aria-disabled', 'true');
        els.manageCustomScenariosBtn.title = title;
    }

    function enableCustomScenarioButton(title = 'Manage custom scenarios') {
        if (!els.manageCustomScenariosBtn) return;
        els.manageCustomScenariosBtn.disabled = false;
        els.manageCustomScenariosBtn.setAttribute('aria-disabled', 'false');
        els.manageCustomScenariosBtn.title = title;
    }

    function showInlineSpinner(targetEl, text = 'Loading…') {
        if (!targetEl) return;
        targetEl.innerHTML = `
            <div class="text-muted small d-flex align-items-center gap-2">
              <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
              <span>${escHtml(text)}</span>
            </div>
          `;
    }

    // ---------- Pure utils ----------
    function guessGeoJSONProjection(gj) {
        // Heuristic: look for any coordinate whose absolute value is > 200.
        // If found, assume meters (EPSG:3857). Otherwise assume degrees (EPSG:4326).
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
            if (gj?.type === 'FeatureCollection' && Array.isArray(gj.features) && gj.features.length) {
                for (const f of gj.features) {
                    sampleAbs = coordsScan(f.geometry);
                    if (sampleAbs != null) break;
                }
            } else if (gj?.type && gj?.coordinates) {
                sampleAbs = coordsScan(gj);
            }
            if (sampleAbs != null && sampleAbs > 200) return 'EPSG:3857';
        } catch (e) {
            console.warn('[CRS] guess failed:', e);
        }
        return 'EPSG:4326';
    }

    function toNum(v) {
        if (v === '' || v == null || v === '-') return NaN;
        const n = typeof v === 'number' ? v : parseFloat(String(v).replace(',','.'));
        return Number.isFinite(n) ? n : NaN;
    }
    function clean(s) { return String(s ?? '').replace(/\s+/g,' ').trim(); }

    // Basic number formatting for legend/hover
    function fmt(n) {
        if (!Number.isFinite(n)) return '—';
        const a = Math.abs(n);
        if (a >= 1000) return n.toFixed(0);
        if (a >= 100)  return n.toFixed(1);
        return n.toFixed(3);
    }

    // Escape helpers for safe HTML/attributes
    const escHtml = s => String(s).replace(/[&<>"']/g, m => (
        {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]
    ));
    const escAttr = s => escHtml(s).replace(/"/g,'&quot;');

    function rampViridisRGB(t) {
        const stops = [
            [68,1,84],[71,44,122],[59,81,139],[44,113,142],[33,144,141],
            [39,173,129],[92,200,99],[170,220,50],[253,231,37]
        ];
        const i = Math.min(stops.length-2, Math.max(0, Math.floor(t*(stops.length-1))));
        const f = t*(stops.length-1) - i;
        const c0 = stops[i], c1 = stops[i+1];
        return [0,1,2].map(k => Math.round(c0[k] + (c1[k]-c0[k])*f));
    }
    function colorScale(vmin, vmax) {
        if (!(vmax > vmin)) {
            const mid = rampViridisRGB(0.5);
            return () => `rgb(${mid[0]},${mid[1]},${mid[2]})`;
        }
        return v => {
            const t = Math.min(1, Math.max(0, (v - vmin) / (vmax - vmin)));
            const c = rampViridisRGB(t);
            return `rgb(${c[0]},${c[1]},${c[2]})`;
        };
    }

    async function switchStudyArea(newStudyAreaId) {
        loadEpoch++;
        preloadEpoch++;            // cancels any in-flight preload runner
        preloadQueue = [];
        preloadTotal = preloadDone = preloadInFlight = 0;
        setPreloadStatus(0, 0);
        runLoadPromises.clear();   // optional; safe because new area will load different runs anyway
        const epoch = loadEpoch;

        if (!Number.isFinite(+newStudyAreaId) || +newStudyAreaId <= 0) return;

        enableCustomScenarioButton();

        currentStudyAreaId = +newStudyAreaId;
        subbasinGeoUrl = `/api/study_area_subbasins_geo.php?study_area_id=${encodeURIComponent(currentStudyAreaId)}`;
        riversGeoUrl   = `/api/study_area_reaches_geo.php?study_area_id=${encodeURIComponent(currentStudyAreaId)}`;

        // reset state & UI bits for new area
        selectedRunIds = [];
        mapScenarioIds = [];
        current.runId = null;
        current.selectedSub = null;
        hasRunsForStudyArea = null;
        runsMeta = [];
        runsStore.clear();
        mcaIndicatorDefs = [];

        // reset MCA selection cache
        lastRunCropsById = {};
        lastSelectedRunIds = [];

        // If MCA is enabled, clear MCA state for new area
        if (mcaEnabled && mca) {
            await mca.setAvailableRuns({
                studyAreaId: currentStudyAreaId,
                selectedRunIds: [],
                runsMeta: [],
                runCropsById: {},
            });

            mca.setAllowedCrops([]);
        }

        if (els.dataset) {
            els.dataset.innerHTML = '<div class="text-muted small">Loading runs…</div>';
        }
        if (els.seriesChart) Plotly.purge(els.seriesChart);
        if (els.cropChart)   Plotly.purge(els.cropChart);

        try {
            await bootstrapFromApi();
            if (epoch !== loadEpoch) return; // abandoned
        } catch (err) {
            console.error('[switchStudyArea] failed:', err);
            if (els.mapNote) els.mapNote.textContent = 'Failed to load runs or data for this study area.';
        }

        if (mca && els.mcaWorkspaceSelect) {
            try {
                await mca.refreshWorkspaceList(currentStudyAreaId);
            } catch (err) {
                console.error('[MCA] Failed to load workspace list', err);
                if (els.mcaWorkspaceStatus) {
                    els.mcaWorkspaceStatus.textContent = err.message || String(err);
                }
            }
        }

        if (mcaEnabled && mca) {
            await rehydrateMcaFromCurrentRuns();
        }
    }

    // Expose controller
    return {
        switchStudyArea,
        refreshDatasetsAndScenarios,
    };
}