export function initMcaController({ apiBase, els }) {
    let studyAreaId = null;
    let presetId = null;
    let resultsCache = null;

    let defaultPresetItems = [];
    let currentPresetItems = [];

    let defaultVars = [];
    let currentVars = [];

    let defaultCropVars = [];
    let currentCropVars = [];

    let allowedCropSet = null; // null = show all

    let runCropsById = {}; // runId -> [crop codes]

    let mcaSelectedIndicator = null;

    let baselineRunId = null;
    let cropRefFactorByCropKey = new Map(); // "CROP::key" -> number|null

    // Scenario state
    let availableRuns = [];           // [{id,label,run_date}]
    let includedRunIds = new Set();   // approach 1: defaults to selected runs
    const runInputs = new Map(); // runId -> { ok, loading, error, variables_run, crop_factors }

    // ---------- Local-only getters for inputs (no DB writes) ----------
    function normalizeVarValue(row) {
        if (!row) return null;
        const t = row.data_type;
        if (t === 'bool') return row.value_bool ?? null;
        if (t === 'number') return (row.value_num == null ? null : Number(row.value_num));
        // text / default
        return row.value_text ?? null;
    }

    function runLabel(runId) {
        const r = (availableRuns || []).find(x => Number(x.id) === Number(runId));
        return r ? r.label : `Run ${runId}`;
    }

    function ensureVizVisible(show) {
        if (!els.mcaVizWrap) return;
        els.mcaVizWrap.style.display = show ? 'block' : 'none';
    }

    function getEnabledIndicatorCodesFromResults(json) {
        // safest: use response enabled_mca (already sorted)
        const list = json?.enabled_mca || [];
        return Array.isArray(list) ? list.map(String) : [];
    }

    function getIndicatorMetaMap(json) {
        const list = Array.isArray(json?.enabled_mca_meta) ? json.enabled_mca_meta : [];
        const m = new Map();
        for (const r of list) {
            const ck = String(r?.calc_key ?? '');
            if (!ck) continue;
            m.set(ck, {
                name: String(r?.name ?? ck),
                unit: r?.unit ? String(r.unit) : null,
                code: r?.code ? String(r.code) : null,
            });
        }
        return m;
    }

    function indicatorLabel(metaMap, code) {
        const m = metaMap.get(String(code));
        return m?.name || String(code);
    }

    function renderRadar(json) {
        if (!els.mcaRadarChart) return;

        const enabled = getEnabledIndicatorCodesFromResults(json);
        const metaMap = getIndicatorMetaMap(json);
        const runIds = (json?.run_ids || []).map(Number);

        const normRoot = json?.results?.normalized || {};
        const normByRun = normRoot.by_run || normRoot;
        if (!enabled.length || !runIds.length) {
            Plotly.purge(els.mcaRadarChart);
            return;
        }

        if (!enabled.length) console.warn('[MCA radar] enabled_mca empty', json);
        if (!runIds.length) console.warn('[MCA radar] run_ids empty', json);
        if (!normByRun || !Object.keys(normByRun).length) {
            console.warn('[MCA radar] normalized.by_run missing/empty', json?.results);
        }

        const axisLabels = enabled.map(code => indicatorLabel(metaMap, code));

        const traces = runIds.map((rid) => {
            const row = normByRun?.[rid] || normByRun?.[String(rid)] || {};
            const rVals = enabled.map(code => {
                const v = row?.[code];
                return Number.isFinite(Number(v)) ? Number(v) : null;
            });

            const theta = axisLabels.concat(axisLabels[0]);
            const r = rVals.concat(rVals[0]);

            return {
                type: 'scatterpolar',
                mode: 'lines+markers',
                name: runLabel(rid),
                theta,
                r,
                connectgaps: true,
            };
        });

        Plotly.newPlot(els.mcaRadarChart, traces, {
            margin: { t: 10, r: 10, b: 10, l: 10 },
            polar: { radialaxis: { range: [0, 1], tickformat: '.1f' } },
            showlegend: true,
        }, { displayModeBar: false, responsive: true });
    }

    function renderTotalsBar(json) {
        if (!els.mcaTotalsChart) return;

        const rows = Array.isArray(json?.totals) ? json.totals : [];
        if (!rows.length) {
            Plotly.purge(els.mcaTotalsChart);
            console.warn('[MCA totals] totals empty', json);
            return;
        }

        const x = rows.map(r => runLabel(r.run_id));
        const y = rows.map(r => (Number.isFinite(Number(r.total_weighted_score)) ? Number(r.total_weighted_score) : null));

        Plotly.newPlot(els.mcaTotalsChart, [{
            type: 'bar',
            x, y,
            hovertemplate: '%{x}<br>%{y:.3f}<extra></extra>',
        }], {
            margin: { t: 10, r: 10, b: 70, l: 60 },
            xaxis: { tickangle: -25, automargin: true },
            yaxis: { title: 'Total weighted score' },
            showlegend: false,
        }, { displayModeBar: false, responsive: true });
    }

    function populateIndicatorSelect(json) {
        if (!els.mcaIndicatorSelect) return;

        const enabled = getEnabledIndicatorCodesFromResults(json); // calc_keys
        const metaMap = getIndicatorMetaMap(json);                 // Map(calc_key -> {name, unit, code})

        if (!enabled.length) {
            els.mcaIndicatorSelect.innerHTML = '';
            mcaSelectedIndicator = null;
            return;
        }

        if (!mcaSelectedIndicator || !enabled.includes(mcaSelectedIndicator)) {
            mcaSelectedIndicator = enabled[0];
        }

        els.mcaIndicatorSelect.innerHTML = enabled.map(calcKey => {
            const meta = metaMap.get(String(calcKey));
            const label = meta?.name || String(calcKey);
            const shownCode = meta?.code || String(calcKey); // SDG-style code if present

            return `<option value="${escapeHtml(calcKey)}">${escapeHtml(label)} (${escapeHtml(shownCode)})</option>`;
        }).join('');

        els.mcaIndicatorSelect.value = mcaSelectedIndicator;
    }

    function renderRawTimeseries(json) {
        if (!els.mcaRawTsChart) return;

        const code = mcaSelectedIndicator;
        if (!code) {
            Plotly.purge(els.mcaRawTsChart);
            return;
        }

        const metaMap = getIndicatorMetaMap(json);
        const meta = metaMap.get(String(code));
        const yTitle = meta?.unit ? `${meta.name} (${meta.unit})` : (meta?.name || code);

        const runIds = (json?.run_ids || []).map(Number);
        const rawRoot = json?.results?.raw || {};
        const rawByRun = rawRoot.by_run || rawRoot;

        if (!rawByRun || !Object.keys(rawByRun).length) {
            console.warn('[MCA raw TS] raw.by_run missing/empty', json?.results);
        }

        // union years across runs for this indicator
        const yearSet = new Set();
        for (const rid of runIds) {
            const rBlock = rawByRun?.[rid] || rawByRun?.[String(rid)];
            const series = rBlock?.[code]?.series || rBlock?.[code];
            if (series && typeof series === 'object') {
                Object.keys(series).forEach(y => yearSet.add(Number(y)));
            }
        }
        const years = [...yearSet].filter(Number.isFinite).sort((a,b)=>a-b);

        if (!years.length) {
            Plotly.purge(els.mcaRawTsChart);
            console.warn('[MCA raw TS] no years found for indicator', code, json?.results);
            return;
        }

        const traces = runIds.map((rid) => {
            const rBlock = rawByRun?.[rid] || rawByRun?.[String(rid)] || {};
            const series = rBlock?.[code]?.series || rBlock?.[code] || {};

            const y = years.map(yr => {
                const v = series?.[yr];
                return Number.isFinite(Number(v)) ? Number(v) : null;
            });

            return {
                type: 'scatter',
                mode: 'lines',
                connectgaps: true,
                name: runLabel(rid),
                x: years,
                y,
                hovertemplate: `${runLabel(rid)}<br>%{x}<br>%{y:.4f}<extra></extra>`,
            };
        });

        Plotly.newPlot(els.mcaRawTsChart, traces, {
            margin: { t: 20, r: 10, b: 40, l: 60 },
            xaxis: { title: 'Year' },
            yaxis: { title: yTitle },
            showlegend: true,
        }, { displayModeBar: false, responsive: true });
    }

    function renderMcaViz(json) {
        const ok = !!json?.ok && !!json?.results;
        ensureVizVisible(ok);

        if (!ok) return;

        // 1) radar + totals
        renderRadar(json);
        renderTotalsBar(json);

        // 2) selector + raw ts
        populateIndicatorSelect(json);
        renderRawTimeseries(json);
    }

    function buildKeyIndex(list, keyField = 'key') {
        const m = new Map();
        for (const r of (list || [])) {
            const k = r?.[keyField];
            if (k != null) m.set(String(k), r);
        }
        return m;
    }

    function allIncludedRunsReady() {
        for (const rid of includedRunIds) {
            const st = runInputs.get(Number(rid));
            if (!st?.ok) return false;
        }
        return true;
    }

    function getCropFactorNum(runId, cropCode, key, fallback = null) {
        const st = getRunState(runId);
        if (!st) return fallback;

        const row = (st._factorByCropKey instanceof Map)
            ? st._factorByCropKey.get(`${String(cropCode)}::${String(key)}`)
            : (st.crop_factors || []).find(r => String(r.crop_code)===String(cropCode) && String(r.key)===String(key));

        const v = normalizeVarValue(row);
        return Number.isFinite(Number(v)) ? Number(v) : fallback;
    }

    /**
     * Get the loaded input state for a run (or null).
     * NOTE: This is local memory only; ensureRunInputsLoaded() fills it from API.
     */
    function getRunState(runId) {
        const st = runInputs.get(Number(runId));
        return st && st.ok ? st : null;
    }

    /**
     * Global MCA variable value (from preset active load): currentVars by key.
     * Example: getGlobalVarNum('discount_rate')
     */
    function getGlobalVarRow(key) {
        return (currentVars || []).find(v => String(v.key) === String(key)) || null;
    }
    function getGlobalVar(key) {
        return normalizeVarValue(getGlobalVarRow(key));
    }
    function getGlobalVarNum(key, fallback = null) {
        const v = getGlobalVar(key);
        return Number.isFinite(Number(v)) ? Number(v) : fallback;
    }
    function getGlobalVarBool(key, fallback = null) {
        const v = getGlobalVar(key);
        return (typeof v === 'boolean') ? v : fallback;
    }
    function getGlobalVarText(key, fallback = null) {
        const v = getGlobalVar(key);
        return (v == null) ? fallback : String(v);
    }

    const SCENARIO_RUN_KEYS = [
        { key: 'economic_life_years',       label: 'Economic life (years)', type: 'number' },
        { key: 'discount_rate',            label: 'Discount rate (%)', type: 'number' },
        { key: 'bmp_invest_cost_usd_ha',    label: 'BMP investment cost (USD/ha)', type: 'number' },
        { key: 'bmp_annual_om_cost_usd_ha', label: 'BMP annual O&M cost (USD/ha/year)', type: 'number' },

        { key: 'water_cost_usd_m3',         label: 'Water cost (USD/m³)', type: 'number' },
        { key: 'water_use_fee_usd_ha',      label: 'Water use fee (USD/ha)', type: 'number' },
    ];

    const BASELINE_LABOUR_KEYS = [
        { key: 'bmp_labour_land_preparation_pd_ha', label: 'Land preparation' },
        { key: 'bmp_labour_planting_pd_ha', label: 'Planting' },
        { key: 'bmp_labour_fertilizer_application_pd_ha', label: 'Fertilizer application' },
        { key: 'bmp_labour_weeding_pd_ha', label: 'Weeding' },
        { key: 'bmp_labour_pest_control_pd_ha', label: 'Pest control' },
        { key: 'bmp_labour_irrigation_pd_ha', label: 'Irrigation' },
        { key: 'bmp_labour_harvesting_pd_ha', label: 'Harvesting' },
        { key: 'bmp_labour_other_pd_ha', label: 'Other' },
    ];

    const BASELINE_MATERIAL_KEYS = [
        { key: 'bmp_material_seeds_usd_ha', label: 'Seeds / planting material' },
        { key: 'bmp_material_mineral_fertilisers_usd_ha', label: 'Mineral fertilisers' },
        { key: 'bmp_material_organic_amendments_usd_ha', label: 'Organic amendments' },
        { key: 'bmp_material_pesticides_usd_ha', label: 'Pesticides' },
        { key: 'bmp_material_tractor_usage_usd_ha', label: 'Tractor usage' },
        { key: 'bmp_material_equipment_usage_usd_ha', label: 'Equipment usage' },
        { key: 'bmp_material_other_usd_ha', label: 'Other' },
    ];

    function getBaselineFactorNum(cropCode, key, fallback = null) {
        const v = cropRefFactorByCropKey.get(`${String(cropCode)}::${String(key)}`);
        return Number.isFinite(Number(v)) ? Number(v) : fallback;
    }

    function setBaselineFactorNum(cropCode, key, numOrNull) {
        const k = `${String(cropCode)}::${String(key)}`;
        cropRefFactorByCropKey.set(k, (numOrNull != null && Number.isFinite(Number(numOrNull))) ? Number(numOrNull) : null);
    }

    function upsertRunVarValue(runId, key, numOrNull) {
        const st = runInputs.get(runId);
        if (!st || !Array.isArray(st.variables_run)) return;

        let row = st.variables_run.find(r => String(r.key) === String(key));
        if (!row) {
            row = { key, data_type: 'number', value_num: null, value_text: null, value_bool: null };
            st.variables_run.push(row);
        }

        row.data_type = row.data_type || 'number';
        row.value_num = (numOrNull != null && Number.isFinite(Number(numOrNull))) ? Number(numOrNull) : null;

        // keep indices fresh
        st._varsByKey = buildKeyIndex(st.variables_run, 'key');
    }

    function getRunCropList(runId) {
        return (runCropsById && runCropsById[runId]) ? runCropsById[runId].map(String) : [];
    }

    function getRunVarRow(runId, key) {
        const st = getRunState(runId);
        if (!st) return null;

        if (st._varsByKey instanceof Map) {
            return st._varsByKey.get(String(key)) || null;
        }

        return (st.variables_run || []).find(v => String(v.key) === String(key)) || null;
    }

    function getRunVar(runId, key) {
        return normalizeVarValue(getRunVarRow(runId, key));
    }
    function getRunVarNum(runId, key, fallback = null) {
        const v = getRunVar(runId, key);
        return Number.isFinite(Number(v)) ? Number(v) : fallback;
    }
    function getRunVarBool(runId, key, fallback = null) {
        const v = getRunVar(runId, key);
        return (typeof v === 'boolean') ? v : fallback;
    }
    function getRunVarText(runId, key, fallback = null) {
        const v = getRunVar(runId, key);
        return (v == null) ? fallback : String(v);
    }

    /**
     * Crop “global” vars (from active preset load): currentCropVars by (crop_code, key)
     * Example: getCropGlobalNum('WHEAT', 'crop_price_usd_per_t')
     */
    function getCropGlobalRow(cropCode, key) {
        const c = String(cropCode);
        const k = String(key);
        return (currentCropVars || []).find(r => String(r.crop_code) === c && String(r.key) === k) || null;
    }
    function getCropGlobal(cropCode, key) {
        return normalizeVarValue(getCropGlobalRow(cropCode, key));
    }
    function getCropGlobalNum(cropCode, key, fallback = null) {
        const v = getCropGlobal(cropCode, key);
        return Number.isFinite(Number(v)) ? Number(v) : fallback;
    }

    /**
     * One convenience object you can pass into indicator calculations.
     * Everything is local memory. If run inputs not loaded yet, returns safe fallbacks.
     */
    function getLocalInputsForRun(runId) {
        const st = getRunState(runId);
        return {
            runId: Number(runId),

            // raw arrays (local)
            variables_global: currentVars || [],
            variables_run: st?.variables_run || [],
            crop_variables_global: currentCropVars || [],
            crop_factors: st?.crop_factors || [],

            // getters (typed)
            getGlobalVar,
            getGlobalVarNum,
            getGlobalVarBool,
            getGlobalVarText,
            getCropFactorNum,
            getBaselineFactorNum,

            getRunVar,
            getRunVarNum,
            getRunVarBool,
            getRunVarText,

            getCropGlobal,
            getCropGlobalNum,
        };
    }

    function setAllowedCrops(cropCodes) {
        if (!Array.isArray(cropCodes) || !cropCodes.length) {
            allowedCropSet = null;
        } else {
            allowedCropSet = new Set(cropCodes.map(c => String(c)));
        }
        // Scenario cards show per-crop factors
        renderScenarioCards();
    }

    function enabledWeightSum(items) {
        return items
            .filter(it => !!it.is_enabled)
            .reduce((sum, it) => sum + (Number(it.weight) || 0), 0);
    }

    function setEditError(msg) {
        if (!els.mcaEditError) return;
        if (!msg) {
            els.mcaEditError.style.display = 'none';
            els.mcaEditError.textContent = '';
        } else {
            els.mcaEditError.style.display = 'block';
            els.mcaEditError.textContent = msg;
        }
    }

    function updateSumUI(items) {
        if (!els.mcaWeightSum) return;
        els.mcaWeightSum.textContent = String(Math.round(enabledWeightSum(items)));
    }

    // ---------- Accordion rendering ----------
    function renderIndicatorsAccordion() {
        if (!els.mcaIndicatorsTableWrap) return;

        const items = currentPresetItems || [];
        if (!items.length) {
            els.mcaIndicatorsTableWrap.innerHTML = `<div class="text-muted small">No preset loaded.</div>`;
            return;
        }

        const sumW = enabledWeightSum(items);

        els.mcaIndicatorsTableWrap.innerHTML = `
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-2 w-100">
              <thead>
                <tr>
                  <th style="width:70px">Use</th>
                  <th>Indicator</th>
                  <th style="width:170px">Direction</th>
                  <th style="width:140px">Weight (whole #)</th>
                </tr>
              </thead>
              <tbody>
                ${items.map((it, idx) => {
                const code = String(it.indicator_code ?? '');
                const name = String(it.indicator_name ?? code);
                const dir  = (it.direction === 'neg' || it.direction === 'pos') ? it.direction : 'pos';
                const w    = Number.isFinite(Number(it.weight)) ? Math.max(0, Math.round(Number(it.weight))) : 0;
    
                return `
                      <tr data-idx="${idx}">
                        <td>
                          <input class="form-check-input mca-en" type="checkbox" ${it.is_enabled ? 'checked' : ''}>
                        </td>
                        <td>
                          <div class="fw-semibold">${escapeHtml(name)}</div>
                          <div class="text-muted small mono">${escapeHtml(code)}</div>
                        </td>
                        <td>
                          <select class="form-select form-select-sm mca-dir">
                            <option value="pos" ${dir === 'pos' ? 'selected' : ''}>Higher is better</option>
                            <option value="neg" ${dir === 'neg' ? 'selected' : ''}>Lower is better</option>
                          </select>
                        </td>
                        <td>
                          <input class="form-control form-control-sm mono mca-w"
                                 type="number" step="1" min="0"
                                 value="${w}">
                          <div class="form-text small">
                            Normalized on compute
                          </div>
                        </td>
                      </tr>
                    `;
            }).join('')}
              </tbody>
            </table>
          </div>
          <div class="small text-muted">
            Enabled weight sum: <span class="mono">${Math.round(sumW)}</span>
          </div>
        `;

        // Wire live edits
        els.mcaIndicatorsTableWrap.querySelectorAll('tr[data-idx]').forEach(tr => {
            const idx = parseInt(tr.dataset.idx, 10);
            const en  = tr.querySelector('.mca-en');
            const dir = tr.querySelector('.mca-dir');
            const w   = tr.querySelector('.mca-w');

            const sync = () => {
                const it = items[idx];
                if (!it) return;

                it.is_enabled = !!en.checked;
                it.direction  = dir.value;

                const n = Math.round(Number(w.value || 0));
                it.weight = Number.isFinite(n) ? Math.max(0, n) : 0;

                updateSumUI(items);
                setEditError(null);
            };

            en.addEventListener('change', sync);
            dir.addEventListener('change', sync);
            w.addEventListener('input', sync);
        });

        updateSumUI(items);
        setEditError(null);
    }

    function renderCropGlobalsAccordion() {
        if (!els.mcaCropGlobalsWrap) return;

        const rows = (currentCropVars || []).slice();
        if (!rows.length) {
            els.mcaCropGlobalsWrap.innerHTML = `<div class="text-muted small">No crop variables loaded.</div>`;
            return;
        }

        const KEYS = [
            { key: 'crop_price_usd_per_t', label: 'Crop price (USD/t)' },
        ];

        const byCrop = new Map();
        for (const r of rows) {
            const code = r.crop_code;
            if (!byCrop.has(code)) byCrop.set(code, { crop_code: code, crop_name: r.crop_name, vars: [] });
            byCrop.get(code).vars.push(r);
        }

        let crops = Array.from(byCrop.values());
        if (allowedCropSet) crops = crops.filter(c => allowedCropSet.has(String(c.crop_code)));

        if (!crops.length) {
            els.mcaCropGlobalsWrap.innerHTML = `<div class="text-muted small">No crops available for the selected scenarios.</div>`;
            return;
        }

        function upsert(crop, key, patch) {
            let row = currentCropVars.find(r => r.crop_code === crop && r.key === key);
            if (!row) {
                row = { crop_code: crop, crop_name: (byCrop.get(crop)?.crop_name ?? crop), key, data_type: 'number' };
                currentCropVars.push(row);
            }
            Object.assign(row, patch);
        }

        function renderBaselineFactorTable(title, unit, keys, crops) {
            return `
                <div class="mt-3">
                  <div class="small text-muted mb-1">
                    ${escapeHtml(title)} <span class="text-muted">(${escapeHtml(unit)})</span>
                    ${baselineRunId ? `<span class="text-muted ms-2">baseline run_id: ${baselineRunId}</span>` : `<span class="text-danger ms-2">baseline not found</span>`}
                  </div>
                  <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                      <thead>
                        <tr>
                          <th style="min-width:180px">Crop</th>
                          ${keys.map(k => `<th style="min-width:220px">${escapeHtml(k.label)}</th>`).join('')}
                        </tr>
                      </thead>
                      <tbody>
                        ${crops.map(c => `
                          <tr>
                            <td>
                              <div class="fw-semibold">${escapeHtml(c.crop_name || c.crop_code)}</div>
                              <div class="text-muted small mono">${escapeHtml(c.crop_code)}</div>
                            </td>
                            ${keys.map(k => {
                                const v = getBaselineFactorNum(c.crop_code, k.key, null);
                                const shown = (v == null) ? '' : String(v);
                                return `
                                    <td>
                                      <input class="form-control form-control-sm mono mca-baseline-factor"
                                             data-crop="${escapeHtml(c.crop_code)}"
                                             data-key="${escapeHtml(k.key)}"
                                             type="number" step="any"
                                             value="${escapeHtml(shown)}">
                                    </td>
                              `;
                            }).join('')}
                          </tr>
                        `).join('')}
                      </tbody>
                    </table>
                  </div>
                </div>
              `;
        }

        els.mcaCropGlobalsWrap.innerHTML = `
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Crop</th>
                  ${KEYS.map(k => `<th style="min-width:220px">${escapeHtml(k.label)}</th>`).join('')}
                </tr>
              </thead>
              <tbody>
                ${crops.map(c => `
                  <tr>
                    <td>
                      <div class="fw-semibold">${escapeHtml(c.crop_name || c.crop_code)}</div>
                      <div class="text-muted small mono">${escapeHtml(c.crop_code)}</div>
                    </td>
                    ${KEYS.map(k => {
                        const row = c.vars.find(v => v.key === 'crop_price_usd_per_t');
                        const val = row?.value_num ?? '';
                        return `
                            <td>
                                <input class="form-control form-control-sm mono mca-crop-global"
                                       data-crop="${escapeHtml(c.crop_code)}"
                                       data-key="crop_price_usd_per_t"
                                       type="number" step="any"
                                       value="${escapeHtml(String(val ?? ''))}">
                            </td>
                        `;
                    }).join('')}
                  </tr>
                `).join('')}
              </tbody>
            </table>
          </div>
        
          ${renderBaselineFactorTable('Baseline labour factors', 'person-days/ha', BASELINE_LABOUR_KEYS, crops)}
          ${renderBaselineFactorTable('Baseline material factors', 'USD/ha', BASELINE_MATERIAL_KEYS, crops)}
        `;

        els.mcaCropGlobalsWrap.querySelectorAll('.mca-crop-global').forEach(inp => {
            inp.addEventListener('input', () => {
                const crop = inp.dataset.crop;
                const key  = inp.dataset.key;
                const txt  = String(inp.value ?? '').trim();
                const num  = (txt === '') ? null : Number(txt);

                upsert(crop, key, {
                    data_type: 'number',
                    value_num: (num !== null && Number.isFinite(num)) ? num : null
                });
            });
        });

        els.mcaCropGlobalsWrap.querySelectorAll('.mca-baseline-factor').forEach(inp => {
            inp.addEventListener('input', () => {
                const crop = String(inp.dataset.crop || '');
                const key  = String(inp.dataset.key || '');
                const txt  = String(inp.value ?? '').trim();
                const num  = (txt === '') ? null : Number(txt);
                setBaselineFactorNum(crop, key, (num != null && Number.isFinite(num)) ? num : null);
            });
        });
    }

    // ---------- Scenario picker + cards ----------
    async function setAvailableRuns({ studyAreaId: saId, selectedRunIds, runsMeta, runCropsById: rc = {} }) {
        studyAreaId = saId;
        runCropsById = rc || {};

        // build availableRuns from selectedRunIds
        const selectedSet = new Set((selectedRunIds || []).map(n => Number(n)));
        availableRuns = (runsMeta || [])
            .filter(r => selectedSet.has(Number(r.id)))
            .map(r => ({
                id: Number(r.id),
                label: r.run_label || `Run ${r.id}`,
                run_date: r.run_date || null,
            }));

        // Approach 1: include all selected by default (but keep previous include states if possible)
        const prev = new Set(includedRunIds);
        includedRunIds = new Set();
        for (const r of availableRuns) {
            if (prev.size === 0) includedRunIds.add(r.id);
            else if (prev.has(r.id)) includedRunIds.add(r.id);
            else includedRunIds.add(r.id); // approach 1: default included
        }

        // purge inputs for runs that are no longer available
        for (const rid of runInputs.keys()) {
            if (!selectedSet.has(Number(rid))) runInputs.delete(rid);
        }

        renderScenarioPicker();
        renderScenarioCards();
        updateComputeEnabled();

        // preload inputs for included runs
        await Promise.all([...includedRunIds].map(rid => ensureRunInputsLoaded(rid)));
        renderScenarioCards();
        updateComputeEnabled();
        debugComputeGate('after setAvailableRuns preload');
    }

    function renderScenarioPicker() {
        if (!els.mcaScenarioPickerWrap) return;

        if (!availableRuns.length) {
            els.mcaScenarioPickerWrap.innerHTML = `<div class="text-muted small">Select scenarios in the Controls panel to make them available here.</div>`;
            return;
        }

        els.mcaScenarioPickerWrap.innerHTML = `
      <div class="small text-muted mb-1">Included scenarios (default: all selected scenarios)</div>
      <div class="d-flex flex-column gap-1">
        ${availableRuns.map(r => {
            const checked = includedRunIds.has(r.id) ? 'checked' : '';
            const date = r.run_date ? ` <span class="text-muted">(${escapeHtml(r.run_date)})</span>` : '';
            return `
            <div class="form-check">
              <input class="form-check-input mca-include-run" type="checkbox" data-run-id="${r.id}" ${checked}>
              <label class="form-check-label">
                ${escapeHtml(r.label)}${date}
              </label>
            </div>
          `;
        }).join('')}
      </div>
    `;

        els.mcaScenarioPickerWrap.querySelectorAll('.mca-include-run').forEach(inp => {
            inp.addEventListener('change', async () => {
                const rid = Number(inp.dataset.runId);
                if (!Number.isFinite(rid)) return;

                if (inp.checked) includedRunIds.add(rid);
                else includedRunIds.delete(rid);

                updateComputeEnabled();
                renderScenarioCards();

                if (inp.checked) {
                    await ensureRunInputsLoaded(rid);
                    renderScenarioCards();
                }
            });
        });
    }

    function renderScenarioCards() {
        if (!els.mcaScenarioCards) return;

        if (!availableRuns.length) {
            els.mcaScenarioCards.innerHTML = '';
            return;
        }

        const included = availableRuns.filter(r => includedRunIds.has(r.id));
        if (!included.length) {
            els.mcaScenarioCards.innerHTML = `
              <div class="alert alert-warning py-2 small mb-0">
                No scenarios included. Tick at least one scenario above to compute MCA.
              </div>
            `;
            return;
        }

        function renderFactorSection(title, unit, keys, runId, runCrops, st) {
            const idx = (st?._factorByCropKey instanceof Map) ? st._factorByCropKey : null;

            return `
              <div class="mt-2">
                <div class="small text-muted mb-1">${escapeHtml(title)} <span class="text-muted">(${escapeHtml(unit)})</span></div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0">
                    <thead>
                      <tr>
                        <th style="min-width:180px">Crop</th>
                        ${keys.map(k => `<th style="min-width:220px">${escapeHtml(k.label)}</th>`).join('')}
                      </tr>
                    </thead>
                    <tbody>
                      ${runCrops.map(code => {
                        const anyRow = (st.crop_factors || []).find(x => String(x.crop_code) === String(code));
                        const name = anyRow?.crop_name || code;
        
                        return `
                          <tr>
                            <td>
                              <div class="fw-semibold">${escapeHtml(name)}</div>
                              <div class="text-muted small mono">${escapeHtml(code)}</div>
                            </td>
                            ${keys.map(k => {
                                const row = idx ? idx.get(`${String(code)}::${String(k.key)}`) : null;
                                const val = (row && row.value_num != null) ? row.value_num : '';
                                return `
                                    <td>
                                      <input class="form-control form-control-sm mono mca-crop-factor"
                                             data-run-id="${runId}"
                                             data-crop="${escapeHtml(code)}"
                                             data-key="${escapeHtml(k.key)}"
                                             type="number" step="any"
                                             value="${escapeHtml(String(val))}">
                                    </td>
                                `;
                            }).join('')}
                          </tr>
                        `;
                      }).join('')}
                    </tbody>
                  </table>
                </div>
              </div>
            `;
        }

        // 1) Render HTML
        els.mcaScenarioCards.innerHTML = included.map(r => {
            const st = runInputs.get(r.id) || { loading: false };

            const badge = st.loading
                ? `<span class="badge text-bg-secondary">Loading inputs…</span>`
                : st.error
                    ? `<span class="badge text-bg-danger">Inputs error</span>`
                    : st.ok
                        ? `<span class="badge text-bg-success">Inputs ready</span>`
                        : `<span class="badge text-bg-secondary">Inputs pending</span>`;

            const varsByKey = (st._varsByKey instanceof Map) ? st._varsByKey : buildKeyIndex(st.variables_run || [], 'key');

            const varsHtml = SCENARIO_RUN_KEYS.map(v => {
                const row = varsByKey.get(String(v.key));
                const val = (row && row.value_num != null) ? row.value_num : '';
                return `
                    <div class="col-12 col-md-6">
                      <label class="form-label mb-1 small">${escapeHtml(v.label)}</label>
                      <input class="form-control form-control-sm mono mca-run-var"
                             data-run-id="${r.id}"
                             data-key="${escapeHtml(v.key)}"
                             type="number" step="any"
                             value="${escapeHtml(String(val))}">
                    </div>
                  `;
            }).join('');

            const runCrops = getRunCropList(r.id);

            const cropTables = runCrops.length ? `
              ${renderFactorSection('Labour inputs', 'person-days/ha', BASELINE_LABOUR_KEYS, r.id, runCrops, st)}
              ${renderFactorSection('Material inputs', 'USD/ha', BASELINE_MATERIAL_KEYS, r.id, runCrops, st)}
            ` : `<div class="mt-2 small text-muted">No crop list available for this scenario (run ${r.id}).</div>`;

            return `
              <div class="card">
                <div class="card-body py-2">
                  <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                      <div class="fw-semibold">${escapeHtml(r.label)}</div>
                      <div class="text-muted small mono">run_id: ${r.id}</div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                      ${badge}
                      <button class="btn btn-sm btn-outline-secondary mca-reload-run" data-run-id="${r.id}">
                        Reload
                      </button>
                    </div>
                  </div>
        
                  ${st.error ? `<div class="small text-danger mt-1">${escapeHtml(st.error)}</div>` : ``}
        
                  <div class="mt-2">
                    <div class="small text-muted mb-1">Scenario variables (local edits, not saved)</div>
                    <div class="row g-2">${varsHtml}</div>
                  </div>
        
                  ${cropTables}
                </div>
              </div>
            `;
        }).join('');

        // 2) Wire events AFTER rendering
        els.mcaScenarioCards.querySelectorAll('.mca-reload-run').forEach(btn => {
            btn.addEventListener('click', async () => {
                const rid = Number(btn.dataset.runId);
                if (!Number.isFinite(rid)) return;
                runInputs.delete(rid);
                renderScenarioCards();
                await ensureRunInputsLoaded(rid);
                renderScenarioCards();
            });
        });

        els.mcaScenarioCards.querySelectorAll('.mca-run-var').forEach(inp => {
            inp.addEventListener('input', () => {
                const runId = Number(inp.dataset.runId);
                const key = String(inp.dataset.key || '');
                const txt = String(inp.value ?? '').trim();
                const num = (txt === '') ? null : Number(txt);
                upsertRunVarValue(runId, key, (num != null && Number.isFinite(num)) ? num : null);
            });
        });

        els.mcaScenarioCards.querySelectorAll('.mca-crop-factor').forEach(inp => {
            inp.addEventListener('input', () => {
                const runId = Number(inp.dataset.runId);
                const crop  = String(inp.dataset.crop || '');
                const key   = String(inp.dataset.key || '');
                const txt   = String(inp.value ?? '').trim();
                const num   = (txt === '') ? null : Number(txt);

                const st = runInputs.get(runId);
                if (!st || !Array.isArray(st.crop_factors)) return;

                let row = st.crop_factors.find(r => String(r.crop_code) === crop && String(r.key) === key);
                if (!row) {
                    row = { crop_code: crop, crop_name: crop, key, data_type: 'number', value_num: null, value_text: null, value_bool: null };
                    st.crop_factors.push(row);
                }
                row.data_type = 'number';
                row.value_num = (num != null && Number.isFinite(num)) ? num : null;

                st._factorByCropKey = buildCropKeyIndex2(st.crop_factors);
            });
        });
    }

    async function ensureRunInputsLoaded(runId) {
        if (!studyAreaId || !Number.isFinite(Number(runId))) return;

        const existing = runInputs.get(runId);
        if (existing?.loading) return;
        if (existing?.ok) return;

        runInputs.set(runId, { loading: true, ok: false, error: null });
        renderScenarioCards();
        updateComputeEnabled();

        try {
            const url = `${apiBase}/mca_run_inputs.php?study_area_id=${encodeURIComponent(studyAreaId)}&run_id=${encodeURIComponent(runId)}`;
            const res = await fetch(url);
            const json = await res.json();
            if (!res.ok || !json.ok) throw new Error(json.error || `HTTP ${res.status}`);

            runInputs.set(runId, {
                loading: false,
                ok: true,
                error: null,

                variables_run: json.variables_run || [],
                crop_factors: json.crop_factors || [],

                // indexes for fast lookups (still local memory)
                _varsByKey: buildKeyIndex(json.variables_run || [], 'key'),
                _factorByCropKey: buildCropKeyIndex2(json.crop_factors || []),
            });
            renderScenarioCards();
        } catch (e) {
            runInputs.set(runId, { loading: false, ok: false, error: e.message || String(e) });
        }
        updateComputeEnabled();
    }

    function updateComputeEnabled() {
        if (!els.mcaComputeBtn) return;

        let reason = null;

        if (!presetId) reason = 'No preset loaded (presetId missing).';
        else if (includedRunIds.size <= 0) reason = 'No scenarios included.';
        else if (!allIncludedRunsReady()) {
            const bad = [...includedRunIds].map(rid => ({ rid: Number(rid), st: runInputs.get(Number(rid)) }))
                .filter(x => !x.st?.ok)
                .map(x => `run ${x.rid}: ${x.st?.loading ? 'loading' : (x.st?.error ? x.st.error : 'not loaded')}`);
            reason = `Scenario inputs not ready: ${bad.join(' | ')}`;
        }

        els.mcaComputeBtn.disabled = !!reason;
        els.mcaComputeBtn.title = reason || 'Compute MCA';
    }

    function debugComputeGate(where = '') {
        const states = [...includedRunIds].map(rid => {
            const st = runInputs.get(Number(rid));
            return {
                run_id: Number(rid),
                ok: !!st?.ok,
                loading: !!st?.loading,
                error: st?.error || null,
                vars: Array.isArray(st?.variables_run) ? st.variables_run.length : null,
                factors: Array.isArray(st?.crop_factors) ? st.crop_factors.length : null,
            };
        });

        console.log('[MCA compute gate]', where, {
            studyAreaId,
            presetId,
            presetIdTruthy: !!presetId,
            includedRunIds: [...includedRunIds],
            includedCount: includedRunIds.size,
            allIncludedRunsReady: allIncludedRunsReady(),
            states,
        });
    }

    function buildCropKeyIndex2(list) {
        // Map<"crop::key", row>
        const m = new Map();
        for (const r of (list || [])) {
            const c = r?.crop_code;
            const k = r?.key;
            if (c != null && k != null) m.set(`${String(c)}::${String(k)}`, r);
        }
        return m;
    }

    // ---------- MCA form rendering ----------
    function renderVarsForm(vars, targetEl = els.mcaVarsForm) {
        if (!targetEl) return;

        targetEl.innerHTML = vars.map((v, idx) => {
            const key = String(v.key);
            const name = String(v.name || key);
            const unit = v.unit ? ` <span class="text-muted small">(${escapeHtml(v.unit)})</span>` : '';
            const val = (v.data_type === 'bool')
                ? (v.value_bool ? 'checked' : '')
                : (v.value_num ?? v.value_text ?? '');

            if (v.data_type === 'bool') {
                return `
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input mca-var" type="checkbox" data-idx="${idx}" ${val}>
              <label class="form-check-label">${escapeHtml(name)}${unit}</label>
            </div>
          </div>
        `;
            }

            return `
        <div class="col-12 col-md-6">
          <label class="form-label mb-1">${escapeHtml(name)}${unit}</label>
          <input class="form-control form-control-sm mca-var"
                 data-idx="${idx}"
                 type="${v.data_type === 'number' ? 'number' : 'text'}"
                 step="any"
                 value="${escapeHtml(String(val ?? ''))}">
          ${v.description ? `<div class="form-text small">${escapeHtml(v.description)}</div>` : ''}
        </div>
      `;
        }).join('');

        targetEl.querySelectorAll('.mca-var').forEach(inp => {
            const idx = parseInt(inp.dataset.idx, 10);
            const v = vars[idx];
            if (!v) return;

            const evtName = (v.data_type === 'bool') ? 'change' : 'input';

            inp.addEventListener(evtName, () => {
                if (v.data_type === 'bool') {
                    v.value_bool = !!inp.checked;
                    return;
                }

                const txt = String(inp.value ?? '').trim();
                if (v.data_type === 'number') {
                    v.value_num = (txt === '') ? null : Number(txt);
                    if (v.value_num !== null && !Number.isFinite(v.value_num)) v.value_num = null;
                } else {
                    v.value_text = txt === '' ? null : txt;
                }
            });
        });
    }

    async function loadActivePreset(saId) {
        studyAreaId = saId;

        const res = await fetch(`${apiBase}/mca_preset_active.php?study_area_id=${encodeURIComponent(saId)}`);
        const json = await res.json();
        if (!res.ok || !json.ok) throw new Error(json.error || `HTTP ${res.status}`);

        // IMPORTANT: set presetId so compute can enable
        const pidRaw =
            json.preset_set_id ??
            json.preset_id ??
            json.id ??
            json.preset?.id ??
            json.preset?.preset_set_id ??
            json.preset?.preset_id ??
            json.variable_set?.id; // if your backend calls it this

        presetId = Number.isFinite(Number(pidRaw)) ? Number(pidRaw) : null;

        if (!presetId) {
            console.error('Preset id missing from mca_preset_active response:', json);
        }

        defaultPresetItems = (json.items || []).map(x => ({ ...x }));
        currentPresetItems = (json.items || []).map(x => ({ ...x }));

        defaultCropVars = (json.crop_variables || []).map(x => ({ ...x }));
        currentCropVars = (json.crop_variables || []).map(x => ({ ...x }));

        const VAR_ORDER = [
            'discount_rate',
            'economic_life_years',
            'baseline_net_income_usd_ha',
            'net_income_after_usd_ha',
            'labour_hours_per_ha',
            'labour_cost_usd_per_hour',
            'include_labour_in_costs',
            'bmp_invest_cost_usd_ha',
            'bmp_annual_om_cost_usd_ha',
            'bmp_annual_benefit_usd_ha',
        ];

        function sortVars(vars) {
            const rank = new Map(VAR_ORDER.map((k,i)=>[k,i]));
            return vars.slice().sort((a,b) => (rank.get(a.key) ?? 999) - (rank.get(b.key) ?? 999));
        }

        defaultVars = sortVars((json.variables || []).map(x => ({ ...x })));
        currentVars = sortVars((json.variables || []).map(x => ({ ...x })));

        baselineRunId = Number(json.baseline_run_id ?? null);

        // Build baseline REF map from API field crop_ref_cost
        // Baseline (reference) factors: store rows by crop+key
        cropRefFactorByCropKey = new Map();
        for (const r of (json.crop_ref_factors || [])) {
            const crop = String(r.crop_code);
            const key  = String(r.key);
            const num  = (r.value_num == null) ? null : Number(r.value_num);
            cropRefFactorByCropKey.set(`${crop}::${key}`, Number.isFinite(num) ? num : null);
        }

        renderIndicatorsAccordion();
        renderCropGlobalsAccordion();
        renderScenarioPicker();
        renderScenarioCards();
        renderVarsForm(currentVars, els.mcaVarsForm);

        updateComputeEnabled();
        debugComputeGate('after loadActivePreset');

        ensureVizVisible(false);
        if (els.mcaRadarChart) Plotly.purge(els.mcaRadarChart);
        if (els.mcaTotalsChart) Plotly.purge(els.mcaTotalsChart);
        if (els.mcaRawTsChart) Plotly.purge(els.mcaRawTsChart);
    }

    async function compute(cropCode = null) {
        if (!presetId) return;

        els.mcaComputeBtn.disabled = true;
        els.mcaComputeBtn.textContent = 'Computing…';

        try {
            const fd = new FormData();
            fd.append('csrf', window.CSRF_TOKEN);
            fd.append('preset_set_id', presetId);
            fd.append('preset_id', presetId);
            if (cropCode) fd.append('crop_code', cropCode);

            // IMPORTANT: send included run ids
            fd.append('run_ids_json', JSON.stringify([...includedRunIds]));

            const enabled = currentPresetItems.filter(it => !!it.is_enabled);
            const sumW = enabled.reduce((s, it) => s + (Math.round(Number(it.weight) || 0)), 0);

            if (sumW <= 0) {
                setEditError('Enable at least one indicator and give it a weight > 0.');
                throw new Error('Invalid weights');
            }
            setEditError(null);

            fd.append('preset_items_json', JSON.stringify(
                currentPresetItems.map(it => {
                    const wInt = Math.max(0, Math.round(Number(it.weight) || 0));
                    return {
                        indicator_calc_key: it.indicator_calc_key,
                        indicator_code: it.indicator_code,         // optional (debug/display)
                        weight: it.is_enabled ? wInt : 0,
                        direction: it.direction,
                        is_enabled: !!it.is_enabled,
                    };
                })
            ));
            fd.append('variables_json', JSON.stringify(
                currentVars.map(v => ({
                    key: v.key,
                    data_type: v.data_type,
                    value_num: v.value_num ?? null,
                    value_text: v.value_text ?? null,
                    value_bool: v.value_bool ?? null,
                }))
            ));
            fd.append('crop_variables_json', JSON.stringify(
                currentCropVars.map(r => ({
                    crop_code: r.crop_code,
                    key: r.key,
                    data_type: r.data_type,
                    value_num: r.value_num ?? null,
                    value_text: r.value_text ?? null,
                    value_bool: r.value_bool ?? null,
                }))
            ));
            fd.append('crop_ref_factors_json', JSON.stringify(
                Array.from(cropRefFactorByCropKey.entries()).map(([k, v]) => {
                    const [crop_code, key] = k.split('::');
                    return {
                        crop_code,
                        key,
                        data_type: 'number',
                        value_num: (v == null ? null : Number(v)),
                        value_text: null,
                        value_bool: null,
                    };
                })
            ));
            // Build per-run inputs payload (honor local edits)
            const runInputsPayload = [...includedRunIds].map(runId => {
                const st = runInputs.get(Number(runId));
                return {
                    run_id: Number(runId),
                    variables_run: st?.variables_run || [],
                    crop_factors: st?.crop_factors || [],
                };
            });

            fd.append('run_inputs_json', JSON.stringify(runInputsPayload));

            const res = await fetch(`${apiBase}/mca_compute.php`, { method: 'POST', body: fd });
            const json = await res.json();
            console.log('[MCA compute] HTTP', res.status, 'ok?', res.ok);
            console.log('[MCA compute] payload keys', Object.keys(json || {}));
            console.log('[MCA compute] summary', {
                ok: json?.ok,
                hasResults: !!json?.results,
                run_ids: json?.run_ids,
                enabled_mca: json?.enabled_mca?.length,
                totals_len: Array.isArray(json?.totals) ? json.totals.length : null,
                norm_keys: json?.results?.normalized?.by_run ? Object.keys(json.results.normalized.by_run) : null,
                raw_keys: json?.results?.raw?.by_run ? Object.keys(json.results.raw.by_run) : null,
            });
            if (!json.ok) throw new Error(json.error || 'MCA compute failed');

            resultsCache = json;
            renderMcaViz(json);
            return json;
        } finally {
            els.mcaComputeBtn.textContent = 'Compute MCA';
            updateComputeEnabled();
        }
    }

    function getScenarioScore(runId) {
        if (!resultsCache) return null;
        const t = (resultsCache.totals || []).find(r => r.run_id == runId);
        return t ? t.total_weighted_score : null;
    }

    // ---- UI wiring ----
    els.mcaComputeBtn?.addEventListener('click', () => compute());

    els.mcaIndicatorSelect?.addEventListener('change', () => {
        mcaSelectedIndicator = String(els.mcaIndicatorSelect.value || '');
        if (resultsCache) renderRawTimeseries(resultsCache);
    });

    return {
        loadActivePreset,
        compute,
        getScenarioScore,
        hasResults: () => !!resultsCache,
        setAllowedCrops,
        setAvailableRuns,

        ensureRunInputsLoaded,
        getLocalInputsForRun,
        getRunVarNum,
        getCropGlobalNum,
        getGlobalVarNum,
    };
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, m => (
        {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]
    ));
}