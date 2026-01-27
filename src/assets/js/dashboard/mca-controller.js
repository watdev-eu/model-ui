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

    let baselineRunId = null;
    let cropRefByCrop = new Map(); // crop_code -> value_num (baseline prod_cost_bmp_usd_ha)

    // Scenario state
    let availableRuns = [];           // [{id,label,run_date}]
    let includedRunIds = new Set();   // approach 1: defaults to selected runs
    const runInputs = new Map();      // runId -> { ok, loading, error, variables_run, crop_bmp_cost }

    // ---------- Local-only getters for inputs (no DB writes) ----------
    function normalizeVarValue(row) {
        if (!row) return null;
        const t = row.data_type;
        if (t === 'bool') return row.value_bool ?? null;
        if (t === 'number') return (row.value_num == null ? null : Number(row.value_num));
        // text / default
        return row.value_text ?? null;
    }

    function buildKeyIndex(list, keyField = 'key') {
        const m = new Map();
        for (const r of (list || [])) {
            const k = r?.[keyField];
            if (k != null) m.set(String(k), r);
        }
        return m;
    }

    function buildCropKeyIndex(list) {
        // Map<crop_code, row>
        const m = new Map();
        for (const r of (list || [])) {
            const c = r?.crop_code;
            if (c != null) m.set(String(c), r);
        }
        return m;
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
        { key: 'bmp_prod_cost_usd_ha', label: 'BMP production cost (USD/ha)', type: 'number' },
        { key: 'time_horizon_years',   label: 'Time horizon / economic life (years)', type: 'number' },
        { key: 'discount_rate',        label: 'Discount rate (0–1)', type: 'number' },
        { key: 'bmp_invest_cost_usd_ha',label: 'BMP investment cost (USD/ha)', type: 'number' },
        { key: 'bmp_annual_om_cost_usd_ha', label: 'BMP annual O&M cost (USD/ha/year)', type: 'number' },
    ];

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

    function upsertCropBmpCostValue(runId, cropCode, numOrNull) {
        const st = runInputs.get(runId);
        if (!st || !Array.isArray(st.crop_bmp_cost)) return;

        const c = String(cropCode);
        let row = st.crop_bmp_cost.find(r => String(r.crop_code) === c);

        if (!row) {
            row = { crop_code: c, crop_name: c, key: 'prod_cost_bmp_usd_ha', data_type: 'number', value_num: null };
            st.crop_bmp_cost.push(row);
        }

        row.data_type = 'number';
        row.key = row.key || 'prod_cost_bmp_usd_ha';
        row.value_num = (numOrNull != null && Number.isFinite(Number(numOrNull))) ? Number(numOrNull) : null;

        st._bmpByCrop = buildCropKeyIndex(st.crop_bmp_cost);
    }

    function getRunCropList(runId) {
        return (runCropsById && runCropsById[runId]) ? runCropsById[runId].map(String) : [];
    }

    /**
     * Run-level MCA variable value (from mca_run_inputs.php): variables_run by key.
     * Example: getRunVarNum(runId, 'bmp_invest_cost_usd_ha')
     */
    function getRunVarRow(runId, key) {
        const st = getRunState(runId);
        if (!st) return null;

        // Fast path if indices exist (we create them in ensureRunInputsLoaded)
        if (st._varsByKey instanceof Map) {
            return st._varsByKey.get(String(key)) || null;
        }

        // Fallback
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
     * Crop BMP cost rows (from mca_run_inputs.php): crop_bmp_cost by crop_code for THIS run.
     * Example: getCropBmpCostNum(runId, 'WHEAT')
     */
    function getCropBmpCostRow(runId, cropCode) {
        const st = getRunState(runId);
        if (!st) return null;

        // Fast path if index exists
        if (st._bmpByCrop instanceof Map) {
            return st._bmpByCrop.get(String(cropCode)) || null;
        }

        // Fallback
        return (st.crop_bmp_cost || []).find(r => String(r.crop_code) === String(cropCode)) || null;
    }
    function getCropBmpCostNum(runId, cropCode, fallback = null) {
        const row = getCropBmpCostRow(runId, cropCode);
        const v = normalizeVarValue(row);
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
            crop_bmp_cost: st?.crop_bmp_cost || [],

            // getters (typed)
            getGlobalVar,
            getGlobalVarNum,
            getGlobalVarBool,
            getGlobalVarText,

            getRunVar,
            getRunVarNum,
            getRunVarBool,
            getRunVarText,

            getCropGlobal,
            getCropGlobalNum,

            getCropBmpCostNum,
        };
    }

    function setAllowedCrops(cropCodes) {
        if (!Array.isArray(cropCodes) || !cropCodes.length) {
            allowedCropSet = null;
        } else {
            allowedCropSet = new Set(cropCodes.map(c => String(c)));
        }
        // Scenario cards show crop BMP costs too
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
            { key: 'prod_cost_ref_usd_ha', label: 'Production cost REF (USD/ha — baseline default)' },
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
                        if (k.key === 'crop_price_usd_per_t') {
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
                        }
                        
                        // REF: allow override stored in currentCropVars; fallback to baseline
                        const overrideRow = c.vars.find(v => v.key === 'prod_cost_ref_usd_ha');
                        const overrideVal = (overrideRow?.value_num == null) ? null : Number(overrideRow.value_num);
            
                        const baseVal = cropRefByCrop.get(String(c.crop_code));
                        const shownVal = (overrideVal != null && Number.isFinite(overrideVal)) ? overrideVal : baseVal;
            
                        const shown = (shownVal == null) ? '' : String(shownVal);
                        const hint  = baselineRunId ? `baseline run_id: ${baselineRunId}` : 'baseline not found';
            
                        return `
                          <td>
                            <input class="form-control form-control-sm mono mca-crop-ref"
                                   data-crop="${escapeHtml(c.crop_code)}"
                                   data-key="prod_cost_ref_usd_ha"
                                   type="number" step="any"
                                   value="${escapeHtml(shown)}">
                            <div class="form-text small text-muted">${escapeHtml(hint)}</div>
                          </td>
                        `;
                    }).join('')}
                  </tr>
                `).join('')}
              </tbody>
            </table>
          </div>
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

        els.mcaCropGlobalsWrap.querySelectorAll('.mca-crop-ref').forEach(inp => {
            inp.addEventListener('input', () => {
                const crop = inp.dataset.crop;
                const key  = inp.dataset.key; // prod_cost_ref_usd_ha
                const txt  = String(inp.value ?? '').trim();
                const num  = (txt === '') ? null : Number(txt);

                upsert(crop, key, {
                    data_type: 'number',
                    value_num: (num !== null && Number.isFinite(num)) ? num : null
                });
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

        els.mcaScenarioCards.innerHTML = included.map(r => {
            const st = runInputs.get(r.id) || { loading: false };
            const badge = st.loading
                ? `<span class="badge text-bg-secondary">Loading inputs…</span>`
                : st.error
                    ? `<span class="badge text-bg-danger">Inputs error</span>`
                    : st.ok
                        ? `<span class="badge text-bg-success">Inputs ready</span>`
                        : `<span class="badge text-bg-secondary">Inputs pending</span>`;

            const bmpCount = Array.isArray(st.crop_bmp_cost) ? st.crop_bmp_cost.length : 0;

            // Run-specific crops
            const runCrops = getRunCropList(r.id);
            const cropRows = (st.crop_bmp_cost || []).filter(row => runCrops.includes(String(row.crop_code)));

            // current values for the 5 scenario vars
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

            const cropTable = runCrops.length ? `
              <div class="mt-2">
                <div class="small text-muted mb-1">Crop BMP production cost (USD/ha) — crops in this scenario</div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0">
                    <thead>
                      <tr>
                        <th>Crop</th>
                        <th style="width:220px">Production cost BMP (USD/ha)</th>
                      </tr>
                    </thead>
                    <tbody>
                      ${runCrops.map(code => {
                        const row = cropRows.find(x => String(x.crop_code) === String(code));
                        const name = row?.crop_name || code;
                        const val = (row?.value_num != null) ? row.value_num : '';
                        return `
                          <tr>
                            <td>
                              <div class="fw-semibold">${escapeHtml(name)}</div>
                              <div class="text-muted small mono">${escapeHtml(code)}</div>
                            </td>
                            <td>
                              <input class="form-control form-control-sm mono mca-crop-bmp"
                                     data-run-id="${r.id}"
                                     data-crop="${escapeHtml(code)}"
                                     type="number" step="any"
                                     value="${escapeHtml(String(val))}">
                            </td>
                          </tr>
                        `;
                    }).join('')}
                    </tbody>
                  </table>
                </div>
              </div>
            ` : `
              <div class="mt-2 small text-muted">
                No crop list available for this scenario (run ${r.id}).
              </div>
            `;

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
        
                  <div class="mt-2 small text-muted">
                    Run inputs: ${st.ok ? 'loaded' : 'not loaded'} • BMP cost rows: ${bmpCount}
                  </div>
        
                  ${st.error ? `<div class="small text-danger mt-1">${escapeHtml(st.error)}</div>` : ``}
        
                  <div class="mt-2">
                    <div class="small text-muted mb-1">Scenario variables (local edits, not saved)</div>
                    <div class="row g-2">
                      ${varsHtml}
                    </div>
                  </div>
        
                  ${cropTable}
                </div>
              </div>
            `;
        }).join('');

        // wire reload
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

        // wire scenario var edits
        els.mcaScenarioCards.querySelectorAll('.mca-run-var').forEach(inp => {
            inp.addEventListener('input', () => {
                const runId = Number(inp.dataset.runId);
                const key = String(inp.dataset.key || '');
                const txt = String(inp.value ?? '').trim();
                const num = (txt === '') ? null : Number(txt);
                upsertRunVarValue(runId, key, (num != null && Number.isFinite(num)) ? num : null);
                // no re-render needed; but safe to keep consistent:
                // renderScenarioCards();
            });
        });

        // wire crop bmp edits
        els.mcaScenarioCards.querySelectorAll('.mca-crop-bmp').forEach(inp => {
            inp.addEventListener('input', () => {
                const runId = Number(inp.dataset.runId);
                const crop = String(inp.dataset.crop || '');
                const txt = String(inp.value ?? '').trim();
                const num = (txt === '') ? null : Number(txt);
                upsertCropBmpCostValue(runId, crop, (num != null && Number.isFinite(num)) ? num : null);
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
                crop_bmp_cost: json.crop_bmp_cost || [],

                // indexes for fast lookups (still local memory)
                _varsByKey: buildKeyIndex(json.variables_run || [], 'key'),
                _bmpByCrop: buildCropKeyIndex(json.crop_bmp_cost || []),
            });
        } catch (e) {
            runInputs.set(runId, { loading: false, ok: false, error: e.message || String(e) });
        }
    }

    function updateComputeEnabled() {
        if (!els.mcaComputeBtn) return;
        const ok = !!presetId && includedRunIds.size > 0;
        els.mcaComputeBtn.disabled = !ok;
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
        presetId = Number(json.preset_set_id ?? json.preset_id ?? json.id ?? null);

        defaultPresetItems = (json.items || []).map(x => ({ ...x }));
        currentPresetItems = (json.items || []).map(x => ({ ...x }));

        defaultCropVars = (json.crop_variables || []).map(x => ({ ...x }));
        currentCropVars = (json.crop_variables || []).map(x => ({ ...x }));

        const VAR_ORDER = [
            'discount_rate',
            'time_horizon_years',
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
        cropRefByCrop = new Map();
        for (const r of (json.crop_ref_cost || [])) {
            const code = String(r.crop_code);
            const num  = (r.value_num == null) ? null : Number(r.value_num);
            cropRefByCrop.set(code, Number.isFinite(num) ? num : null);
        }

        renderIndicatorsAccordion();
        renderCropGlobalsAccordion();
        renderScenarioPicker();
        renderScenarioCards();
        renderVarsForm(currentVars, els.mcaVarsForm);

        updateComputeEnabled();
    }

    async function compute(cropCode = null) {
        if (!presetId) return;

        els.mcaComputeBtn.disabled = true;
        els.mcaComputeBtn.textContent = 'Computing…';

        try {
            const fd = new FormData();
            fd.append('csrf', window.CSRF_TOKEN);
            fd.append('preset_set_id', presetId);
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
                    const wNorm = it.is_enabled ? (wInt / sumW) : 0;
                    return {
                        indicator_code: it.indicator_code,
                        // normalized proportional weight for enabled indicators only
                        weight: wNorm,
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

            const res = await fetch(`${apiBase}/mca_compute.php`, { method: 'POST', body: fd });
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || 'MCA compute failed');

            resultsCache = json;
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
        getCropBmpCostNum,
        getCropGlobalNum,
        getGlobalVarNum,
    };
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, m => (
        {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]
    ));
}