</div> <!-- .container-xl -->
</main> <!-- #page-content -->

</div> <!-- page-content -->
</div> <!-- wrapper -->

<?php include 'includes/modal.php'; ?>

<script src="/assets/vendor/jquery/jquery-3.7.1.min.js"></script>
<script src="/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

<script>
    // Allow typing in TinyMCE dialogs when a Bootstrap modal is open
    document.addEventListener('focusin', (e) => {
        if (e.target.closest('.tox-tinymce-aux, .tox-dialog, .tox-menu, .moxman-window, .tam-assetmanager-root')) {
            e.stopImmediatePropagation();
        }
    });
</script>

<!-- DataTables -->
<script src="/assets/vendor/datatables/jquery.dataTables.min.js"></script>
<script src="/assets/vendor/datatables/dataTables.bootstrap5.min.js"></script>

<!-- SortableJS -->
<script src="/assets/vendor/sortablejs/Sortable.min.js"></script>

<!-- FullCalendar JS -->
<script src="/assets/vendor/fullcalendar/index.global.min.js"></script>

<!-- Responsive plugin -->
<script src="/assets/vendor/datatables/dataTables.responsive.min.js"></script>
<script src="/assets/vendor/datatables/responsive.bootstrap5.min.js"></script>

<!-- Modal logic -->
<script src="/assets/js/modal-loader.js"></script>

</body>
</html>