// assets/js/crops-add-modal.js
function initCropsAddModal() {
    const form  = document.getElementById('cropsAddModalForm');
    const rowsEl = document.getElementById('cropsModalRows');
    const addRowBtn = document.getElementById('addCropsModalRow');

    if (!form || !rowsEl) return;

    const CropsAdmin = window.CropsAdmin || {};

    function createRow(code = '', name = '') {
        const div = document.createElement('div');
        div.className = 'input-group input-group-sm crops-modal-row';
        div.innerHTML = `
            <input type="text"
                   class="form-control mono modal-code"
                   maxlength="8"
                   placeholder="Code"
                   value="${code}">
            <input type="text"
                   class="form-control modal-name"
                   placeholder="Name"
                   value="${name}">
            <button type="button"
                    class="btn btn-outline-danger modal-row-remove"
                    title="Remove">
                <i class="bi bi-x-lg"></i>
            </button>
        `;
        rowsEl.appendChild(div);
    }

    // initial row
    if (!rowsEl.querySelector('.crops-modal-row')) {
        createRow();
    }

    addRowBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        createRow();
    });

    rowsEl.addEventListener('click', (e) => {
        const btn = e.target.closest('.modal-row-remove');
        if (!btn) return;
        const row = btn.closest('.crops-modal-row');
        if (row) row.remove();
        if (!rowsEl.querySelector('.crops-modal-row')) {
            createRow();
        }
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const rowEls = Array.from(rowsEl.querySelectorAll('.crops-modal-row'));
        const toSave = rowEls.map(row => {
            const code = row.querySelector('.modal-code')?.value.trim() || '';
            const name = row.querySelector('.modal-name')?.value.trim() || '';
            return { code, name };
        }).filter(r => r.code); // skip empty codes

        if (!toSave.length) {
            showToast('Enter at least one code', true);
            return;
        }

        try {
            let successCount = 0;
            for (const r of toSave) {
                const json = await CropsAdmin.send('save', {
                    code: r.code,
                    name: r.name,
                });
                const saved = json.crop || r;
                CropsAdmin.renderRow(saved);
                successCount++;
            }

            showToast(`Added ${successCount} crop${successCount !== 1 ? 's' : ''}`, false);

            const modalEl = document.getElementById('ajaxModal');
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            modalInstance?.hide();
        } catch (err) {
            console.error(err);
            showToast(`Failed to add crops: ${err.message}`, true);
        }
    });
}