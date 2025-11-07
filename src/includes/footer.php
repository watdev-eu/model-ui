</div> <!-- .container-xl -->
</main> <!-- #page-content -->

</div> <!-- page-content -->
</div> <!-- wrapper -->

<?php include 'includes/modal.php'; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Allow typing in TinyMCE dialogs when a Bootstrap modal is open
    document.addEventListener('focusin', (e) => {
        if (e.target.closest('.tox-tinymce-aux, .tox-dialog, .tox-menu, .moxman-window, .tam-assetmanager-root')) {
            e.stopImmediatePropagation();
        }
    });
</script>

<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<!-- SortableJS -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<!-- FullCalendar JS -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>

<!-- Responsive plugin -->
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<!-- Your modal logic -->
<script src="/assets/js/modal-loader.js"></script>

</body>
</html>