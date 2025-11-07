<div class="sticky-top bg-white border-bottom py-2 w-100" style="z-index: 1020;">
    <div class="topbar-inner d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 px-4">
        <div class="d-flex align-items-center gap-2">
            <h1 class="h3 m-0"><?= htmlspecialchars($pageTitle ?? 'Pagina') ?></h1>
        </div>

        <div class="btn-group">
            <?php if (!empty($pageButtons)): ?>
                <?php foreach ($pageButtons as $btn): ?>
                    <?php if (isset($btn['ajax'])): ?>
                        <button type="button"
                                class="btn btn-<?= $btn['type'] ?? 'primary' ?>"
                                data-bs-toggle="modal"
                                data-bs-target="#ajaxModal"
                                data-url="<?= $btn['ajax'] ?>">
                            <?= $btn['label'] ?>
                        </button>
                    <?php else: ?>
                        <a href="<?= $btn['href'] ?>" class="btn btn-<?= $btn['type'] ?? 'primary' ?>">
                            <?= $btn['label'] ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>