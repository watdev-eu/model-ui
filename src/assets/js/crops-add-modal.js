// assets/js/crops-add-modal.js
function initCropsAddModal() {
    const form  = document.getElementById('cropsAddModalForm');
    const rowsEl = document.getElementById('cropsModalRows');
    const addRowBtn = document.getElementById('addCropsModalRow');

    if (!form || !rowsEl) return;

    const CropsAdmin = window.CropsAdmin || {};

    function escHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }

    function createRow(code = '', name = '', dryMatterFraction = '') {
        const div = document.createElement('div');
        div.className = 'input-group input-group-sm crops-modal-row';
        div.innerHTML = `
            <input type="text"
                   class="form-control mono modal-code"
                   maxlength="8"
                   placeholder="Code"
                   value="${escHtml(code)}">

            <input type="text"
                   class="form-control modal-name"
                   placeholder="Name"
                   value="${escHtml(name)}">

            <input type="number"
                   class="form-control modal-dry-matter"
                   placeholder="Dry matter"
                   value="${escHtml(dryMatterFraction)}"
                   min="0"
                   max="1"
                   step="0.001"
                   title="Dry matter fraction, e.g. 0.86. Fresh yield = dry yield / dry matter fraction.">

            <button type="button"
                    class="btn btn-outline-danger modal-row-remove"
                    title="Remove">
                <i class="bi bi-x-lg"></i>
            </button>
        `;
        rowsEl.appendChild(div);
    }

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
            const dry_matter_fraction = row.querySelector('.modal-dry-matter')?.value.trim() || '';

            return { code, name, dry_matter_fraction };
        }).filter(r => r.code);

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
                    dry_matter_fraction: r.dry_matter_fraction,
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