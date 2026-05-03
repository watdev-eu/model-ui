// assets/js/data-run-edit.js
(function () {
    let runEditMap = null;
    let runEditVectorSource = null;
    let runEditSelectionLayer = null;
    let runEditAllSubbasins = [];
    let runEditSelectedSubbasins = new Set();

    function escapeHtml(v) {
        return String(v ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function runEditSelectedStyle(feature) {
        const sub = Number(feature.get('Subbasin'));
        const selected = runEditSelectedSubbasins.has(sub);

        return new ol.style.Style({
            stroke: new ol.style.Stroke({
                color: selected ? '#0d6efd' : '#666',
                width: selected ? 2.5 : 1
            }),
            fill: new ol.style.Fill({
                color: selected ? 'rgba(13,110,253,0.28)' : 'rgba(108,117,125,0.10)'
            }),
            text: new ol.style.Text({
                text: String(sub || ''),
                font: '12px sans-serif',
                fill: new ol.style.Fill({ color: '#222' })
            })
        });
    }

    function renderRunEditSubbasinChecklist() {
        const wrap = document.getElementById('runEditSubbasinChecklist');
        if (!wrap) return;

        if (!runEditAllSubbasins.length) {
            wrap.innerHTML = '<div class="text-muted">No subbasins found.</div>';
            return;
        }

        wrap.innerHTML = runEditAllSubbasins.map(sub => `
        <div class="form-check">
            <input class="form-check-input run-edit-subbasin-check"
                   type="checkbox"
                   value="${sub}"
                   id="run_edit_sub_${sub}"
                   ${runEditSelectedSubbasins.has(sub) ? 'checked' : ''}>
            <label class="form-check-label" for="run_edit_sub_${sub}">
                Subbasin ${sub}
            </label>
        </div>
    `).join('');

        wrap.querySelectorAll('.run-edit-subbasin-check').forEach(cb => {
            cb.addEventListener('change', () => {
                const sub = parseInt(cb.value, 10);
                if (cb.checked) runEditSelectedSubbasins.add(sub);
                else runEditSelectedSubbasins.delete(sub);
                refreshRunEditMapStyles();
            });
        });
    }

    function refreshRunEditMapStyles() {
        if (runEditSelectionLayer) {
            runEditSelectionLayer.setStyle(runEditSelectedStyle);
        }
        renderRunEditSubbasinChecklist();
    }

    async function loadRunEditSubbasins() {
        const mapEl = document.getElementById('runEditSubbasinMap');
        const studyAreaInput = document.getElementById('runEditStudyAreaId');
        const initialInput = document.getElementById('runEditInitialSubbasins');

        if (!mapEl || !studyAreaInput || !window.ol) return;

        const studyAreaId = parseInt(studyAreaInput.value || '0', 10);
        if (!studyAreaId) return;

        try {
            const initial = JSON.parse(initialInput?.value || '[]');
            runEditSelectedSubbasins = new Set(
                Array.isArray(initial)
                    ? initial.map(v => parseInt(v, 10)).filter(v => v > 0)
                    : []
            );
        } catch (_) {
            runEditSelectedSubbasins = new Set();
        }

        if (!runEditMap) {
            runEditVectorSource = new ol.source.Vector();

            runEditSelectionLayer = new ol.layer.Vector({
                source: runEditVectorSource,
                style: runEditSelectedStyle
            });

            runEditMap = new ol.Map({
                target: 'runEditSubbasinMap',
                layers: [
                    new ol.layer.Tile({ source: new ol.source.OSM() }),
                    runEditSelectionLayer
                ],
                view: new ol.View({
                    center: [0, 0],
                    zoom: 2
                })
            });

            runEditMap.on('singleclick', evt => {
                runEditMap.forEachFeatureAtPixel(evt.pixel, feature => {
                    const sub = parseInt(feature.get('Subbasin'), 10);
                    if (!sub) return;

                    if (runEditSelectedSubbasins.has(sub)) {
                        runEditSelectedSubbasins.delete(sub);
                    } else {
                        runEditSelectedSubbasins.add(sub);
                    }

                    refreshRunEditMapStyles();
                });
            });
        } else {
            runEditMap.setTarget('runEditSubbasinMap');
        }

        const res = await fetch(`/api/study_area_subbasins_geo.php?study_area_id=${encodeURIComponent(studyAreaId)}`, {
            credentials: 'include'
        });

        const data = await res.json();

        if (!res.ok) {
            throw new Error(data.error || 'Failed to load subbasins.');
        }

        const format = new ol.format.GeoJSON();
        const features = format.readFeatures(data);

        runEditVectorSource.clear();
        runEditVectorSource.addFeatures(features);

        runEditAllSubbasins = features
            .map(f => parseInt(f.get('Subbasin'), 10))
            .filter(v => Number.isInteger(v) && v > 0)
            .sort((a, b) => a - b);

        refreshRunEditMapStyles();

        window.setTimeout(() => {
            runEditMap.updateSize();
            if (features.length) {
                runEditMap.getView().fit(runEditVectorSource.getExtent(), {
                    padding: [20, 20, 20, 20],
                    duration: 250
                });
            }
        }, 250);
    }

    function setButtonBusy(button, busy, busyText) {
        if (!button) return;

        if (!button.dataset.idleText) {
            button.dataset.idleText = button.textContent.trim();
        }

        if (busy) {
            button.disabled = true;
            button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${busyText}`;
        } else {
            button.disabled = false;
            button.textContent = button.dataset.idleText;
        }
    }

    function setStatus(message, type = 'info') {
        const el = document.getElementById('runEditStatus');
        if (!el) return;

        el.className = `alert alert-${type} mb-0`;
        el.textContent = message;
        el.classList.remove('d-none');
    }

    function syncDownloadableDate() {
        const checkbox = document.getElementById('runEditIsDownloadable');
        const date = document.getElementById('runEditDownloadableFromDate');

        if (!checkbox || !date) return;

        date.disabled = !checkbox.checked;
        if (!checkbox.checked) {
            date.value = '';
        }
    }

    function syncDefaultVisibility() {
        const isDefault = document.getElementById('runEditIsDefault');
        const visibility = document.getElementById('runEditVisibility');

        if (!isDefault || !visibility) return;

        if (isDefault.value === '1') {
            visibility.value = 'public';
            visibility.disabled = true;
        } else {
            visibility.disabled = false;
        }
    }

    document.addEventListener('change', event => {
        if (event.target?.id === 'runEditIsDownloadable') {
            syncDownloadableDate();
        }

        if (event.target?.id === 'runEditIsDefault') {
            syncDefaultVisibility();
        }
    });

    document.addEventListener('shown.bs.modal', () => {
        syncDownloadableDate();
        syncDefaultVisibility();

        loadRunEditSubbasins().catch(err => {
            console.error(err);
            showToast(err.message || 'Failed to load subbasins.', true);
        });
    });

    document.addEventListener('click', event => {
        if (event.target.closest('#runEditSelectAllSubs')) {
            runEditAllSubbasins.forEach(sub => runEditSelectedSubbasins.add(sub));
            refreshRunEditMapStyles();
        }

        if (event.target.closest('#runEditClearSubs')) {
            runEditSelectedSubbasins.clear();
            refreshRunEditMapStyles();
        }
    });

    document.addEventListener('click', async event => {
        const btn = event.target.closest('#runEditSaveBtn');
        if (!btn) return;

        const form = document.getElementById('runEditForm');
        if (!form) return;

        syncDefaultVisibility();

        if (!form.reportValidity()) {
            return;
        }

        const fd = new FormData(form);

        fd.set(
            'selected_subbasins_json',
            JSON.stringify(Array.from(runEditSelectedSubbasins).sort((a, b) => a - b))
        );

        if (runEditSelectedSubbasins.size === 0) {
            showToast('Please select at least one subbasin.', true);
            setStatus('Please select at least one subbasin.', 'warning');
            return;
        }

        // Disabled selects are not included in FormData.
        const visibility = document.getElementById('runEditVisibility');
        if (visibility) {
            fd.set('visibility', visibility.value);
        }

        setButtonBusy(btn, true, 'Saving…');
        setStatus('Saving scenario metadata…', 'info');

        try {
            const res = await fetch('/api/runs_admin.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            });

            const data = await res.json().catch(() => ({}));

            if (!res.ok || !data.ok) {
                throw new Error(data.error || `Save failed with HTTP ${res.status}`);
            }

            setStatus('Scenario metadata saved.', 'success');
            showToast('Scenario metadata saved.');

            window.setTimeout(() => {
                window.location.reload();
            }, 500);
        } catch (err) {
            console.error(err);
            const message = err?.message || 'Failed to save scenario metadata.';
            setStatus(message, 'danger');
            showToast(message, true);
        } finally {
            setButtonBusy(btn, false, 'Save changes');
        }
    });
})();