// assets/js/data-mca-defaults.js
document.addEventListener('DOMContentLoaded', () => {
    const card = document.getElementById('mca-defaults-card');
    if (!card) return;

    const apiUrl = card.dataset.apiUrl || '/api/mca_defaults_admin.php';
    const csrf   = card.dataset.csrf || '';

    const selArea = document.getElementById('mcaDefaultsStudyAreaSelect');
    const varSetLabel = document.getElementById('mcaDefaultsVarSetLabel');
    const cropsCount  = document.getElementById('mcaDefaultsCropsCount');
    const statusEl    = document.getElementById('mcaDefaultsStatus');

    const globalWrap = document.getElementById('mcaDefaultsGlobalForm');
    const cropWrap   = document.getElementById('mcaDefaultsCropTableWrap');

    const btnReload = document.getElementById('mcaDefaultsReloadBtn');
    const btnSave   = document.getElementById('mcaDefaultsSaveBtn');

    const runFormWrap   = document.getElementById('mcaDefaultsRunForm');
    const runCropBlock  = document.getElementById('mcaDefaultsRunCropBlock');
    const runCropWrap   = document.getElementById('mcaDefaultsRunCropTableWrap');

    let state = {
        study_area_id: null,
        variable_set: null,

        globals: [],       // [{key,name,unit,data_type,value_*}]
        cropVars: [],      // rows from API
        cropsInRuns: [],   // ["CORN", ...]

        run_id: null,
        runGlobals: [],
        runCropVars: [],      // [{crop_code,crop_name,key,value_num...}]
        runCropsInRun: [],    // ["CORN", ...]

        dirty: false,
    };

    function setStatus(msg, isError=false) {
        if (!statusEl) return;
        statusEl.textContent = msg || '';
        statusEl.className = 'small ' + (isError ? 'text-danger' : 'text-muted');
    }

    function setDirty(d) {
        state.dirty = !!d;
        if (btnSave) btnSave.disabled = !state.study_area_id || !state.variable_set || !state.dirty;
    }

    async function getJson(url) {
        const res = await fetch(url, { method: 'GET' });
        const json = await res.json().catch(() => ({}));
        if (!res.ok || json.error) throw new Error(json.error || `HTTP ${res.status}`);
        return json;
    }

    async function post(action, payload) {
        const body = new URLSearchParams({ action, csrf, ...payload });
        const res = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok || json.error) throw new Error(json.error || `HTTP ${res.status}`);
        return json;
    }

    function escHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }

    function renderRuns(runs, selectedRunId = null) {
        const selRun = document.getElementById('mcaDefaultsRunSelect');
        if (!selRun) return;

        if (!runs.length) {
            selRun.innerHTML = `<option value="">No scenarios for this area</option>`;
            selRun.disabled = true;
            return;
        }

        selRun.innerHTML =
            `<option value="">Select scenario…</option>` +
            runs.map(r => `<option value="${r.id}">${escHtml(r.run_label)}</option>`).join('');

        selRun.disabled = false;

        if (selectedRunId) selRun.value = String(selectedRunId);
    }

    function renderRunGlobals(vars) {
        const wrap = document.getElementById('mcaDefaultsRunForm');
        if (!wrap) return;

        wrap.innerHTML = (vars || []).map((v, idx) => {
            const name = v.name || v.key;
            const unit = v.unit ? ` <span class="text-muted small">(${escHtml(v.unit)})</span>` : '';
            const val  = v.value_num ?? '';

            return `
        <div class="col-12 col-md-6">
            <label class="form-label mb-1">${escHtml(name)}${unit}</label>
            <input class="form-control form-control-sm mono mca-run"
                   data-idx="${idx}"
                   type="number"
                   step="any"
                   value="${escHtml(val)}">
            ${v.description ? `<div class="form-text small">${escHtml(v.description)}</div>` : ''}
        </div>`;
        }).join('');

        wrap.querySelectorAll('.mca-run').forEach(inp => {
            const idx = Number(inp.dataset.idx);
            const v = state.runGlobals[idx];
            inp.addEventListener('input', () => {
                const txt = inp.value.trim();
                v.value_num = txt === '' ? null : Number(txt);
                if (!Number.isFinite(v.value_num)) v.value_num = null;
                setDirty(true);
            });
        });
    }

    function renderStudyAreas(areas, selectedId=null) {
        if (!selArea) return;
        const opts = (areas || []).map(a => {
            const dis = a.enabled ? '' : ' (disabled)';
            return `<option value="${a.id}">${escHtml(a.name)}${escHtml(dis)}</option>`;
        }).join('');
        selArea.innerHTML = `<option value="">Select study area…</option>` + opts;
        if (selectedId) selArea.value = String(selectedId);
    }

    function renderGlobals(vars) {
        if (!globalWrap) return;

        // simple 2-col form like your modal
        globalWrap.innerHTML = (vars || []).map((v, idx) => {
            const key = String(v.key);
            const name = v.name || key;
            const unit = v.unit ? ` <span class="text-muted small">(${escHtml(v.unit)})</span>` : '';
            const dt = v.data_type || 'number';

            let val = '';
            if (dt === 'bool') val = v.value_bool ? 'checked' : '';
            else if (dt === 'number') val = (v.value_num ?? '') === null ? '' : String(v.value_num ?? '');
            else val = String(v.value_text ?? '');

            if (dt === 'bool') {
                return `
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input mca-global" type="checkbox" data-idx="${idx}" ${val}>
              <label class="form-check-label">${escHtml(name)}${unit}</label>
              ${v.description ? `<div class="form-text small">${escHtml(v.description)}</div>` : ''}
            </div>
          </div>
        `;
            }

            return `
        <div class="col-12 col-md-6">
          <label class="form-label mb-1">${escHtml(name)}${unit}</label>
          <input class="form-control form-control-sm mono mca-global"
                 data-idx="${idx}"
                 type="${dt === 'number' ? 'number' : 'text'}"
                 step="any"
                 value="${escHtml(val)}">
          ${v.description ? `<div class="form-text small">${escHtml(v.description)}</div>` : ''}
        </div>
      `;
        }).join('');

        globalWrap.querySelectorAll('.mca-global').forEach(inp => {
            const idx = Number(inp.dataset.idx || 0);
            const v = state.globals[idx];
            if (!v) return;

            const dt = v.data_type || 'number';
            const evt = (dt === 'bool') ? 'change' : 'input';

            inp.addEventListener(evt, () => {
                if (dt === 'bool') {
                    v.value_bool = !!inp.checked;
                } else if (dt === 'number') {
                    const txt = String(inp.value ?? '').trim();
                    v.value_num = (txt === '') ? null : Number(txt);
                    if (v.value_num !== null && !Number.isFinite(v.value_num)) v.value_num = null;
                } else {
                    const txt = String(inp.value ?? '').trim();
                    v.value_text = (txt === '') ? null : txt;
                }
                setDirty(true);
            });
        });
    }

    function renderRunCropTable(rows, cropCodesInRun) {
        if (!runCropWrap || !runCropBlock) return;

        const runCrops = (cropCodesInRun || []).map(String);

        if (!runCrops.length) {
            runCropWrap.innerHTML = `<div class="text-muted small">No crops found for this scenario.</div>`;
            runCropBlock.style.display = '';
            return;
        }

        // index rows by crop_code
        const byCrop = new Map();
        for (const r of (rows || [])) byCrop.set(String(r.crop_code), r);

        runCropWrap.innerHTML = `
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Crop</th>
                  <th style="min-width:260px">Production cost BMP (USD/ha)</th>
                </tr>
              </thead>
              <tbody>
                ${runCrops.map(code => {
                    const row = byCrop.get(code);
                    const name = row?.crop_name || code;
                    const val = (row?.value_num ?? '') === null ? '' : String(row?.value_num ?? '');
                    return `
                    <tr>
                      <td>
                        <div class="fw-semibold">${escHtml(name)}</div>
                        <div class="text-muted small mono">${escHtml(code)}</div>
                      </td>
                      <td>
                        <input class="form-control form-control-sm mono mca-run-crop"
                               data-crop="${escHtml(code)}"
                               type="number" step="any"
                               value="${escHtml(val)}">
                      </td>
                    </tr>
                  `;
                }).join('')}
              </tbody>
            </table>
          `;

        runCropBlock.style.display = '';

        runCropWrap.querySelectorAll('.mca-run-crop').forEach(inp => {
            inp.addEventListener('input', () => {
                const crop = String(inp.dataset.crop || '');
                const txt  = String(inp.value ?? '').trim();
                const num  = (txt === '') ? null : Number(txt);

                const key = 'prod_cost_bmp_usd_ha';

                let row = state.runCropVars.find(r => String(r.crop_code) === crop && String(r.key) === key);
                if (!row) {
                    row = { crop_code: crop, crop_name: crop, key, data_type: 'number' };
                    state.runCropVars.push(row);
                }
                row.value_num = (num !== null && Number.isFinite(num)) ? num : null;

                setDirty(true);
            });
        });
    }

    function groupCropVars(rows) {
        // rows: [{crop_code,crop_name,key,value_num...}]
        const byCrop = new Map();
        for (const r of (rows || [])) {
            const c = String(r.crop_code);
            if (!byCrop.has(c)) byCrop.set(c, { crop_code: c, crop_name: r.crop_name || c, vars: [] });
            byCrop.get(c).vars.push(r);
        }
        return Array.from(byCrop.values());
    }

    function renderCropTable(rows) {
        if (!cropWrap) return;

        const KEYS = [
            { key: 'crop_price_usd_per_t', label: 'Crop price (USD/t)' },
        ];

        const crops = groupCropVars(rows);

        if (!crops.length) {
            cropWrap.innerHTML = `<div class="text-muted small">No crops found in runs for this study area.</div>`;
            return;
        }

        cropWrap.innerHTML = `
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Crop</th>
            ${KEYS.map(k => `<th style="min-width:220px">${escHtml(k.label)}</th>`).join('')}
          </tr>
        </thead>
        <tbody>
          ${crops.map(c => `
            <tr>
              <td>
                <div class="fw-semibold">${escHtml(c.crop_name || c.crop_code)}</div>
                <div class="text-muted small mono">${escHtml(c.crop_code)}</div>
              </td>
              ${KEYS.map(k => {
            const row = c.vars.find(v => String(v.key) === k.key);
            const val = (row?.value_num ?? '') === null ? '' : String(row?.value_num ?? '');
            return `
                  <td>
                    <input class="form-control form-control-sm mono mca-crop"
                      data-crop="${escHtml(c.crop_code)}"
                      data-key="${escHtml(k.key)}"
                      type="number" step="any"
                      value="${escHtml(val)}">
                  </td>
                `;
        }).join('')}
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;

        cropWrap.querySelectorAll('.mca-crop').forEach(inp => {
            inp.addEventListener('input', () => {
                const crop = inp.dataset.crop;
                const key  = inp.dataset.key;
                const txt  = String(inp.value ?? '').trim();
                const num  = (txt === '') ? null : Number(txt);

                // upsert into state.cropVars (keep row shape similar to API)
                let row = state.cropVars.find(r => String(r.crop_code) === String(crop) && String(r.key) === String(key));
                if (!row) {
                    row = { crop_code: crop, crop_name: crop, key, data_type: 'number' };
                    state.cropVars.push(row);
                }
                row.value_num = (num !== null && Number.isFinite(num)) ? num : null;

                setDirty(true);
            });
        });
    }

    function renderMeta() {
        varSetLabel.textContent = state.variable_set ? `#${state.variable_set.id} (${state.variable_set.name})` : '—';
        cropsCount.textContent  = Array.isArray(state.cropsInRuns) ? String(state.cropsInRuns.length) : '—';
    }

    async function loadStudyAreasOnly() {
        setStatus('Loading study areas…');
        const json = await getJson(`${apiUrl}`);
        renderStudyAreas(json.study_areas || []);
        setStatus('');
    }

    async function loadForStudyArea(studyAreaId) {
        setStatus('Loading MCA defaults…');
        setDirty(false);

        const json = await getJson(`${apiUrl}?study_area_id=${encodeURIComponent(studyAreaId)}`);

        // Always refresh areas list (in case changed)
        renderStudyAreas(json.study_areas || [], studyAreaId);

        state.study_area_id = Number(json.study_area_id || 0) || null;
        state.variable_set = json.variable_set || null;
        state.globals = (json.variables_global || []).map(x => ({ ...x }));
        state.cropVars = (json.crop_variables || []).map(x => ({ ...x }));
        state.cropsInRuns = json.crops_in_runs || [];

        renderRuns(json.runs || []);

        state.run_id = null;
        state.runGlobals = [];

        renderMeta();
        renderGlobals(state.globals);
        renderCropTable(state.cropVars);

        state.run_id = null;
        state.runGlobals = [];
        state.runCropVars = [];
        state.runCropsInRun = [];

        runFormWrap?.replaceChildren();
        runCropWrap?.replaceChildren();
        if (runCropBlock) runCropBlock.style.display = 'none';

        if (!state.variable_set) {
            setStatus(json.note || 'No default MCA variable set for this area.', true);
        } else {
            setStatus('');
        }

        // enable save only if var set exists
        if (btnSave) btnSave.disabled = !state.study_area_id || !state.variable_set || !state.dirty;
    }

    async function loadForRun(runId) {
        if (!state.study_area_id) return;

        setStatus('Loading scenario inputs…');
        setDirty(false);

        const json = await getJson(
            `${apiUrl}?study_area_id=${state.study_area_id}&run_id=${runId}`
        );

        state.run_id = runId;
        state.runGlobals = (json.run_variables || []).map(x => ({ ...x }));

        // per-crop BMP cost rows
        state.runCropsInRun = json.run_crops_in_run || [];
        state.runCropVars = (json.run_crop_variables || []).map(x => ({ ...x }));

        renderRunGlobals(state.runGlobals);
        renderRunCropTable(state.runCropVars, state.runCropsInRun);

        setStatus('');
    }

    async function save() {
        if (!state.study_area_id || !state.variable_set) return;

        // 1) Always save study-area defaults (globals + crop price)
        const globalsPayload = state.globals.map(v => ({ key: v.key, value: v.value_num ?? null }));
        const cropPayload = state.cropVars.map(r => ({ crop_code: r.crop_code, key: r.key, value: r.value_num ?? null }));

        await post('save', {
            study_area_id: state.study_area_id,
            globals_json: JSON.stringify(globalsPayload),
            crop_vars_json: JSON.stringify(cropPayload),
        });

        // 2) If a scenario selected, also save run defaults + run crop BMP cost
        if (state.run_id) {
            const runGlobalsPayload = state.runGlobals.map(v => ({ key: v.key, value: v.value_num ?? null }));

            const runCropPayload = state.runCropVars.map(r => ({
                crop_code: r.crop_code,
                key: r.key,
                value: r.value_num ?? null
            }));

            await post('save_run_defaults', {
                study_area_id: state.study_area_id,
                run_id: state.run_id,
                run_globals_json: JSON.stringify(runGlobalsPayload),
                run_crop_vars_json: JSON.stringify(runCropPayload),
            });

            showToast('Saved MCA defaults + scenario inputs.', false);
        } else {
            showToast('Saved MCA defaults.', false);
        }

        setDirty(false);
    }

    // wiring
    selArea?.addEventListener('change', () => {
        const id = Number(selArea.value || 0);
        if (!id) return;
        loadForStudyArea(id);
    });

    btnReload?.addEventListener('click', () => {
        const id = Number(selArea?.value || 0);
        if (id) loadForStudyArea(id);
        else loadStudyAreasOnly();
    });

    btnSave?.addEventListener('click', save);

    const selRun = document.getElementById('mcaDefaultsRunSelect');

    selRun?.addEventListener('change', () => {
        const runId = Number(selRun.value || 0);

        // clear run UI
        state.run_id = null;
        state.runGlobals = [];
        state.runCropVars = [];
        state.runCropsInRun = [];
        runFormWrap?.replaceChildren();
        runCropWrap?.replaceChildren();
        if (runCropBlock) runCropBlock.style.display = 'none';

        if (!runId) {
            setDirty(false);
            return;
        }
        loadForRun(runId);
    });

    // boot
    (async () => {
        try {
            await loadStudyAreasOnly();
            setDirty(false);
            if (btnSave) btnSave.disabled = true;
        } catch (e) {
            console.error(e);
            setStatus(`Failed to load study areas: ${e.message}`, true);
        }
    })();
});