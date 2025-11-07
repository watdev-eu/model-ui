document.addEventListener('DOMContentLoaded', function () {
    function cleanBootstrapState() {
        document.querySelector('.modal-backdrop')?.remove();
        document.body.classList.remove('modal-open');
        document.body.style = '';
    }

    function getModalParts() {
        const ajaxModal = document.getElementById('ajaxModal');
        const modalContent = document.getElementById('ajaxModalContent'); // re-query live
        return { ajaxModal, modalContent };
    }

    function initModalTooltips() {
        const { ajaxModal } = getModalParts();
        if (!ajaxModal) return;
        ajaxModal.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
            bootstrap.Tooltip.getOrCreateInstance(el, { container: 'body' });
        });
    }
    function disposeModalTooltips() {
        const { ajaxModal } = getModalParts();
        if (!ajaxModal) return;
        ajaxModal.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
            bootstrap.Tooltip.getInstance(el)?.dispose();
        });
    }

    function ensureModalSkeleton() {
        const ajaxModal = document.getElementById('ajaxModal');
        if (!ajaxModal) return null;

        // Ensure .modal-content
        let contentWrap = ajaxModal.querySelector('.modal-content');
        if (!contentWrap) {
            const dlg = ajaxModal.querySelector('.modal-dialog') || ajaxModal.appendChild(document.createElement('div'));
            if (!dlg.classList.contains('modal-dialog')) dlg.className = 'modal-dialog modal-lg';
            contentWrap = document.createElement('div');
            contentWrap.className = 'modal-content';
            dlg.appendChild(contentWrap);
        }

        // Ensure header with title + close
        let header = contentWrap.querySelector('.modal-header');
        if (!header) {
            header = document.createElement('div');
            header.className = 'modal-header';
            header.innerHTML = `
              <h5 class="modal-title" id="ajaxModalLabel"></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>`;
            contentWrap.prepend(header);
        } else if (!header.querySelector('#ajaxModalLabel')) {
            const title = document.createElement('h5');
            title.className = 'modal-title';
            title.id = 'ajaxModalLabel';
            header.insertBefore(title, header.firstChild);
        }

        // Ensure body and inner container
        let body = contentWrap.querySelector('.modal-body');
        if (!body) {
            body = document.createElement('div');
            body.className = 'modal-body';
            contentWrap.appendChild(body);
        }

        let inner = document.getElementById('ajaxModalContent');
        if (!inner) {
            inner = document.createElement('div');
            inner.id = 'ajaxModalContent';
            body.innerHTML = '';
            body.appendChild(inner);
        }
        return inner;
    }

    function clearHeaderActions() {
        const header = document.querySelector('#ajaxModal .modal-header');
        if (!header) return;

        // Remove any preview-specific action groups
        header.querySelectorAll('.js-invoice-actions').forEach(el => el.remove());

        // Preview added a spacing class to the close button; normalize it
        const closeBtn = header.querySelector('.btn-close');
        closeBtn?.classList.remove('ms-1');
    }

    function setSpinner() {
        const { modalContent } = getModalParts();
        if (!modalContent) return;
        modalContent.innerHTML = `
          <div class="text-center p-5 fade-in">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-3">Laden...</p>
          </div>`;
    }

    function loadModalContent(url) {
        if (!url) return;
        const { ajaxModal, modalContent } = getModalParts();
        ensureModalSkeleton();
        clearHeaderActions();
        if (!ajaxModal || !modalContent) return;

        const modalInstance = bootstrap.Modal.getOrCreateInstance(ajaxModal);
        disposeModalTooltips();
        setSpinner();

        const bust = url.includes('?') ? '&_=' + Date.now() : '?_=' + Date.now();

        fetch(url + bust)
            .then(res => res.text())
            .then(html => {
                // ensure target still exists (wasn't replaced)
                const { modalContent: liveContent } = getModalParts();
                if (!liveContent) return;

                liveContent.innerHTML = html;

                // run inline scripts
                liveContent.querySelectorAll('script').forEach(script => {
                    if (!script.src) {
                        try { new Function(script.innerText)(); } catch (err) {
                            console.warn('Error in inline modal script:', err);
                        }
                    }
                });

                initModalTooltips();

                // dynamic assets + init
                const scriptTag = liveContent.querySelector('[data-modal-script]');
                if (scriptTag) {
                    const cssHrefAttr = scriptTag.getAttribute('data-modal-style');
                    if (cssHrefAttr) {
                        cssHrefAttr.split(',').map(s => s.trim()).forEach(href => {
                            if (!document.querySelector(`link[href="${href}"]`)) {
                                const styleTag = document.createElement('link');
                                styleTag.rel = 'stylesheet';
                                styleTag.href = href;
                                styleTag.setAttribute('data-modal-dynamic', 'true');
                                document.head.appendChild(styleTag);
                            }
                        });
                    }

                    const jsUrl = scriptTag.getAttribute('src');
                    if (jsUrl) {
                        const dynamicScript = document.createElement('script');
                        dynamicScript.src = jsUrl;
                        dynamicScript.defer = true;
                        dynamicScript.setAttribute('data-modal-dynamic', 'true');
                        dynamicScript.onload = () => {
                            const initFunction = scriptTag.getAttribute('data-init-function');
                            if (initFunction && typeof window[initFunction] === 'function') {
                                window[initFunction]();
                            }

                            initModalTooltips();
                            requestAnimationFrame(() => initModalTooltips());
                        };
                        document.body.appendChild(dynamicScript);
                    }

                    // Auto-focus first input
                    requestAnimationFrame(() => {
                        const firstInput = liveContent.querySelector(
                            'input:not([type="hidden"]):not([disabled]):not(.d-none), select, textarea'
                        );
                        firstInput?.focus?.();
                    });
                }

                requestAnimationFrame(() => {
                    if (!ajaxModal.classList.contains('show')) {
                        modalInstance.show();
                    }
                });
            })
            .catch(() => {
                const { modalContent: liveContent } = getModalParts();
                if (liveContent) liveContent.innerHTML = '<div class="p-4 text-danger">Kon inhoud niet laden.</div>';
            });
    }

    // Click handler for elements with [data-url]
    document.addEventListener('click', function (event) {
        const target = event.target.closest('[data-url]');
        if (!target) return;
        event.preventDefault();

        const delay = target.hasAttribute('data-bs-dismiss') ? 300 : 0;
        setTimeout(() => loadModalContent(target.getAttribute('data-url')), delay);
    });

    // Reset modal on close
    const { ajaxModal } = getModalParts();
    ajaxModal?.addEventListener('hidden.bs.modal', () => {
        // remove dynamic assets
        document.querySelectorAll('script[data-modal-dynamic]').forEach(el => el.remove());
        document.querySelectorAll('link[data-modal-dynamic]').forEach(el => el.remove());

        ajaxModal.querySelectorAll('.js-invoice-actions').forEach(el => el.remove());

        // optional: clear title to avoid stale header between screens
        const label = document.getElementById('ajaxModalLabel') || ajaxModal.querySelector('.modal-title');
        if (label) label.textContent = '';

        const { modalContent } = getModalParts();
        if (modalContent) modalContent.innerHTML = '';

        cleanBootstrapState();
        disposeModalTooltips();
        ModalUtils.clearHeaderActions?.();
    });

    // expose to other code
    window.ModalUtils = window.ModalUtils || {};
    ModalUtils.reloadModal = loadModalContent;
    ModalUtils.clearHeaderActions = clearHeaderActions;
    ModalUtils.reinitTooltips = initModalTooltips;
    ModalUtils.setModalTitle = function (html) {
        const apply = () => {
            ensureModalSkeleton();
            const label =
                document.getElementById('ajaxModalLabel') ||
                document.querySelector('#ajaxModal .modal-title');
            if (label) label.innerHTML = html;
        };
        apply();
        requestAnimationFrame(apply);
        setTimeout(apply, 0);
    };
});

// Universal cancel handler (unchanged)
document.addEventListener('click', function (event) {
    const cancelBtn = event.target.closest('[data-cancel]');
    if (!cancelBtn) return;
    const form = cancelBtn.closest('form');
    const id = ModalUtils.getId(form);
    const entity = ModalUtils.getEntity(form);
    if (id && entity) {
        ModalUtils.reloadModal(`/modals/${entity}_view.php?id=${id}`);
    } else {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('ajaxModal')).hide();
    }
});

window.ModalUtils = window.ModalUtils || {};
Object.assign(window.ModalUtils, {
    getId(el) {
        const val = el?.getAttribute('data-id');
        const parsed = Number(val);
        return !isNaN(parsed) && val !== '' ? parsed : null;
    },
    getEntity(el) {
        const val = el?.getAttribute('data-entity');
        return typeof val === 'string' && val.trim() !== '' ? val.trim() : null;
    }
});