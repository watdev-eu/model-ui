<!-- Reusable AJAX Modal -->
<div class="modal fade" id="ajaxModal" tabindex="-1" aria-labelledby="ajaxModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">

            <!-- Universal modal header with close button -->
            <div class="modal-header">
                <h5 class="modal-title" id="ajaxModalLabel">...</h5> <!-- will be replaced -->
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Sluiten"></button>
            </div>

            <div class="modal-body p-0">
                <div id="ajaxModalContent">
                    <div class="text-center p-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-3">Laden...</p>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Bevestiging vereist</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="confirmModalBody">Weet je het zeker?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuleren</button>
                <button type="button" class="btn btn-danger" id="confirmModalConfirmBtn">Verwijderen</button>
            </div>
        </div>
    </div>
</div>