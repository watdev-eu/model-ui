// assets/js/data-crops.js
document.addEventListener('DOMContentLoaded', () => {
    const card = document.getElementById('crops-card');
    if (!card) return;

    const apiUrl   = card.dataset.apiUrl || '/api/crops_admin.php';
    const csrf     = card.dataset.csrf || '';
    const statusEl = document.getElementById('crops-status');

    // --- delete modal wiring ---
    const deleteModalEl      = document.getElementById('cropDeleteModal');
    const deleteCodeEl       = document.getElementById('cropDeleteCode');
    const deleteConfirmBtn   = document.getElementById('cropDeleteConfirmBtn');
    const deleteModal        = deleteModalEl ? new bootstrap.Modal(deleteModalEl) : null;
    let pendingDelete = null; // { code, tr }

    function escHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }

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

    // Renders/updates a row in the list
    function renderRow(crop) {
        const { code, name } = crop;
        if (!code) return;

        // 1) try to find existing row
        let tr = card.querySelector(`tr[data-code="${CSS.escape(code)}"]`);
        if (tr) {
            tr.querySelector('.crop-code').value = code;
            tr.querySelector('.crop-name').value = name || '';
            return;
        }

        // 2) choose column with fewest rows
        const cols = Array.from(card.querySelectorAll('.crops-column'));
        if (!cols.length) return;

        let targetCol = cols[0];
        let minRows = cols[0].querySelectorAll('tbody tr').length;
        for (const col of cols.slice(1)) {
            const rows = col.querySelectorAll('tbody tr').length;
            if (rows < minRows) {
                minRows = rows;
                targetCol = col;
            }
        }

        const tbody = targetCol.querySelector('tbody') || targetCol;
        const rowHtml = `
            <tr data-code="${escHtml(code)}">
                <td>
                    <div class="input-group input-group-sm">
                        <input type="text"
                               class="form-control mono crop-code"
                               maxlength="8"
                               value="${escHtml(code)}"
                               placeholder="Code">

                        <input type="text"
                               class="form-control crop-name"
                               value="${escHtml(name || '')}"
                               placeholder="Name">

                        <button type="button"
                                class="btn btn-outline-success js-crop-save"
                                title="Save">
                            <i class="bi bi-check"></i>
                        </button>
                        <button type="button"
                                class="btn btn-outline-danger js-crop-delete"
                                title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
        const temp = document.createElement('tbody');
        temp.innerHTML = rowHtml;
        tbody.appendChild(temp.firstElementChild);
    }

    // Click handler for save/delete on existing rows
    card.addEventListener('click', async (ev) => {
        const saveBtn = ev.target.closest('.js-crop-save');
        const delBtn  = ev.target.closest('.js-crop-delete');

        if (saveBtn) {
            const tr = saveBtn.closest('tr');
            if (!tr) return;
            const originalCode = tr.dataset.code || '';
            const code = tr.querySelector('.crop-code')?.value.trim() || '';
            const name = tr.querySelector('.crop-name')?.value.trim() || '';

            if (!code) {
                showToast('Code is required', true);
                return;
            }

            try {
                const json = await send('save', { code, name, original_code: originalCode });
                const saved = json.crop || { code, name };
                tr.dataset.code = saved.code;
                tr.querySelector('.crop-code').value = saved.code;
                tr.querySelector('.crop-name').value = saved.name || '';

                showToast(`Saved crop ${saved.code}`, false);
            } catch (err) {
                console.error(err);
                showToast(`Failed to save ${code}: ${err.message}`, true);
            }
        }

        if (delBtn) {
            const tr = delBtn.closest('tr');
            if (!tr) return;
            const code = tr.dataset.code;

            // If for some reason modal isn't available, fall back to confirm().
            if (!deleteModal || !deleteCodeEl || !deleteConfirmBtn) {
                if (!confirm(`Remove crop ${code}?`)) return;
                try {
                    await send('delete', { code });
                    tr.remove();
                    showToast(`Deleted crop ${code}`, false);
                } catch (err) {
                    console.error(err);
                    showToast(`Failed to delete ${code}: ${err.message}`, true);
                }
                return;
            }

            // Use Bootstrap modal
            pendingDelete = { code, tr };
            deleteCodeEl.textContent = code;
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

            const { code, tr } = pendingDelete;
            try {
                await send('delete', { code });
                tr.remove();
                showToast(`Deleted crop ${code}`, false);
            } catch (err) {
                console.error(err);
                showToast(`Failed to delete ${code}: ${err.message}`, true);
            } finally {
                pendingDelete = null;
                deleteModal.hide();
            }
        });
    }

    // Expose helpers for modal to use
    window.CropsAdmin = {
        apiUrl,
        csrf,
        send,
        renderRow,
    };
});