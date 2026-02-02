// assets/js/study-area-add.js
function initStudyAreaAdd() {
    const form    = document.getElementById('studyAreaAddForm');
    if (!form) return;

    const statusEl = document.getElementById('studyAreaAddStatus');
    const submitBtn = document.getElementById('studyAreaSubmitBtn');
    const csrf = form.querySelector('input[name="csrf"]')?.value || '';

    function setStatus(msg, isError = false) {
        if (!statusEl) return;
        statusEl.textContent = msg || '';
        statusEl.className = 'small mt-2 ' + (isError ? 'text-danger' : 'text-muted');
    }

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();

        const nameInput = form.querySelector('#studyAreaName');
        if (!nameInput || !nameInput.value.trim()) {
            setStatus('Name is required.', true);
            return;
        }

        const subFile = form.querySelector('#studyAreaSubbasins')?.files[0];
        const rivFile = form.querySelector('#studyAreaReaches')?.files[0];
        if (!subFile || !rivFile) {
            setStatus('Please select both GeoJSON files.', true);
            return;
        }

        const hasRch = form.querySelector('#studyAreaHasRchResults')?.checked ? '1' : '0';

        const fd = new FormData();
        fd.append('action', 'create_from_geojson');
        fd.append('csrf', csrf);
        fd.append('name', nameInput.value.trim());
        fd.append('subbasins', subFile);
        fd.append('reaches', rivFile);
        fd.append('has_rch_results', hasRch);

        try {
            setStatus('Importing study area…');
            if (submitBtn) submitBtn.disabled = true;

            const res = await fetch('/api/study_areas_admin.php', {
                method: 'POST',
                body: fd,
            });

            const json = await res.json().catch(() => ({}));
            if (!res.ok || json.error) {
                throw new Error(json.error || `HTTP ${res.status}`);
            }

            const name      = json.name || nameInput.value.trim();
            const subCount  = json.subbasins ?? 0;
            const reachCount = json.reaches ?? 0;

            // Close modal
            const ajaxModal = document.getElementById('ajaxModal');
            if (ajaxModal) {
                const modalInstance = bootstrap.Modal.getInstance(ajaxModal);
                modalInstance?.hide();
            }

            // Dynamically update the table
            const tbody = document.getElementById('studyAreasTbody');
            if (tbody) {
                // Remove placeholder row if present
                if (tbody.children.length === 1) {
                    const onlyRow = tbody.children[0];
                    if (!onlyRow.dataset.studyAreaId) {
                        tbody.removeChild(onlyRow);
                    }
                }

                const esc = (str) =>
                    String(str ?? '').replace(/[&<>"']/g, m => ({
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#39;',
                    }[m]));

                const tr = document.createElement('tr');
                tr.setAttribute('data-study-area-id', json.id || '');
                tr.innerHTML = `
                    <td>${esc(name)}</td>
                    <td class="text-center">
                        <span class="badge bg-light text-muted">
                            ${subCount}
                        </span>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-light text-muted">
                            ${reachCount}
                        </span>
                    </td>
                    <td>
                        <span class="badge bg-success">Enabled</span>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary"
                                data-url="/modals/study_area_manage.php?id=${json.id}">
                            Manage
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            }

            // Toast after DOM update so you can actually see it
            showToast(
                `Study area "${name}" created (${subCount} subbasins, ${reachCount} reaches).`,
                false
            );

            // Optionally clear form fields if the modal is reopened
            form.reset();
            setStatus('', false);

        } catch (err) {
            console.error(err);
            setStatus(`Failed to import study area: ${err.message}`, true);
            showToast(`Failed to import study area: ${err.message}`, true);
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    });
}