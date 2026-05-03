// assets/js/data-run-edit.js
(function () {
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