<?php
// src/modals/crops_add.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
?>
<script>
    ModalUtils.setModalTitle('Add crops');
</script>

<div class="modal-body">
    <p class="small text-muted">
        Add one or more crops. You can leave name empty for now and fill it in later.
    </p>

    <form id="cropsAddModalForm">
        <div id="cropsModalRows" class="d-flex flex-column gap-2 mb-3">
            <!-- Rows will be added by JS -->
        </div>

        <button type="button"
                class="btn btn-outline-secondary btn-sm mb-3"
                id="addCropsModalRow">
            + Add row
        </button>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
                Cancel
            </button>
            <button type="submit" class="btn btn-primary btn-sm">
                Save crops
            </button>
        </div>
    </form>
</div>

<script src="/assets/js/crops-add-modal.js"
        data-modal-script
        data-init-function="initCropsAddModal"></script>