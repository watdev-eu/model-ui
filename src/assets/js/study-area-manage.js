// assets/js/study-area-manage.js
function initStudyAreaManage() {
    const root = document.getElementById('studyAreaManageRoot');
    if (!root) return;

    const id    = Number(root.dataset.id || 0);
    const name  = root.dataset.name || '';
    const csrf  = root.dataset.csrf || '';
    let enabled = root.dataset.enabled === '1';

    const statusBadge = document.getElementById('studyAreaStatusBadge');
    const toggleBtn   = document.getElementById('studyAreaToggleBtn');
    const deleteBtn   = document.getElementById('studyAreaDeleteBtn');

    const deleteConfirmWrapper = document.getElementById('studyAreaDeleteConfirm');
    const deleteConfirmBtn     = document.getElementById('studyAreaDeleteConfirmBtn');
    const deleteCancelBtn      = document.getElementById('studyAreaDeleteCancelBtn');

    if (!id) return;

    // --- helpers ---
    function updateStatusUI(newEnabled) {
        enabled = newEnabled;
        if (statusBadge) {
            statusBadge.textContent = newEnabled ? 'Enabled' : 'Disabled';
            statusBadge.classList.remove('bg-success', 'bg-secondary');
            statusBadge.classList.add(newEnabled ? 'bg-success' : 'bg-secondary');
        }
        if (toggleBtn) {
            toggleBtn.textContent = newEnabled ? 'Disable area' : 'Enable area';
        }

        // Also update the main table row
        const row = document.querySelector(`tr[data-study-area-id="${id}"]`);
        if (row) {
            const statusCell = row.querySelector('td:nth-child(4) .badge');
            if (statusCell) {
                statusCell.textContent = newEnabled ? 'Enabled' : 'Disabled';
                statusCell.classList.remove('bg-success', 'bg-secondary');
                statusCell.classList.add(newEnabled ? 'bg-success' : 'bg-secondary');
            }
        }
        // Keep the root's data in sync (in case modal is reused somehow)
        root.dataset.enabled = newEnabled ? '1' : '0';
    }

    async function sendAction(action, payload = {}) {
        const body = new URLSearchParams({ action, csrf, ...payload });
        const res = await fetch('/api/study_areas_admin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok || json.error) {
            throw new Error(json.error || `HTTP ${res.status}`);
        }
        return json;
    }

    // --- OpenLayers map ---
    (function initMap() {
        const mapDiv = document.getElementById('studyAreaMap');
        if (!mapDiv) return;

        if (typeof window.ol === 'undefined') {
            console.error('OpenLayers library not loaded — map cannot be displayed.');
            mapDiv.innerHTML = '<div class="text-danger p-3">Map library not loaded.</div>';
            return;
        }

        const data   = window.StudyAreaManageData || {};
        const subFC  = data.subbasins || { type: 'FeatureCollection', features: [] };
        const rivFC  = data.reaches   || { type: 'FeatureCollection', features: [] };

        const format = new ol.format.GeoJSON();
        const proj   = 'EPSG:3857'; // DB + GeoJSON + OSM all in 3857

        const subSource = new ol.source.Vector({
            features: format.readFeatures(subFC, {
                dataProjection: proj,
                featureProjection: proj,
            }),
        });

        const rivSource = new ol.source.Vector({
            features: format.readFeatures(rivFC, {
                dataProjection: proj,
                featureProjection: proj,
            }),
        });

        const subLayer = new ol.layer.Vector({
            source: subSource,
            style: new ol.style.Style({
                stroke: new ol.style.Stroke({
                    color: 'rgba(0, 80, 200, 0.7)',
                    width: 1.5,
                }),
                fill: new ol.style.Fill({
                    color: 'rgba(0, 80, 200, 0.10)',
                }),
            }),
        });

        const rivLayer = new ol.layer.Vector({
            source: rivSource,
            style: new ol.style.Style({
                stroke: new ol.style.Stroke({
                    color: 'rgba(220, 0, 0, 0.9)',
                    width: 3,
                }),
            }),
        });
        subLayer.setZIndex(5);
        rivLayer.setZIndex(10);

        const map = new ol.Map({
            target: mapDiv,
            layers: [
                new ol.layer.Tile({ source: new ol.source.OSM() }), // OSM is 3857
                subLayer,
                rivLayer,
            ],
            view: new ol.View({
                center: [0, 0], // will be overwritten by fit
                zoom: 5,
            }),
        });

        const extent = ol.extent.createEmpty();
        const subExtent = subSource.getExtent();
        const rivExtent = rivSource.getExtent();

        if (!ol.extent.isEmpty(subExtent)) ol.extent.extend(extent, subExtent);
        if (!ol.extent.isEmpty(rivExtent)) ol.extent.extend(extent, rivExtent);

        function fitToData() {
            if (!ol.extent.isEmpty(extent)) {
                map.getView().fit(extent, {
                    padding: [30, 30, 30, 30],
                    maxZoom: 11,    // slightly less aggressive zoom
                    duration: 250,
                });
            }
        }

        fitToData();

        setTimeout(() => {
            map.updateSize();
            fitToData();
        }, 200);
    })();

    // --- toggle enabled ---
    if (toggleBtn) {
        toggleBtn.addEventListener('click', async () => {
            try {
                const json = await sendAction('toggle_enabled', { id: String(id) });
                updateStatusUI(!!json.enabled);
                showToast(
                    json.enabled
                        ? `Study area "${name}" is now enabled.`
                        : `Study area "${name}" is now disabled.`,
                    false
                );
            } catch (err) {
                console.error(err);
                showToast(`Failed to update status: ${err.message}`, true);
            }
        });
    }

    // --- inline delete confirm ---
    async function doDelete() {
        await sendAction('delete', { id: String(id) });

        // Remove row from main table
        const row = document.querySelector(`tr[data-study-area-id="${id}"]`);
        if (row) row.remove();

        // Close the manage modal
        const ajaxModal = document.getElementById('ajaxModal');
        if (ajaxModal) {
            const modalInstance = bootstrap.Modal.getInstance(ajaxModal);
            modalInstance?.hide();
        }

        showToast(`Study area "${name}" has been removed.`, false);
    }

    if (deleteConfirmBtn) {
        deleteConfirmBtn.addEventListener('click', async () => {
            try {
                await doDelete();
            } catch (err) {
                console.error(err);
                showToast(`Failed to remove study area: ${err.message}`, true);
            }
        });
    }

    if (deleteCancelBtn && deleteConfirmWrapper) {
        deleteCancelBtn.addEventListener('click', () => {
            const collapse = bootstrap.Collapse.getOrCreateInstance(deleteConfirmWrapper);
            collapse.hide();
        });
    }
}