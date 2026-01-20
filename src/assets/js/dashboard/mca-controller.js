// assets/js/dashboard/mca-controller.js
export function initMcaController({ apiBase, els }) {
    let studyAreaId = null;
    let presetId = null;
    let resultsCache = null;

    let defaultPresetItems = [];   // pristine from API
    let currentPresetItems = [];   // user overrides (client-side)

    let defaultVars = [];   // pristine from API
    let currentVars = [];   // user edits (client-side)

    let defaultCropVars = [];
    let currentCropVars = [];

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
        els.mcaWeightSum.textContent = enabledWeightSum(items).toFixed(3);
    }

    function renderEditTable(items) {
        const thead = els.mcaEditTable?.querySelector('thead');
        const tbody = els.mcaEditTable?.querySelector('tbody');
        if (!thead || !tbody) return;

        thead.innerHTML = `
          <tr>
            <th style="width:70px">Use</th>
            <th>Indicator</th>
            <th style="width:160px">Direction</th>
            <th style="width:160px">Weight</th>
          </tr>
        `;

        tbody.innerHTML = items.map((it, idx) => {
            const code = String(it.indicator_code ?? '');
            const name = String(it.indicator_name ?? code);
            const dir  = (it.direction === 'neg' || it.direction === 'pos') ? it.direction : 'pos';
            const w    = Number(it.weight ?? 0);

            return `
              <tr data-idx="${idx}">
                <td><input class="form-check-input mca-en" type="checkbox" ${it.is_enabled ? 'checked' : ''}></td>
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
                  <input class="form-control form-control-sm mca-w" type="number" step="0.001" min="0" max="1"
                         value="${Number.isFinite(w) ? w : 0}">
                </td>
              </tr>
            `;
        }).join('');

        // wire row inputs
        tbody.querySelectorAll('tr[data-idx]').forEach(tr => {
            const idx = parseInt(tr.dataset.idx, 10);
            const en  = tr.querySelector('.mca-en');
            const dir = tr.querySelector('.mca-dir');
            const w   = tr.querySelector('.mca-w');

            const sync = () => {
                items[idx].is_enabled = !!en.checked;
                items[idx].direction  = dir.value;
                items[idx].weight     = Number(w.value || 0);
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

    function renderVarsForm(vars) {
        if (!els.mcaVarsForm) return;

        els.mcaVarsForm.innerHTML = vars.map((v, idx) => {
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

        els.mcaVarsForm.querySelectorAll('.mca-var').forEach(inp => {
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

    function openEditModal() {
        // clone so Cancel/close doesn’t mutate current
        const draftItems = currentPresetItems.map(x => ({ ...x }));
        const draftVars  = currentVars.map(x => ({ ...x }));
        const draftCropVars = currentCropVars.map(x => ({ ...x }));

        renderEditTable(draftItems);
        renderVarsForm(draftVars);
        renderCropVarsForm(draftCropVars);

        els.mcaResetBtn.onclick = () => {
            const freshItems = defaultPresetItems.map(x => ({ ...x }));
            const freshVars  = defaultVars.map(x => ({ ...x }));
            renderEditTable(freshItems);
            renderVarsForm(freshVars);

            draftItems.length = 0; freshItems.forEach(x => draftItems.push(x));
            draftVars.length  = 0; freshVars.forEach(x => draftVars.push(x));

            const freshCropVars = defaultCropVars.map(x => ({ ...x }));
            renderCropVarsForm(freshCropVars);

            draftCropVars.length = 0; freshCropVars.forEach(x => draftCropVars.push(x));
        };

        els.mcaSaveOverridesBtn.onclick = () => {
            const sum = enabledWeightSum(draftItems);
            if (Math.abs(sum - 1.0) > 1e-6) {
                setEditError(`Weights must sum to 1.000 (currently ${sum.toFixed(3)}).`);
                return;
            }

            currentPresetItems = draftItems.map(x => ({ ...x }));
            currentVars = draftVars.map(x => ({ ...x }));
            currentCropVars = draftCropVars.map(x => ({ ...x }));
            setEditError(null);

            const modal = bootstrap.Modal.getOrCreateInstance(els.mcaEditModal);
            modal.hide();
        };

        const modal = bootstrap.Modal.getOrCreateInstance(els.mcaEditModal);
        modal.show();
    }

    async function loadActivePreset(saId) {
        studyAreaId = saId;

        els.mcaPreset.disabled = true;
        els.mcaPreset.innerHTML = '<option>Loading…</option>';

        const res = await fetch(`${apiBase}/mca_preset_active.php?study_area_id=${saId}`);
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Failed to load preset');

        presetId = json.preset.id;

        // single option for now
        els.mcaPreset.innerHTML = `<option value="${presetId}">${json.preset.name}</option>`;
        els.mcaPreset.value = String(presetId);
        els.mcaPreset.disabled = false;

        // store pristine defaults + current editable copy
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

        els.mcaComputeBtn.disabled = false;
        els.mcaEditBtn.disabled = false;
        els.mcaCloneBtn.disabled = true;
    }

    function renderCropVarsForm(rows) {
        if (!els.mcaCropVarsForm) return;

        // Group by crop
        const byCrop = new Map();
        for (const r of rows) {
            const code = r.crop_code;
            if (!byCrop.has(code)) byCrop.set(code, { crop_code: code, crop_name: r.crop_name, vars: [] });
            byCrop.get(code).vars.push(r);
        }

        els.mcaCropVarsForm.innerHTML = Array.from(byCrop.values()).map((c) => {
            // only crop_price_usd_per_t for now
            const price = c.vars.find(v => v.key === 'crop_price_usd_per_t');
            const val = price?.value_num ?? '';

            return `
      <div class="col-12 col-md-6">
        <label class="form-label mb-1">
          ${escapeHtml(c.crop_name)} <span class="text-muted small">(${escapeHtml(c.crop_code)})</span>
        </label>
        <input class="form-control form-control-sm mca-crop-var"
               data-crop="${escapeHtml(c.crop_code)}"
               data-key="crop_price_usd_per_t"
               type="number" step="any"
               value="${escapeHtml(String(val ?? ''))}">
        <div class="form-text small">Crop price (USD/t)</div>
      </div>
    `;
        }).join('');

        els.mcaCropVarsForm.querySelectorAll('.mca-crop-var').forEach(inp => {
            inp.addEventListener('input', () => {
                const crop = inp.dataset.crop;
                const key  = inp.dataset.key;
                const txt  = String(inp.value ?? '').trim();
                const num  = (txt === '') ? null : Number(txt);

                const row = rows.find(r => r.crop_code === crop && r.key === key);
                if (!row) return;
                row.value_num = (num !== null && Number.isFinite(num)) ? num : null;
            });
        });
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

            // send overrides
            fd.append('preset_items_json', JSON.stringify(
                currentPresetItems.map(it => ({
                    indicator_code: it.indicator_code,
                    weight: Number(it.weight),
                    direction: it.direction,
                    is_enabled: !!it.is_enabled,
                }))
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
            els.mcaComputeBtn.disabled = false;
        }
    }

    function getScenarioScore(runId) {
        if (!resultsCache) return null;
        const t = resultsCache.totals.find(r => r.run_id == runId);
        return t ? t.total_weighted_score : null;
    }

    // ---- UI wiring ----
    els.mcaEditBtn?.addEventListener('click', openEditModal);
    els.mcaComputeBtn?.addEventListener('click', () => compute());

    return {
        loadActivePreset,
        compute,
        getScenarioScore,
        hasResults: () => !!resultsCache,
    };
}

// tiny helper
function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, m => (
        {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]
    ));
}