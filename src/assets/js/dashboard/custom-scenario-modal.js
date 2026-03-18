// assets/js/custom-scenario-modal.js

export function initCustomScenarioModal({ triggerEl }) {
    let selectedStudyArea = {
        id: 0,
        name: '',
    };

    function updateTriggerState() {
        const enabled = Number.isFinite(+selectedStudyArea.id) && +selectedStudyArea.id > 0;

        if (!triggerEl) return;

        triggerEl.disabled = !enabled;
        triggerEl.setAttribute('aria-disabled', enabled ? 'false' : 'true');
        triggerEl.title = enabled
            ? `Manage custom scenarios for ${selectedStudyArea.name || 'selected study area'}`
            : 'Select a study area first';
    }

    function buildModalUrl() {
        const params = new URLSearchParams({
            study_area_id: String(selectedStudyArea.id || 0),
            study_area_name: selectedStudyArea.name || '',
        });

        return `/modals/custom_scenarios.php?${params.toString()}`;
    }

    function openModal() {
        const enabled = Number.isFinite(+selectedStudyArea.id) && +selectedStudyArea.id > 0;
        if (!enabled) return;

        if (!window.ModalUtils?.reloadModal) {
            console.warn('[custom-scenario-modal] ModalUtils.reloadModal not available');
            return;
        }

        window.ModalUtils.reloadModal(buildModalUrl());
    }

    if (triggerEl) {
        triggerEl.addEventListener('click', (event) => {
            event.preventDefault();
            openModal();
        });
    }

    updateTriggerState();

    return {
        setStudyArea({ id, name }) {
            selectedStudyArea = {
                id: Number(id) || 0,
                name: String(name || '').trim(),
            };
            updateTriggerState();
        },

        reset() {
            selectedStudyArea = { id: 0, name: '' };
            updateTriggerState();
        },
    };
}