// assets/js/egypt/subbasin-dashboard.js
export function initSubbasinDashboard({ els, subbasinGeoUrl, riversGeoUrl, indicators,
                                          studyArea = 'egypt', apiBase = '/api'}) {
    // ---------- State ----------
    let map, subLayer, subSource, subFeatures = [];
    let vectorLayer; // stylable choropleth layer (subbasins)
    let rivLayer, rivSource;   // rivers
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
        byHruYear(gisnum, year) {
            return this.data.filter(r => r.HRUGIS === +gisnum && r.YEAR === +year);
        }
    };

    let cropLookup = {};

    // ---------- Boot ----------
    wireUI();
    bootstrapFromApi().catch(err => {
        console.error('[BOOT] Failed to bootstrap dashboard:', err);
        if (els.mapNote) els.mapNote.textContent = 'Failed to load runs or data.';
    });

    // ---------- UI ----------
    function wireUI() {
        els.dataset.addEventListener('change', async () => {
            const rid = +(els.dataset.value || 0);
            current.runId = Number.isFinite(rid) && rid > 0 ? rid : null;
            if (current.runId) {
                await loadAll(current.runId);
            }
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
    }

    // ---------- Data load ----------
    async function loadAll(runId) {
        const urlHru = `${apiBase}/hru_kpi.php?run_id=${encodeURIComponent(runId)}`;
        const urlRch = `${apiBase}/rch_kpi.php?run_id=${encodeURIComponent(runId)}`;
        const urlSnu = `${apiBase}/snu_kpi.php?run_id=${encodeURIComponent(runId)}`;

        const [hruJson, rchJson, snuJson, subGJ, rivGJ] = await Promise.all([
            fetch(urlHru).then(r => r.json()),
            fetch(urlRch).then(r => r.json()),
            fetch(urlSnu).then(r => r.json()),
            fetch(subbasinGeoUrl).then(r => r.json()),
            fetch(riversGeoUrl).then(r => r.json())
        ]).catch(err => {
            console.error('[LOAD] Failed:', err);
            throw err;
        });

        rows = (hruJson.rows || []).map(r => ({
            ...r,
            LULC: clean(r.LULC),
            HRU: +r.HRU,
            HRUGIS: +r.HRUGIS,
            SUB: +r.SUB,
            YEAR: +r.YEAR,
            MON: +r.MON,
            AREAkm2: toNum(r.AREAkm2)
        }));
        console.debug('[LOAD] HRU rows from DB:', rows.length);

        colsetHru = new Set(Object.keys(rows[0] || {}));

        const hruLookup = new Map();
        for (const r of rows) {
            hruLookup.set(r.HRUGIS, {
                SUB: r.SUB,
                LULC: r.LULC,
                AREAkm2: r.AREAkm2
            });
        }

        // ----- RCH rows -----
        rchRows = (rchJson.rows || []).map(r => ({
            ...r,
            SUB: +r.SUB,
            YEAR: +r.YEAR,
            MON: +r.MON,
            AREAkm2: toNum(r.AREAkm2),
            FLOW_OUTcms: toNum(r.FLOW_OUTcms),
            NO3_OUTkg: toNum(r.NO3_OUTkg),
            SED_OUTtons: toNum(r.SED_OUTtons)
        }));
        console.debug('[LOAD] RCH rows from DB:', rchRows.length);

        colsetRch = new Set(Object.keys(rchRows[0] || {}));

        // Years / crops
        years = [...new Set(rows.map(r => r.YEAR).filter(Number.isFinite))].sort((a,b) => a-b);
        crops = [...new Set(rows.map(r => r.LULC).filter(Boolean))].sort();

        snuRows = (snuJson.rows || []).map(r => ({
            ...r,
            HRUGIS: +r.HRUGIS,
            YEAR: +r.YEAR,
            // keep raw NO3 / SOL_P / ORG_N / ORG_P / SOL_RSD; we call toNum() later
        }));
        console.debug('[LOAD] SNU rows from DB:', snuRows.length);

        colsetSnu = new Set(Object.keys(snuRows[0] || {}));

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
            obj.NO3_sum += toNum(r.NO3);
            obj.SOL_P_sum += toNum(r.SOL_P);
            obj.ORG_P_sum += toNum(r.ORG_P);
            obj.SOL_RSD_sum += toNum(r.SOL_RSD);
            obj.count++;
        }

        snuAnnual.data = [...snuAcc.values()].map(o => ({
            ...o,
            ORG_N_mean: o.ORG_N_sum / o.count,
            NO3_mean: o.NO3_sum / o.count,
            SOL_P_mean: o.SOL_P_sum / o.count,
            ORG_P_mean: o.ORG_P_sum / o.count,
            SOL_RSD_mean: o.SOL_RSD_sum / o.count
        }));

        // ---------- Pre-aggregate HRU to annual per-HRU ----------
        // Columns needed by HRU-based indicators
        const neededHruCols = new Set();
        for (const def of indicators) {
            if (def.disabled) continue;
            (def.needs || []).forEach(c => neededHruCols.add(c));
        }

        const keepHruCols = [...neededHruCols].filter(c => colsetHru.has(c));
        const maxCols = new Set(['YLDt_ha', 'BIOMt_ha']); // we also track annual max for these

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

        hruAnnual.data = [...accHru.values()];

        // ---------- Pre-aggregate RCH to annual per SUB ----------
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

        rchAnnual.data = [...accRch.values()];

        // OpenLayers map (rebuild once per dataset load)
        buildMap(subGJ, rivGJ);

        // Populate UI (sectors, indicators, crops, years)
        populateMetricsCombined();
        populateCrops();
        initYearSlider();

        // Apply current toggle visibility
        if (els.toggleRivers) rivLayer.setVisible(els.toggleRivers.checked);
        if (els.toggleSubbasins) {
            const vis = els.toggleSubbasins.checked;
            subLayer.setVisible(vis);
            vectorLayer.setVisible(vis);
        }

        // Re-apply opacities after reloading layers
        if (els.opacitySubbasins && subLayer && vectorLayer) {
            const a = Math.max(0, Math.min(1, (+els.opacitySubbasins.value || 0) / 100));
            subLayer.setOpacity(a); vectorLayer.setOpacity(a);
        }
        if (els.opacityRivers && rivLayer) {
            const a = Math.max(0, Math.min(1, (+els.opacityRivers.value || 0) / 100));
            rivLayer.setOpacity(a);
        }

        // Defaults
        current.indicatorId = els.metric.value || null;
        current.crop = (els.crop.options[0] && els.crop.options[0].value) || null;
        current.avgAllYears = els.avgAllYears.checked;
        current.yearIndex = +els.yearSlider.value;

        // First draw
        recomputeAndRedraw();
    }

    async function bootstrapFromApi() {
        // Load crop names (global)
        await loadCropLookup();

        // Load runs for this study area and populate the dropdown
        const defaultRunId = await loadRuns();
        if (!defaultRunId) {
            throw new Error('No runs found for this study area');
        }

        current.runId = defaultRunId;
        els.dataset.value = String(defaultRunId);

        // Now load HRU data for that run
        await loadAll(current.runId);
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
        const url = `${apiBase}/runs_list.php?study_area=${encodeURIComponent(studyArea)}`;
        const res = await fetch(url);
        if (!res.ok) throw new Error(`runs_list HTTP ${res.status}`);
        const json = await res.json();
        const runs = json.runs || [];

        if (!runs.length) {
            els.dataset.innerHTML = '';
            return null;
        }

        const defaultRuns = runs.filter(r => r.is_default);
        const userRuns    = runs.filter(r => !r.is_default); // visibility='public' only, by API

        let optionsHtml = '';

        // 1) Default datasets – no date in label
        if (defaultRuns.length) {
            optionsHtml += '<optgroup label="Default datasets">';
            optionsHtml += defaultRuns.map(r =>
                `<option value="${r.id}">${escHtml(r.run_label)}</option>`
            ).join('');
            optionsHtml += '</optgroup>';
        }

        // 2) User-created runs – include date if available
        if (userRuns.length) {
            optionsHtml += '<optgroup label="User-created model runs">';
            optionsHtml += userRuns.map(r => {
                const labelParts = [r.run_label];
                if (r.run_date) labelParts.push(`(${r.run_date})`);
                const label = labelParts.join(' ');
                return `<option value="${r.id}">${escHtml(label)}</option>`;
            }).join('');
            optionsHtml += '</optgroup>';
        }

        els.dataset.innerHTML = optionsHtml;

        // Choose default run: first default, else first user run
        const defaultRun = defaultRuns[0] || userRuns[0] || null;
        return defaultRun ? defaultRun.id : null;
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

        // Subbasins: EPSG:4326 -> display 3857
        subFeatures = geoFmt.readFeatures(subGJ, {
            dataProjection: 'EPSG:4326',
            featureProjection: 'EPSG:3857'
        }) || [];
        console.debug('[SUB] features:', subFeatures.length);

        // Rivers: EPSG:32636 -> display 3857 (fallback to 4326 if needed)
        let rivFeatures = [];
        try {
            rivFeatures = geoFmt.readFeatures(rivGJ, {
                dataProjection: 'EPSG:32636',
                featureProjection: 'EPSG:3857'
            }) || [];
        } catch (e) {
            console.warn('[RIV] Failed as EPSG:32636, retry as EPSG:4326:', e);
            try {
                rivFeatures = geoFmt.readFeatures(rivGJ, {
                    dataProjection: 'EPSG:4326',
                    featureProjection: 'EPSG:3857'
                }) || [];
            } catch (e2) {
                console.error('[RIV] Failed as EPSG:4326 too:', e2);
                rivFeatures = [];
            }
        }
        console.debug('[RIV] features:', rivFeatures.length);

        // Sources
        subSource = new ol.source.Vector({ features: subFeatures });
        rivSource = new ol.source.Vector({ features: rivFeatures });

        // Layers
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

        if (!map) {
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
                const hit = map.forEachFeatureAtPixel(evt.pixel, (f, layer) => (layer === vectorLayer ? f : null));
                if (hit) {
                    const sid = getProp(hit, 'Subbasin');
                    const v = valueForSub(sid);
                    els.mapInfo.innerHTML = `<b>Subbasin ${escHtml(sid)}</b><br><b>${escHtml(currentIndicator()?.name||'')}</b>: ${fmt(v)} ${unitText()}<br><span class="mono">${escHtml(timeLabelText())}</span>`;
                    map.getTargetElement().style.cursor = 'pointer';
                } else {
                    els.mapInfo.textContent = 'Hover or click a subbasin';
                    map.getTargetElement().style.cursor = '';
                }
            });

            map.on('singleclick', (evt) => {
                const hit = map.forEachFeatureAtPixel(evt.pixel, (f, layer) => (layer === vectorLayer ? f : null));
                if (hit) {
                    current.selectedSub = getProp(hit, 'Subbasin');
                    drawSeries();
                    vectorLayer.changed();
                }
            });
        } else {
            subLayer.setSource(subSource);
            vectorLayer.setSource(subSource);
            rivLayer.setSource(rivSource);
            vectorLayer.changed();
        }

        // Fit to union extent (subbasins + rivers)
        const extAll = ol.extent.createEmpty();
        const subExt = subSource.getExtent();
        if (subExt && subExt.every(Number.isFinite)) ol.extent.extend(extAll, subExt);
        const rivExt = rivSource.getExtent();
        if (rivExt && rivExt.every(Number.isFinite)) ol.extent.extend(extAll, rivExt);
        if (!ol.extent.isEmpty(extAll)) {
            map.getView().fit(extAll, { padding: [18,18,18,18], duration: 250, maxZoom: 9 });
        }

        // Apply toggles immediately
        if (els.toggleRivers) rivLayer.setVisible(els.toggleRivers.checked);
        if (els.toggleSubbasins) {
            const vis = els.toggleSubbasins.checked;
            subLayer.setVisible(vis);
            vectorLayer.setVisible(vis);
        }

        // Apply toggles immediately
        if (els.toggleRivers) rivLayer.setVisible(els.toggleRivers.checked);
        if (els.toggleSubbasins) {
            const vis = els.toggleSubbasins.checked;
            subLayer.setVisible(vis);
            vectorLayer.setVisible(vis);
        }
        // Apply current opacity slider values
        if (els.opacitySubbasins) {
            const a = Math.max(0, Math.min(1, (+els.opacitySubbasins.value || 0) / 100));
            subLayer.setOpacity(a); vectorLayer.setOpacity(a);
            if (els.opacitySubbasinsVal) els.opacitySubbasinsVal.textContent = `${Math.round(a*100)}%`;
        }
        if (els.opacityRivers) {
            const a = Math.max(0, Math.min(1, (+els.opacityRivers.value || 0) / 100));
            rivLayer.setOpacity(a);
            if (els.opacityRiversVal) els.opacityRiversVal.textContent = `${Math.round(a*100)}%`;
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
        els.mapNote.textContent =
            `${currentIndicator()?.name || ''}${(current.aggMode==='crop') ? cropSuffix() : ' — all crops'} — ${timeLabelText()}`;

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
        // average across all years?
        if (current.avgAllYears) {
            const arr = years.map(y => computeIndicatorValue(def, +sub, y)).filter(Number.isFinite);
            if (!arr.length) return NaN;
            return arr.reduce((a,b)=>a+b,0) / arr.length;
        }
        // specific year
        const y = years[current.yearIndex];
        return computeIndicatorValue(def, +sub, y);
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

    // ---------- Time series for clicked sub ----------
    function drawSeries() {
        const def = currentIndicator();
        if (!def || !current.selectedSub) {
            els.seriesHint.style.display = 'block';
            Plotly.purge(els.seriesChart);
            Plotly.purge(els.cropChart);
            return;
        }
        els.seriesHint.style.display = 'none';

        // --- time series (left)
        const x = years.slice();
        const y = years.map(Y => {
            const v = computeIndicatorValue(def, current.selectedSub, Y);
            return Number.isFinite(v) ? v : null; // ensure gaps are null
        });

        Plotly.newPlot(els.seriesChart, [{
            type: 'scatter',
            mode: 'lines',
            connectgaps: true,
            line: { width: 2 },
            x,
            y,
            hovertemplate: '%{x}<br>%{y:.3f}<extra></extra>'
        }], {
            margin: { t: 30, r: 10, b: 40, l: 55 },
            title: `Subbasin ${current.selectedSub}${cropSuffix()} — ${def.name}`,
            xaxis: { title: 'Year' },
            yaxis: { title: `${def.name}${unitText() ? ` (${unitText()})` : ''}` },
            showlegend: false
        }, { displayModeBar: false, responsive: true });

        // --- crop breakdown (right)
        // Compute one value per crop for either the selected year or average across years
        const yr = current.avgAllYears ? null : years[current.yearIndex];
        const x2 = [];
        const y2 = [];

        for (const c of crops) {
            const vals = (yr == null ? years : [yr]).map(Y =>
                def.requiresCrop
                    ? def.calc({
                        hruAnnual,
                        rchAnnual,
                        snuAnnual,
                        sub: current.selectedSub,
                        crop: c,
                        year: Y,
                        areaHa: r => (Number.isFinite(r.AREAkm2) ? r.AREAkm2 * 100 : NaN)
                    })
                    : def.calc({
                        hruAnnual,
                        rchAnnual,
                        snuAnnual,
                        sub: current.selectedSub,
                        year: Y,
                        areaHa: r => (Number.isFinite(r.AREAkm2) ? r.AREAkm2 * 100 : NaN)
                    })
            ).filter(Number.isFinite);

            if (!vals.length) continue;
            x2.push(displayCrop(c));
            y2.push(vals.reduce((a, b) => a + b, 0) / vals.length);
        }

        if (x2.length) {
            Plotly.newPlot(els.cropChart, [{
                type: 'bar',
                x: x2,
                y: y2,
                hovertemplate: '%{x}<br>%{y:.3f}<extra></extra>'
            }], {
                margin: { t: 30, r: 10, b: 80, l: 55 },
                title: `Crop breakdown — Subbasin ${current.selectedSub} — ${def.name}`,
                xaxis: { title: 'Crop', tickangle: -30, automargin: true },
                yaxis: { title: `${def.name}${unitText() ? ` (${unitText()})` : ''}` },
                showlegend: false
            }, { displayModeBar: false, responsive: true });
        } else {
            Plotly.purge(els.cropChart);
        }
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

    function populateCrops() {
        els.crop.innerHTML = crops
            .map(c => `<option value="${escAttr(c)}">${escHtml(displayCrop(c))}</option>`)
            .join('');
        current.crop = crops[0] || null;
    }

    function initYearSlider() {
        els.yearSlider.min = 0;
        els.yearSlider.max = Math.max(0, years.length - 1);
        els.yearSlider.value = 0;
        els.yearMin.textContent = years[0] ?? '—';
        els.yearMax.textContent = years[years.length-1] ?? '—';
        els.yearLabel.textContent = years[0] ?? '—';
        els.yearSlider.disabled = true; // start with "avg all years"
    }

    function updateCropVisibility() {
        const def = currentIndicator();
        const show = !!def?.requiresCrop && current.aggMode !== 'sub';
        els.cropGroup.style.display = show ? 'block' : 'none';
        els.indicatorHelp.textContent = def?.description || '';
    }

    // ---------- Helpers ----------
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
}