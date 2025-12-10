// assets/js/data-runs.js
document.addEventListener('DOMContentLoaded', () => {
    const card = document.getElementById('runs-card');
    if (!card) return;

    const apiUrl = card.dataset.apiUrl || '/api/runs_admin.php';
    const csrf   = card.dataset.csrf || '';

    // --- delete modal wiring ---
    const deleteModalEl    = document.getElementById('runDeleteModal');
    const deleteLabelEl    = document.getElementById('runDeleteLabel');
    const deleteConfirmBtn = document.getElementById('runDeleteConfirmBtn');
    const deleteModal      = deleteModalEl ? new bootstrap.Modal(deleteModalEl) : null;
    let pendingDelete = null; // { id, label, tr }

    async function send(action, payload) {
        const body = new URLSearchParams({ action, csrf, ...payload });
        const res = await fetch(apiUrl, {
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

    function getDisplayDate(tr) {
        const runDate   = tr.dataset.runDate || '';
        const createdAt = tr.dataset.createdAt || '';
        let d = runDate || createdAt;
        if (!d) return '';
        if (d.length > 10) d = d.slice(0, 10);
        return d;
    }

    function updateVisibilityUI(tr, visibility) {
        tr.dataset.visibility = visibility;

        const visCell = tr.querySelector('.run-visibility');
        if (!visCell) return;

        const btnVis = tr.querySelector('.js-run-toggle-visibility');

        if (visibility === 'public') {
            visCell.innerHTML =
                '<span class="badge bg-success"><i class="bi bi-globe2 me-1"></i>Public</span>';
            if (btnVis) {
                btnVis.innerHTML = '<i class="bi bi-lock me-2"></i>Make private';
                btnVis.dataset.current = 'public';
            }
        } else {
            visCell.innerHTML =
                '<span class="badge bg-secondary"><i class="bi bi-lock-fill me-1"></i>Private</span>';
            if (btnVis) {
                btnVis.innerHTML = '<i class="bi bi-unlock me-2"></i>Make public';
                btnVis.dataset.current = 'private';
            }
        }
    }

    function updateDefaultUI(tr, isDefault) {
        const scenCell = tr.querySelector('.run-scenario');
        if (!scenCell) return;

        tr.dataset.isDefault = isDefault ? '1' : '0';

        // remove existing star & date inside this row only
        const oldStar = scenCell.querySelector('.run-default-star');
        if (oldStar) oldStar.remove();
        const oldDate = scenCell.querySelector('.run-date');
        if (oldDate) oldDate.remove();

        const btnDef = tr.querySelector('.js-run-toggle-default');

        if (isDefault) {
            // add star, no date
            const span = document.createElement('span');
            span.className = 'ms-1 text-warning run-default-star';
            span.title = 'Default run for this area';
            span.innerHTML = '<i class="bi bi-star-fill"></i>';
            scenCell.appendChild(span);

            if (btnDef) {
                btnDef.innerHTML = '<i class="bi bi-star-fill me-2"></i>Unset as default';
            }
        } else {
            // put the date back
            const d = getDisplayDate(tr);
            if (d) {
                const div = document.createElement('div');
                div.className = 'small text-muted run-date';
                div.textContent = d;
                scenCell.appendChild(div);
            }
            if (btnDef) {
                btnDef.innerHTML = '<i class="bi bi-star me-2"></i>Set as default';
            }
        }
    }

    card.addEventListener('click', async (ev) => {
        const btnDefault = ev.target.closest('.js-run-toggle-default');
        const btnVis     = ev.target.closest('.js-run-toggle-visibility');
        const btnDelete  = ev.target.closest('.js-run-delete');

        // --- toggle default ---
        if (btnDefault) {
            const tr = btnDefault.closest('tr');
            const id = Number(btnDefault.dataset.id || 0);
            if (!tr || !id) return;

            try {
                const json = await send('toggle_default', { id });
                const nowDefault = !!json.is_default;
                const visibility = json.visibility || 'private';

                updateDefaultUI(tr, nowDefault);
                updateVisibilityUI(tr, visibility); // becomes public when default

                showToast(
                    nowDefault
                        ? 'Scenario set as default for this area (and made public).'
                        : 'Scenario unset as default.',
                    false
                );
            } catch (err) {
                console.error(err);
                showToast(`Failed to toggle default: ${err.message}`, true);
            }
            return;
        }

        // --- toggle visibility ---
        if (btnVis) {
            const tr = btnVis.closest('tr');
            const id = Number(btnVis.dataset.id || 0);
            if (!tr || !id) return;

            // Front-end guard: default scenarios must stay public
            if (tr.dataset.isDefault === '1') {
                showToast('Default scenarios must remain public.', true);
                return;
            }

            try {
                const json = await send('toggle_visibility', { id });
                const vis = json.visibility || 'private';
                updateVisibilityUI(tr, vis);
                showToast(
                    vis === 'public'
                        ? 'Scenario is now public.'
                        : 'Scenario is now private.',
                    false
                );
            } catch (err) {
                console.error(err);
                showToast(`Failed to toggle visibility: ${err.message}`, true);
            }
            return;
        }

        // --- delete run ---
        if (btnDelete) {
            const tr    = btnDelete.closest('tr');
            const id    = Number(btnDelete.dataset.id || 0);
            const label = btnDelete.dataset.label || 'this scenario';
            if (!tr || !id) return;

            // Prefer Bootstrap modal; otherwise fall back to confirm()
            if (!deleteModal || !deleteLabelEl || !deleteConfirmBtn) {
                if (!confirm(`Remove scenario "${label}"? This cannot be undone.`)) {
                    return;
                }
                try {
                    await send('delete', { id });
                    tr.remove();
                    showToast(`Removed scenario "${label}".`, false);
                } catch (err) {
                    console.error(err);
                    showToast(`Failed to remove scenario: ${err.message}`, true);
                }
                return;
            }

            pendingDelete = { id, label, tr };
            deleteLabelEl.textContent = label;
            deleteModal.show();
        }
    });

    // Confirm delete handler
    if (deleteConfirmBtn && deleteModal) {
        deleteConfirmBtn.addEventListener('click', async () => {
            if (!pendingDelete) {
                deleteModal.hide();
                return;
            }
            const { id, label, tr } = pendingDelete;
            try {
                await send('delete', { id });
                tr?.remove();
                showToast(`Removed scenario "${label}".`, false);
            } catch (err) {
                console.error(err);
                showToast(`Failed to remove scenario: ${err.message}`, true);
            } finally {
                pendingDelete = null;
                deleteModal.hide();
            }
        });
    }
});