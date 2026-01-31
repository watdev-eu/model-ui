<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$displayName = 'User';
$avatarUrl = false;
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $pageTitle ?? 'WATDEV' ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/assets/css/sidebar.css" rel="stylesheet">
    <script src="/assets/js/sidebar.js" defer></script>
    <script>
        window.CSRF_TOKEN = <?= json_encode($csrfToken ?? '') ?>;
    </script>
</head>
<body>

<!-- Mobile top bar -->
<header class="bg-dark text-white shadow d-flex d-md-none justify-content-between align-items-center px-3 py-2 position-fixed top-0 start-0 w-100" style="z-index: 1046; height: 56px;">
    <span class="fw-bold">WATDEV</span>
    <button id="mobileToggleSidebar" class="btn btn-outline-light btn-sm">
        <i class="bi bi-list"></i>
    </button>
</header>

<!-- Toast container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1080">
    <div id="globalToast" class="toast align-items-center text-bg-primary border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="toastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
    function showToast(message, isError = false, undoCallback = null, undoLabel = 'Ongedaan maken', duration = 6000) {
        const toastContainer = document.getElementById('toastContainer') || createToastContainer();

        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-white ${isError ? 'bg-danger' : 'bg-success'} border-0`;
        toast.role = 'alert';
        toast.ariaLive = 'assertive';
        toast.ariaAtomic = 'true';

        const undoBtnHtml = undoCallback
            ? `<button type="button" class="btn btn-sm btn-link text-white me-2">${undoLabel}</button>`
            : '';

        toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">
                <i class="bi ${isError ? 'bi-x-circle' : 'bi-check-circle'} me-2"></i>
                ${message}
            </div>
            ${undoBtnHtml}
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    `;

        toastContainer.appendChild(toast);
        const bsToast = new bootstrap.Toast(toast, { delay: duration });
        bsToast.show();

        toast.addEventListener('hidden.bs.toast', () => toast.remove());

        // Wire undo button if present
        if (undoCallback) {
            const undoBtn = toast.querySelector('button.btn-link');
            undoBtn?.addEventListener('click', () => {
                bsToast.hide();
                undoCallback();
            });
        }
    }

    function createToastContainer() {
        const container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        document.body.appendChild(container);
        return container;
    }
</script>

<div class="d-flex">
    <!-- Sidebar -->
    <nav id="sidebar" class="bg-dark text-white flex-column">
        <div class="sidebar-header d-flex justify-content-between align-items-center p-3">
            <span class="fs-5 fw-bold">WATDEV</span>

            <!-- ✅ Desktop collapse toggle -->
            <button class="btn btn-sm btn-outline-light d-none d-md-block" id="toggleSidebar">
                <i class="bi bi-chevron-left"></i>
            </button>

            <!-- ✅ Mobile close toggle -->
            <button class="btn btn-sm btn-outline-light d-md-none" id="mobileCloseSidebar">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <ul class="nav nav-pills flex-column mb-auto text-white">
            <?php
            $navItems = [
                    [
                            'label' => 'Home',
                            'href'  => '/index.php',
                            'icon'  => 'house',
                            'title' => 'Home',
                    ],
                    [
                            'label' => 'Model runs',
                            'href'  => '/model.php',
                            'icon'  => 'play-circle',
                            'title' => 'Inspect model runs',
                    ],
                    [
                            'label' => 'Results',
                            'href'  => '/results.php',
                            'icon'  => 'bar-chart-line',
                            'title' => 'Model results per study area',
                    ],

                    // --- Data & admin tools ---
                    [
                            'label' => 'Data',
                            'href'  => '/data.php',
                            'icon'  => 'database',
                            'title' => 'Manage study areas, crops and runs',
                    ],
                    [
                            'label' => 'Import',
                            'href'  => '/import.php',
                            'icon'  => 'cloud-upload',
                            'title' => 'Import model runs from CSV',
                    ],
                    [
                            'label' => 'Migrate',
                            'href'  => '/migrate.php',
                            'icon'  => 'arrow-repeat',
                            'title' => 'Apply database migrations',
                    ],
            ];

            $currentPage = $_SERVER['PHP_SELF'];

            foreach ($navItems as $item) {
                $isActive = (strpos($currentPage, $item['href']) !== false) ? 'active' : 'text-white';
                echo <<<HTML
        <li>
            <a href="{$item['href']}" class="nav-link {$isActive}" data-bs-toggle="tooltip" title="{$item['title']}">
                <i class="bi bi-{$item['icon']}"></i> <span>{$item['label']}</span>
            </a>
        </li>
        HTML;
            }
            ?>
        </ul>

        <!-- User dropdown -->
        <div class="dropdown mt-auto p-3 border-top">
            <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle"
               id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">

                <?php if ($avatarUrl): ?>
                    <img src="<?= h($avatarUrl) ?>" alt="Profielfoto" width="32" height="32"
                         class="rounded-circle me-2" style="object-fit:cover;">
                <?php else: ?>
                    <!-- Fallback: first initial in a circular badge, same size -->
                    <span class="rounded-circle bg-secondary text-white d-inline-flex align-items-center justify-content-center me-2"
                          style="width:32px;height:32px;font-weight:600;">
                <?= h(mb_strtoupper(mb_substr($displayName, 0, 1))) ?>
            </span>
                <?php endif; ?>

                <strong><?= h($displayName) ?></strong>
            </a>

            <ul class="dropdown-menu dropdown-menu-dark text-small shadow" aria-labelledby="userDropdown">
                <li><a class="dropdown-item" href="#">Instellingen</a></li>
                <li><a class="dropdown-item" href="#">Profile</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="#">Logout</a></li>
            </ul>
            <span class="d-block mt-2">
                <i class="bi bi-git me-1"></i>
                v<?= h(app_version_short()) ?>
                <?php $bd = app_build_date(); if ($bd): ?>
                    <span class="ms-2"><i class="bi bi-clock me-1"></i><?= h($bd) ?></span>
                <?php endif; ?>
            </span>
        </div>
    </nav>

    <!-- Main column with sticky topbar and scrollable content -->
    <div class="flex-grow-1 pt-5 pt-md-0">
        <?php include 'includes/topbar.php'; ?>

        <main id="page-content" class="p-4">
            <div class="container-xl">