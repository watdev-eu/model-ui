/* global bootstrap: false */

function initTooltips() {
    const sidebar = document.getElementById('sidebar');
    const isCollapsed = sidebar?.classList.contains('collapsed');
    if (!isCollapsed) return;

    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(el => {
        const instance = bootstrap.Tooltip.getInstance(el);
        if (instance) instance.dispose();
        new bootstrap.Tooltip(el, { placement: 'right' });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebar');
    const mobileToggle = document.getElementById('mobileToggleSidebar');
    const desktopToggle = document.getElementById('toggleSidebar');

    // ✅ Desktop collapse toggle
    desktopToggle?.addEventListener('click', () => {
        sidebar?.classList.toggle('collapsed');
        initTooltips();
    });

    // ✅ Mobile toggle: show/hide sidebar
    mobileToggle?.addEventListener('click', () => {
        sidebar?.classList.toggle('show-sidebar');
    });

    const mobileClose = document.getElementById('mobileCloseSidebar');

    mobileClose?.addEventListener('click', () => {
        sidebar?.classList.remove('show-sidebar');
    });

    // ✅ Auto-close sidebar when clicking a nav-link on mobile
    sidebar?.querySelectorAll('a.nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth < 992) {
                sidebar.classList.remove('show-sidebar');
            }
        });
    });

    // ✅ Initialize tooltips
    initTooltips();
});