// assets/js/egypt/subbasin-dashboard.js
export function initSubbasinDashboard({ els, subbasinGeoUrl, riversGeoUrl, indicators }) {
    // ---------- State ----------
    let map, subLayer, subSource, subFeatures = [];
    let vectorLayer; // stylable choropleth layer (subbasins)
    let rivLayer, rivSource;   // rivers
    let rows = [];   // monthly HRU rows (from CSV)
    let years = [];  // list of YEARs in data
    let crops = [];  // list of LULC codes
    let colset = new Set(); // available CSV columns
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

    // ---------- Boot ----------
    wireUI();
    loadAll(current.datasetUrl);

    // ---------- UI ----------
    function wireUI() {
        els.dataset.addEventListener('change', async () => {
            current.datasetUrl = els.dataset.value;
            await loadAll(current.datasetUrl);
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
    async function loadAll(csvUrl) {
        // Load CSV + GeoJSON
        const [csvText, subGJ, rivGJ] = await Promise.all([
            fetch(csvUrl).then(r => r.ok ? r.text() : Promise.reject(new Error(`CSV HTTP ${r.status}`))),
            fetch(subbasinGeoUrl).then(r => r.ok ? r.json() : Promise.reject(new Error(`Subbasins HTTP ${r.status}`))),
            fetch(riversGeoUrl).then(r => r.ok ? r.json() : Promise.reject(new Error(`Rivers HTTP ${r.status}`)))
        ]).catch(err => {
            console.error('[LOAD] Failed:', err);
            throw err;
        });
        console.debug('[LOAD] CSV bytes:', csvText.length);
        console.debug('[LOAD] Subbasin keys:', Object.keys(subGJ || {}));
        console.debug('[LOAD] Rivers keys:', Object.keys(rivGJ || {}));

        // Parse CSV (semicolon)
        const parsed = d3.dsvFormat(';').parse(csvText);
        // Normalize + collect columns
        rows = parsed.map(r => ({
            ...r,
            LULC: clean(r.LULC),
            HRU: +r.HRU,
            HRUGIS: +r.HRUGIS,
            SUB: +r.SUB,
            YEAR: +r.YEAR,
            MON: +r.MON,
            AREAkm2: toNum(r.AREAkm2)
        }));
        colset = new Set(Object.keys(rows[0] || {}));

        // Years / crops
        years = [...new Set(rows.map(r => r.YEAR).filter(Number.isFinite))].sort((a,b) => a-b);
        crops = [...new Set(rows.map(r => r.LULC).filter(Boolean))].sort();

        // Pre-aggregate: annual per-HRU sums for any column needed by at least one enabled indicator
        const neededCols = new Set();
        for (const def of indicators) {
            if (def.disabled) continue; // still allow enabling if data exists later
            (def.needs || []).forEach(c => neededCols.add(c));
        }
        // Only keep the needed cols that actually exist
        const keepCols = [...neededCols].filter(c => colset.has(c));
        // Build a nested map by key
        const key = r => `${r.SUB}|${r.LULC}|${r.HRU}|${r.YEAR}`;
        const acc = new Map(); // key -> aggregate row
        for (const r of rows) {
            if (!Number.isFinite(r.YEAR) || !Number.isFinite(r.MON)) continue;
            const k = key(r);
            let obj = acc.get(k);
            if (!obj) {
                obj = { SUB: r.SUB, LULC: r.LULC, HRU: r.HRU, YEAR: r.YEAR, AREAkm2: r.AREAkm2 };
                // init sums
                for (const c of keepCols) obj[`${c}_sum`] = 0;
                acc.set(k, obj);
            }
            for (const c of keepCols) {
                const v = toNum(r[c]);
                if (Number.isFinite(v)) obj[`${c}_sum`] += v;
            }
        }
        hruAnnual.data = [...acc.values()];

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
                const v = def.calc({ hruAnnual, areaHa, sub, crop: c, year });
                if (Number.isFinite(v) && area > 0) { wsum += area; vsum += v * area; }
            }
            return wsum ? vsum / wsum : NaN;
        }

        // Default path: crop-specific if needed, otherwise sub-only
        const crop = def.requiresCrop ? current.crop : undefined;

        return def.calc({
            hruAnnual,
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
                        sub: current.selectedSub,
                        crop: c,
                        year: Y,
                        areaHa: r => (Number.isFinite(r.AREAkm2) ? r.AREAkm2 * 100 : NaN)
                    })
                    : def.calc({
                        hruAnnual,
                        sub: current.selectedSub,
                        year: Y,
                        areaHa: r => (Number.isFinite(r.AREAkm2) ? r.AREAkm2 * 100 : NaN)
                    })
            ).filter(Number.isFinite);

            if (!vals.length) continue;
            x2.push(c);
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
        if (!def?.needs || def.needs.length === 0) return true;
        return def.needs.every(c => colset.has(c));
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
        els.crop.innerHTML = crops.map(c => `<option value="${escAttr(c)}">${escHtml(c)}</option>`).join('');
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
        return def?.requiresCrop && current.crop ? ` — ${current.crop}` : '';
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