// assets/js/dashboard/subbasin-dashboard.js
export function initSubbasinDashboard({
                                          els,
                                          indicators,
                                          studyAreaId,
                                          apiBase = '/api',
                                      }) {
    // ---------- State ----------
    let currentStudyAreaId = studyAreaId;
    let subbasinGeoUrl = `/api/study_area_subbasins_geo.php?study_area_id=${encodeURIComponent(currentStudyAreaId)}`;
    let riversGeoUrl   = `/api/study_area_reaches_geo.php?study_area_id=${encodeURIComponent(currentStudyAreaId)}`;

    let map, subLayer, subSource, subFeatures = [];
    let vectorLayer;
    let rivLayer, rivSource;
    let rows = [];      // monthly HRU rows
    let rchRows = [];   // monthly RCH rows
    let snuRows = [];
    let years = [];     // list of YEARs in data
    let crops = [];     // list of LULC codes
    let colsetHru = new Set(); // available HRU columns
    let colsetRch = new Set(); // available RCH columns
    let colsetSnu = new Set();// available SNU columns
    let current = {
        datasetUrl: els.dataset.value,
        indicatorId: null,
        crop: null,
        aggMode: 'crop',
        avgAllYears: true,
        yearIndex: 0,
        selectedSub: null
    };
    // All data per run_id
    // runsStore[runId] = {
    //   meta: { id, label },
    //   years, crops,
    //   colsetHru, colsetRch, colsetSnu,
    //   hruAnnualData, rchAnnualData, snuAnnualData
    // }
    const runsStore = new Map();
    let selectedRunIds = [];   // run_ids currently selected in the checkbox list
    let mapScenarioIds = [];   // run_ids currently visualised on the map (0–2)
    let runsMeta = [];         // metadata from runs_list.php (id, run_label, run_date)

    // Cached annual per-HRU sums by available columns
    // hruAnnual.key = `${SUB}|${LULC}|${HRU}|${YEAR}`
    // hruAnnual.rows: array of {SUB,LULC,HRU,YEAR, AREAkm2, <col>_sum ...}
    const hruAnnual = {
        data: [],
        // helpers to fetch relevant slices quickly
        bySubYear(sub, year) {
            return this.data.filter(r => r.SUB === +sub && r.YEAR === +year);
        },
        bySubCropYear(sub, crop, year) {
            return this.data.filter(r => r.SUB === +sub && r.LULC === crop && r.YEAR === +year);
        }
    };

    const rchAnnual = {
        data: [],
        bySubYear(sub, year) {
            return this.data.filter(r => r.SUB === +sub && r.YEAR === +year);
        }
    };

    const snuAnnual = {
        data: [],
    };

    let cropLookup = {};

    const COLORWAY = [
        '#636EFA', '#EF553B', '#00CC96', '#AB63FA',
        '#FFA15A', '#19D3F3', '#FF6692', '#B6E880'
    ];

    // ---------- Boot ----------
    wireUI();
    setIdleState();

    // ---------- UI ----------
    function wireUI() {
        els.dataset.addEventListener('change', async () => {
            selectedRunIds = getSelectedRunIdsFromSelect();

            if (!selectedRunIds.length) {
                current.runId = null;
                mapScenarioIds = [];
                updateMapScenarioOptions();

                if (els.mapNote) {
                    els.mapNote.textContent = 'Select at least one scenario.';
                }
                if (els.seriesHint) {
                    els.seriesHint.style.display = 'block';
                    els.seriesHint.textContent = 'Select a scenario, then click a subbasin to load time series.';
                }
                if (els.seriesChart) Plotly.purge(els.seriesChart);
                if (els.cropChart)   Plotly.purge(els.cropChart);

                if (vectorLayer) vectorLayer.changed();
                return;
            }

            // Ensure data is loaded for all selected runs
            await Promise.all(selectedRunIds.map(id => ensureRunLoaded(id)));

            // Keep mapScenarioIds as subset of selectedRunIds
            mapScenarioIds = mapScenarioIds.filter(id => selectedRunIds.includes(id));
            if (!mapScenarioIds.length) {
                mapScenarioIds = [selectedRunIds[0]];
            } else if (mapScenarioIds.length > 2) {
                mapScenarioIds = mapScenarioIds.slice(0, 2);
            }

            // Base run for metrics / crop list / year slider = first map scenario
            const baseRunId = mapScenarioIds[0];
            current.runId = baseRunId;
            activateRun(baseRunId, { preserveCrop: true });

            updateMapScenarioOptions();
            recomputeAndRedraw();
        });

        els.metric.addEventListener('change', () => {
            current.indicatorId = els.metric.value || null;
            updateCropVisibility();
            recomputeAndRedraw();
        });

        els.crop.addEventListener('change', () => {
            current.crop = els.crop.value || null;
            recomputeAndRedraw();
        });

        els.avgAllYears.addEventListener('change', () => {
            current.avgAllYears = els.avgAllYears.checked;
            els.yearSlider.disabled = current.avgAllYears;
            recomputeAndRedraw();
        });

        let rafId = null;
        els.yearSlider.addEventListener('input', () => {
            current.yearIndex = +els.yearSlider.value;
            els.yearLabel.textContent = years[current.yearIndex] ?? '—';
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
            els.mapScenario.addEventListener('change', () => {
                const opts = [...els.mapScenario.options];

                let ids = opts
                    .filter(o => o.selected)
                    .map(o => parseInt(o.value, 10))
                    .filter(v => Number.isFinite(v) && v > 0);

                // Enforce max 2
                if (ids.length > 2) {
                    ids = ids.slice(0, 2);
                    // reflect back in DOM
                    opts.forEach(o => {
                        const id = parseInt(o.value, 10);
                        o.selected = ids.includes(id);
                    });
                }

                // If user deselects all, fall back to first selected run
                if (!ids.length && selectedRunIds.length) {
                    ids = [selectedRunIds[0]];
                    opts.forEach(o => {
                        const id = parseInt(o.value, 10);
                        o.selected = ids.includes(id);
                    });
                }

                mapScenarioIds = ids;

                if (mapScenarioIds.length) {
                    const baseRunId = mapScenarioIds[0];
                    current.runId = baseRunId;
                    activateRun(baseRunId, { preserveCrop: true });
                }

                recomputeAndRedraw();
            });
        }
    }

    // ---------- Data load ----------
    // Load + aggregate all KPI data for a single run_id
    async function loadRunData(runId) {
        const urlHru = `${apiBase}/hru_kpi.php?run_id=${encodeURIComponent(runId)}`;
        const urlRch = `${apiBase}/rch_kpi.php?run_id=${encodeURIComponent(runId)}`;
        const urlSnu = `${apiBase}/snu_kpi.php?run_id=${encodeURIComponent(runId)}`;

        const [hruJson, rchJson, snuJson] = await Promise.all([
            fetch(urlHru).then(r => r.json()),
            fetch(urlRch).then(r => r.json()),
            fetch(urlSnu).then(r => r.json())
        ]).catch(err => {
            console.error('[loadRunData] Failed:', err);
            throw err;
        });

        // ---------- HRU rows ----------
        const rows = (hruJson.rows || []).map(r => ({
            ...r,
            LULC: clean(r.LULC),
            HRU: +r.HRU,
            HRUGIS: +r.HRUGIS,
            SUB: +r.SUB,
            YEAR: +r.YEAR,
            MON: +r.MON,
            AREAkm2: toNum(r.AREAkm2)
        }));
        console.debug(`[loadRunData] HRU rows from DB for run ${runId}:`, rows.length);

        const colsetHru = new Set(Object.keys(rows[0] || {}));

        const hruLookup = new Map();
        for (const r of rows) {
            hruLookup.set(r.HRUGIS, {
                SUB: r.SUB,
                LULC: r.LULC,
                AREAkm2: r.AREAkm2
            });
        }

        // ---------- RCH rows ----------
        const rchRows = (rchJson.rows || []).map(r => ({
            ...r,
            SUB: +r.SUB,
            YEAR: +r.YEAR,
            MON: +r.MON,
            AREAkm2: toNum(r.AREAkm2),
            FLOW_OUTcms: toNum(r.FLOW_OUTcms),
            NO3_OUTkg: toNum(r.NO3_OUTkg),
            SED_OUTtons: toNum(r.SED_OUTtons)
        }));
        console.debug(`[loadRunData] RCH rows from DB for run ${runId}:`, rchRows.length);

        const colsetRch = new Set(Object.keys(rchRows[0] || {}));

        // ---------- SNU rows ----------
        const snuRows = (snuJson.rows || []).map(r => ({
            ...r,
            HRUGIS: +r.HRUGIS,
            YEAR: +r.YEAR,
            // keep raw NO3 / SOL_P / ORG_N / ORG_P / SOL_RSD; we call toNum() later
        }));
        console.debug(`[loadRunData] SNU rows from DB for run ${runId}:`, snuRows.length);

        const colsetSnu = new Set(Object.keys(snuRows[0] || {}));

        // ---------- Years / crops ----------
        const years = [...new Set(rows.map(r => r.YEAR).filter(Number.isFinite))].sort((a, b) => a - b);
        const crops = [...new Set(rows.map(r => r.LULC).filter(Boolean))].sort();

        // ---------- Aggregate SNU -> annual per SUB–CROP–YEAR ----------
        const snuAcc = new Map();  // key: SUB|LULC|YEAR

        for (const r of snuRows) {
            const h = hruLookup.get(+r.HRUGIS);
            if (!h) continue;

            const key = `${h.SUB}|${h.LULC}|${r.YEAR}`;
            let obj = snuAcc.get(key);
            if (!obj) {
                obj = {
                    SUB: h.SUB,
                    LULC: h.LULC,
                    YEAR: r.YEAR,
                    AREAkm2: h.AREAkm2,
                    ORG_N_sum: 0,
                    NO3_sum: 0,
                    SOL_P_sum: 0,
                    ORG_P_sum: 0,
                    SOL_RSD_sum: 0,
                    count: 0
                };
                snuAcc.set(key, obj);
            }

            obj.ORG_N_sum += toNum(r.ORG_N);
            obj.NO3_sum   += toNum(r.NO3);
            obj.SOL_P_sum += toNum(r.SOL_P);
            obj.ORG_P_sum += toNum(r.ORG_P);
            obj.SOL_RSD_sum += toNum(r.SOL_RSD);
            obj.count++;
        }

        const snuAnnualData = [...snuAcc.values()].map(o => ({
            ...o,
            ORG_N_mean: o.ORG_N_sum / o.count,
            NO3_mean:   o.NO3_sum   / o.count,
            SOL_P_mean: o.SOL_P_sum / o.count,
            ORG_P_mean: o.ORG_P_sum / o.count,
            SOL_RSD_mean: o.SOL_RSD_sum / o.count
        }));

        // ---------- Pre-aggregate HRU -> annual per HRU (SUB–LULC–HRU–YEAR) ----------
        // Columns needed by HRU-based indicators
        const neededHruCols = new Set();
        for (const def of indicators) {
            if (def.disabled) continue;
            (def.needs || []).forEach(c => neededHruCols.add(c));
        }

        const keepHruCols = [...neededHruCols].filter(c => colsetHru.has(c));
        const maxCols = new Set(['YLDt_ha', 'BIOMt_ha']); // also track annual max for these

        const keyHru = r => `${r.SUB}|${r.LULC}|${r.HRU}|${r.YEAR}`;
        const accHru = new Map();

        for (const r of rows) {
            if (!Number.isFinite(r.YEAR) || !Number.isFinite(r.MON)) continue;
            const k = keyHru(r);
            let obj = accHru.get(k);
            if (!obj) {
                obj = {
                    SUB: r.SUB,
                    LULC: r.LULC,
                    HRU: r.HRU,
                    YEAR: r.YEAR,
                    AREAkm2: r.AREAkm2
                };
                for (const c of keepHruCols) {
                    obj[`${c}_sum`] = 0;
                    if (maxCols.has(c)) {
                        obj[`${c}_max`] = Number.NEGATIVE_INFINITY;
                    }
                }
                accHru.set(k, obj);
            }

            for (const c of keepHruCols) {
                const v = toNum(r[c]);
                if (!Number.isFinite(v)) continue;

                obj[`${c}_sum`] += v;
                if (maxCols.has(c)) {
                    obj[`${c}_max`] = Math.max(obj[`${c}_max`], v);
                }
            }
        }

        // Normalise "no data" max values
        for (const obj of accHru.values()) {
            for (const c of ['YLDt_ha', 'BIOMt_ha']) {
                const kMax = `${c}_max`;
                if (kMax in obj && obj[kMax] === Number.NEGATIVE_INFINITY) {
                    obj[kMax] = NaN;
                }
            }
        }

        const hruAnnualData = [...accHru.values()];

        // ---------- Pre-aggregate RCH -> annual per SUB–YEAR ----------
        const neededRchCols = new Set();
        for (const def of indicators) {
            if (def.disabled) continue;
            (def.needsRch || []).forEach(c => neededRchCols.add(c));
        }

        const keepRchCols = [...neededRchCols].filter(c => colsetRch.has(c));

        const keyRch = r => `${r.SUB}|${r.YEAR}`;
        const accRch = new Map();

        for (const r of rchRows) {
            if (!Number.isFinite(r.YEAR) || !Number.isFinite(r.MON)) continue;
            const k = keyRch(r);
            let obj = accRch.get(k);
            if (!obj) {
                obj = {
                    SUB: r.SUB,
                    YEAR: r.YEAR
                };
                for (const c of keepRchCols) {
                    obj[`${c}_sum`] = 0;
                }
                accRch.set(k, obj);
            }

            for (const c of keepRchCols) {
                const v = toNum(r[c]);
                if (!Number.isFinite(v)) continue;
                obj[`${c}_sum`] += v;
            }
        }

        const rchAnnualData = [...accRch.values()];

        // ---------- Return structured run data ----------
        return {
            id: runId,
            // raw monthly rows (optional but handy)
            rows,
            rchRows,
            snuRows,
            // meta
            years,
            crops,
            colsetHru,
            colsetRch,
            colsetSnu,
            // annual aggregates used by indicators
            hruAnnualData,
            rchAnnualData,
            snuAnnualData
        };
    }

    async function bootstrapFromApi() {
        await loadCropLookup();

        const [subGJ, rivGJ] = await Promise.all([
            fetch(subbasinGeoUrl).then(r => r.json()),
            fetch(riversGeoUrl).then(r => r.json())
        ]);
        buildMap(subGJ, rivGJ);

        const runIds = await loadRuns();

        // --- No runs: keep map, show friendly messages, but DON'T throw
        if (!runIds.length) {
            selectedRunIds = [];
            mapScenarioIds = [];
            current.runId = null;

            updateMapScenarioOptions();

            if (els.mapNote) {
                els.mapNote.textContent = 'No model runs found for this study area.';
            }
            if (els.seriesHint) {
                els.seriesHint.style.display = 'block';
                els.seriesHint.textContent = 'No scenarios available for this study area.';
            }
            if (els.seriesChart) Plotly.purge(els.seriesChart);
            if (els.cropChart)   Plotly.purge(els.cropChart);

            return; // <- important: exit without error
        }

        const firstId = runIds[0];

        // Mark first as selected in the checkbox list
        if (els.dataset) {
            const box = els.dataset.querySelector(`input.dataset-checkbox[data-run-id="${firstId}"]`);
            if (box) box.checked = true;
        }

        selectedRunIds = [firstId];
        mapScenarioIds = [firstId];
        current.runId = firstId;

        updateMapScenarioOptions();

        await ensureRunLoaded(firstId);
        activateRun(firstId);

        recomputeAndRedraw();
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

    async function loadRuns() {
        const url = `${apiBase}/runs_list.php?study_area_id=${encodeURIComponent(currentStudyAreaId)}`;
        const res = await fetch(url);
        if (!res.ok) throw new Error(`runs_list HTTP ${res.status}`);
        const json = await res.json();
        const runs = json.runs || [];

        runsMeta = runs;  // store for later lookup (labels, etc.)

        if (!runs.length) {
            els.dataset.innerHTML = '<div class="text-muted small">No runs found for this study area.</div>';
            return [];
        }

        const defaultRuns = runs.filter(r => r.is_default);
        const userRuns    = runs.filter(r => !r.is_default);

        let html = '';

        if (defaultRuns.length) {
            html += '<div class="mb-2">';
            html += '<div class="small fw-semibold text-muted mb-1">Default datasets</div>';
            html += defaultRuns.map(r => {
                const label = escHtml(r.run_label);
                const id    = `run-${r.id}`;
                return `
                <div class="form-check mb-1">
                    <input class="form-check-input dataset-checkbox" type="checkbox"
                           id="${id}" data-run-id="${r.id}">
                    <label class="form-check-label" for="${id}">${label}</label>
                </div>`;
            }).join('');
            html += '</div>';
        }

        if (userRuns.length) {
            html += '<div class="mb-2">';
            html += '<div class="small fw-semibold text-muted mb-1">User-created model runs</div>';
            html += userRuns.map(r => {
                const labelParts = [r.run_label];
                if (r.run_date) labelParts.push(`(${r.run_date})`);
                const label = escHtml(labelParts.join(' '));
                const id    = `run-${r.id}`;
                return `
                <div class="form-check mb-1">
                    <input class="form-check-input dataset-checkbox" type="checkbox"
                           id="${id}" data-run-id="${r.id}">
                    <label class="form-check-label" for="${id}">${label}</label>
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

        subFeatures = geoFmt.readFeatures(subGJ, {
            dataProjection: 'EPSG:3857',
            featureProjection: 'EPSG:3857'
        }) || [];
        console.debug('[SUB] features:', subFeatures.length);

        let rivFeatures = geoFmt.readFeatures(rivGJ, {
            dataProjection: 'EPSG:3857',
            featureProjection: 'EPSG:3857'
        }) || [];
        console.debug('[RIV] features:', rivFeatures.length);

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
                const hit = map.forEachFeatureAtPixel(evt.pixel, (f) => f); // take first feature on any vector layer
                if (hit) {
                    const sid = getProp(hit, 'Subbasin');
                    const v = valueForSub(sid);
                    els.mapInfo.innerHTML =
                        `<b>Subbasin ${escHtml(sid)}</b><br>` +
                        `<b>${escHtml(currentIndicator()?.name||'')}</b>: ${fmt(v)} ${unitText()}<br>` +
                        `<span class="mono">${escHtml(timeLabelText())}</span>`;
                    map.getTargetElement().style.cursor = 'pointer';
                } else {
                    els.mapInfo.textContent = 'Hover or click a subbasin';
                    map.getTargetElement().style.cursor = '';
                }
            });

            map.on('singleclick', (evt) => {
                const hit = map.forEachFeatureAtPixel(evt.pixel, (f) => f);
                if (hit) {
                    current.selectedSub = getProp(hit, 'Subbasin');
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
        cachedRange = vals.length ? [Math.min(...vals), Math.max(...vals)] : [0,0];

        // legend + map note
        updateLegend();
        const base = `${currentIndicator()?.name || ''}${(current.aggMode === 'crop') ? cropSuffix() : ' — all crops'}`;
        els.mapNote.textContent = `${base}${describeMapScenario()} — ${timeLabelText()}`;

        // restyle map
        vectorLayer.changed();

        // refresh timeseries if a sub is selected (skip heavy redraw on slider drag if fast=true)
        if (!fast) drawSeries();
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

    function computeIndicatorValue(def, sub, year) {
        // Area helper (ha)
        const areaHa = r => (Number.isFinite(r.AREAkm2) ? r.AREAkm2 * 100 : NaN);

        if (current.aggMode === 'sub' && def.requiresCrop) {
            // Weighted mean across all crops for this sub+year
            let wsum = 0, vsum = 0;
            for (const c of crops) {
                const rows = hruAnnual.bySubCropYear(sub, c, year);
                if (!rows.length) continue;
                // area for this crop in this sub-year
                let area = 0; for (const r of rows) area += areaHa(r);
                const v = def.calc({
                    hruAnnual,
                    rchAnnual,
                    snuAnnual,
                    areaHa,
                    sub,
                    crop: c,
                    year
                });
                if (Number.isFinite(v) && area > 0) { wsum += area; vsum += v * area; }
            }
            return wsum ? vsum / wsum : NaN;
        }

        // Default path: crop-specific if needed, otherwise sub-only
        const crop = def.requiresCrop ? current.crop : undefined;

        return def.calc({
            hruAnnual,
            rchAnnual,
            snuAnnual,
            areaHa,
            sub,
            crop,
            year
        });
    }

    function valueForSubRun(runId, sub) {
        const def = currentIndicator();
        if (!def) return NaN;

        const rd = runsStore.get(runId);
        if (!rd) return NaN;

        if (current.avgAllYears) {
            // average across all years available in this run
            const vals = rd.years
                .map(Y => computeIndicatorValueForRun(def, rd, sub, Y))
                .filter(Number.isFinite);
            if (!vals.length) return NaN;
            return vals.reduce((a, b) => a + b, 0) / vals.length;
        }

        // specific year: use the year index from the *active* run
        const year = years[current.yearIndex];
        if (!Number.isFinite(year)) return NaN;

        return computeIndicatorValueForRun(def, rd, sub, year);
    }

    // ---------- Time series for clicked sub ----------
    function drawSeries() {
        const def = currentIndicator();
        if (!def || !current.selectedSub || !selectedRunIds.length) {
            els.seriesHint.style.display = 'block';
            Plotly.purge(els.seriesChart);
            Plotly.purge(els.cropChart);
            return;
        }
        els.seriesHint.style.display = 'none';

        const traces = [];

        for (const runId of selectedRunIds) {
            const rd = runsStore.get(runId);
            if (!rd) continue;

            const meta = runsMeta.find(r => r.id === runId);
            const runLabel = meta ? meta.run_label : `Run ${runId}`;

            const xs = rd.years.slice();
            const ys = rd.years.map(Y => {
                const v = computeIndicatorValueForRun(def, rd, current.selectedSub, Y);
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
                hovertemplate: `${runLabel}<br>%{x}<br>%{y:.3f}<extra></extra>`
            });
        }

        Plotly.newPlot(els.seriesChart, traces, {
            margin: { t: 30, r: 10, b: 40, l: 55 },
            title: `Subbasin ${current.selectedSub}${cropSuffix()} — ${def.name}`,
            xaxis: { title: 'Year' },
            yaxis: {
                title: `${def.name}${unitText() ? ` (${unitText()})` : ''}`
            },
            showlegend: true,
            colorway: COLORWAY
        }, { displayModeBar: false, responsive: true });

        drawCropChart(def);
    }

    // ---------- UI population ----------
    function isIndicatorAvailable(def) {
        if (def?.disabled) return false;

        const needsHru = def.needs || [];
        const needsRch = def.needsRch || [];
        const needsSnu = def.needsSnu || [];

        const okHru = needsHru.length === 0 || needsHru.every(c => colsetHru.has(c));
        const okRch = needsRch.length === 0 || needsRch.every(c => colsetRch.has(c));
        const okSnu = needsSnu.length === 0 || needsSnu.every(c => colsetSnu.has(c));

        return okHru && okRch && okSnu;
    }

    function populateMetricsCombined() {
        // mark availability per indicator, group by sector
        const defs = indicators.map(d => ({ ...d, enabled: isIndicatorAvailable(d) }));
        const bySector = new Map();
        for (const d of defs) {
            if (!bySector.has(d.sector)) bySector.set(d.sector, []);
            bySector.get(d.sector).push(d);
        }
        const html = [...bySector.entries()].sort(([a],[b]) => a.localeCompare(b))
            .map(([sector, list]) => {
                const opts = list.map(d =>
                    `<option value="${escAttr(d.id)}" ${!d.enabled ? 'disabled' : ''}>
                        ${escHtml(d.name)}${!d.enabled ? ' — (not available)' : ''}
                    </option>`).join('');
                return `<optgroup label="${escAttr(sector)}">${opts}</optgroup>`;
            }).join('');
        els.metric.innerHTML = html;
        // pick first enabled
        const firstEnabled = defs.find(d => d.enabled);
        if (firstEnabled) els.metric.value = firstEnabled.id;
        current.indicatorId = els.metric.value || null;
        els.indicatorHelp.textContent = currentIndicator()?.description || '';
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

        // Clamp current.yearIndex to the new range
        if (current.yearIndex < 0 || current.yearIndex >= years.length) {
            current.yearIndex = 0;
        }

        els.yearSlider.value = current.yearIndex;
        els.yearMin.textContent   = years[0] ?? '—';
        els.yearMax.textContent   = years[years.length - 1] ?? '—';
        els.yearLabel.textContent = years[current.yearIndex] ?? '—';

        // Important: use current.avgAllYears as the source of truth
        els.yearSlider.disabled = current.avgAllYears;
    }

    function updateCropVisibility() {
        const def = currentIndicator();
        const show = !!def?.requiresCrop && current.aggMode !== 'sub';
        els.cropGroup.style.display = show ? 'block' : 'none';
        els.indicatorHelp.textContent = def?.description || '';
    }

    // ---------- Helpers ----------
    function setIdleState() {
        if (els.dataset) {
            els.dataset.innerHTML =
                '<div class="text-muted small">Select a study area first.</div>';
        }

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
            els.seriesHint.style.display = 'block';
            els.seriesHint.textContent = 'Select a study area, then click a subbasin to load time series.';
        }
        if (els.seriesChart) Plotly.purge(els.seriesChart);
        if (els.cropChart)   Plotly.purge(els.cropChart);
    }

    function getSelectedRunIdsFromSelect() {
        if (!els.dataset) return [];
        const boxes = els.dataset.querySelectorAll('input.dataset-checkbox[data-run-id]');
        return [...boxes]
            .filter(b => b.checked)
            .map(b => parseInt(b.dataset.runId, 10))
            .filter(v => Number.isFinite(v) && v > 0);
    }

    async function ensureRunLoaded(runId) {
        if (runsStore.has(runId)) return;

        const runData = await loadRunData(runId);
        runsStore.set(runId, runData);
    }

    function updateMapScenarioOptions() {
        if (!els.mapScenario) return;

        const options = selectedRunIds
            .map(id => {
                const meta = runsMeta.find(r => r.id === id);
                if (!meta) return null;
                const labelParts = [meta.run_label];
                if (meta.run_date) labelParts.push(`(${meta.run_date})`);
                return {
                    id,
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
            return `<option value="${o.id}" ${selected}>${escHtml(o.label)}</option>`;
        }).join('');

        els.mapScenario.innerHTML = html;
    }

    function activateRun(runId, { preserveCrop = false } = {}) {
        const rd = runsStore.get(runId);
        if (!rd) return;

        const prevCrop = preserveCrop ? current.crop : null;

        // Copy to globals used everywhere else:
        years = rd.years.slice();
        crops = rd.crops.slice();

        colsetHru = new Set(rd.colsetHru);
        colsetRch = new Set(rd.colsetRch);
        colsetSnu = new Set(rd.colsetSnu);

        hruAnnual.data = rd.hruAnnualData;
        rchAnnual.data = rd.rchAnnualData;
        snuAnnual.data = rd.snuAnnualData;

        current.runId = runId;

        // Keep avgAllYears UI in sync with state
        if (els.avgAllYears) {
            els.avgAllYears.checked = current.avgAllYears;
        }

        // Rebuild crops but try to keep the previous selection if possible
        populateCrops(prevCrop);

        // Rebuild slider for this run while respecting current.avgAllYears + current.yearIndex
        initYearSlider();

        // Rebuild metric list (availability may change per run)
        populateMetricsCombined();
    }

    function computeIndicatorValueForRun(def, rd, sub, year, cropOverride = null) {
        const areaHa = r => (Number.isFinite(r.AREAkm2) ? r.AREAkm2 * 100 : NaN);

        const hruWrapper = {
            data: rd.hruAnnualData,
            bySubYear(s, y) {
                return this.data.filter(r => r.SUB === +s && r.YEAR === y);
            },
            bySubCropYear(s, c, y) {
                return this.data.filter(r => r.SUB === +s && r.LULC === c && r.YEAR === y);
            }
        };

        const rchWrapper = {
            data: rd.rchAnnualData,
            bySubYear(s, y) {
                return this.data.filter(r => r.SUB === +s && r.YEAR === y);
            }
        };

        const snuWrapper = { data: rd.snuAnnualData };

        // --- Aggregated across crops (aggMode === 'sub' and no explicit crop override)
        if (current.aggMode === 'sub' && def.requiresCrop && !cropOverride) {
            let wsum = 0, vsum = 0;
            for (const c of rd.crops) {
                const rows = hruWrapper.bySubCropYear(sub, c, year);
                if (!rows.length) continue;

                let area = 0; for (const r of rows) area += areaHa(r);

                const v = def.calc({
                    hruAnnual: hruWrapper,
                    rchAnnual: rchWrapper,
                    snuAnnual: snuWrapper,
                    areaHa,
                    sub,
                    crop: c,
                    year
                });

                if (Number.isFinite(v) && area > 0) {
                    wsum += area;
                    vsum += v * area;
                }
            }
            return wsum ? vsum / wsum : NaN;
        }

        // --- Crop-specific or crop-agnostic
        const crop = def.requiresCrop ? (cropOverride || current.crop) : undefined;

        return def.calc({
            hruAnnual: hruWrapper,
            rchAnnual: rchWrapper,
            snuAnnual: snuWrapper,
            areaHa,
            sub,
            crop,
            year
        });
    }

    function drawCropChart(def) {
        const yr = current.avgAllYears ? null : (years[current.yearIndex] ?? null);
        const traces = [];

        for (const runId of selectedRunIds) {
            const rd = runsStore.get(runId);
            if (!rd) continue;

            const meta = runsMeta.find(r => r.id === runId);
            const runLabel = meta ? meta.run_label : `Run ${runId}`;

            const x = [];
            const y = [];

            for (const c of rd.crops) {
                const yearsToUse = (yr == null ? rd.years : [yr]);
                const vals = yearsToUse.map(Y =>
                    computeIndicatorValueForRun(def, rd, current.selectedSub, Y, c)
                ).filter(Number.isFinite);

                if (!vals.length) continue;
                x.push(displayCrop(c));
                y.push(vals.reduce((a, b) => a + b, 0) / vals.length);
            }

            if (!x.length) continue;

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
            title: `Crop breakdown — Subbasin ${current.selectedSub} — ${def.name}`,
            xaxis: { title: 'Crop', tickangle: -30, automargin: true },
            yaxis: {
                title: `${def.name}${unitText() ? ` (${unitText()})` : ''}`
            },
            barmode: 'group',
            showlegend: false,
            colorway: COLORWAY
        }, { displayModeBar: false, responsive: true });
    }

    function currentIndicator() {
        return indicators.find(d => d.id === current.indicatorId) || null;
    }
    function cropSuffix() {
        const def = currentIndicator();
        return def?.requiresCrop && current.crop
            ? ` — ${displayCrop(current.crop)}`
            : '';
    }
    function unitText() {
        return currentIndicator()?.unit || '';
    }
    function timeLabelText() {
        return current.avgAllYears ? 'Average across all years' : `Year ${years[current.yearIndex] ?? ''}`;
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

    function scenarioLabel(runId) {
        const meta = runsMeta.find(r => r.id === runId);
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
        if (!Number.isFinite(+newStudyAreaId) || +newStudyAreaId <= 0) return;

        currentStudyAreaId = +newStudyAreaId;
        subbasinGeoUrl = `/api/study_area_subbasins_geo.php?study_area_id=${encodeURIComponent(currentStudyAreaId)}`;
        riversGeoUrl   = `/api/study_area_reaches_geo.php?study_area_id=${encodeURIComponent(currentStudyAreaId)}`;

        // reset state & UI bits for new area
        selectedRunIds = [];
        mapScenarioIds = [];
        current.runId = null;
        current.selectedSub = null;

        if (els.dataset) {
            els.dataset.innerHTML = '<div class="text-muted small">Loading runs…</div>';
        }
        if (els.seriesHint) {
            els.seriesHint.style.display = 'block';
            els.seriesHint.textContent = 'Loading scenarios for this study area…';
        }
        if (els.seriesChart) Plotly.purge(els.seriesChart);
        if (els.cropChart)   Plotly.purge(els.cropChart);

        try {
            await bootstrapFromApi();
        } catch (err) {
            console.error('[switchStudyArea] failed:', err);
            if (els.mapNote) els.mapNote.textContent = 'Failed to load runs or data for this study area.';
        }
    }

    // Expose controller
    return {
        switchStudyArea,
    };
}