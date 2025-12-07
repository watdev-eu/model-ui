<?php
// src/modals/run_info.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pdo = Database::pdo();
$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    echo "<div class='p-3 text-danger'>Run not found.</div>";
    return;
}

$stmt = $pdo->prepare("
    SELECT
        id,
        study_area,
        run_label,
        run_date,
        visibility,
        description,
        created_by,
        period_start,
        period_end,
        created_at,
        is_default
    FROM swat_runs
    WHERE id = :id
");
$stmt->execute([':id' => $id]);
$run = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$run) {
    echo "<div class='p-3 text-danger'>Run not found.</div>";
    return;
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>

<script>
    ModalUtils.setModalTitle("Run details: <?= h($run['run_label']) ?>");
</script>

<div class="p-3">
    <dl class="row mb-0">
        <dt class="col-sm-4">Scenario</dt>
        <dd class="col-sm-8"><?= h($run['run_label']) ?></dd>

        <dt class="col-sm-4">Study area</dt>
        <dd class="col-sm-8"><?= h(ucfirst($run['study_area'])) ?></dd>

        <dt class="col-sm-4">Run date</dt>
        <dd class="col-sm-8"><?= $run['run_date'] ? h($run['run_date']) : '—' ?></dd>

        <dt class="col-sm-4">Period</dt>
        <dd class="col-sm-8">
            <?= $run['period_start'] ? h($run['period_start']) : '—' ?>
            &rarr;
            <?= $run['period_end'] ? h($run['period_end']) : '—' ?>
        </dd>

        <dt class="col-sm-4">Visibility</dt>
        <dd class="col-sm-8"><?= h($run['visibility']) ?></dd>

        <dt class="col-sm-4">Default</dt>
        <dd class="col-sm-8"><?= !empty($run['is_default']) ? 'Yes' : 'No' ?></dd>

        <dt class="col-sm-4">Created at</dt>
        <dd class="col-sm-8"><?= h($run['created_at']) ?></dd>

        <dt class="col-sm-4">Created by</dt>
        <dd class="col-sm-8"><?= $run['created_by'] ? ('User #' . (int)$run['created_by']) : '—' ?></dd>

        <dt class="col-sm-4">Description</dt>
        <dd class="col-sm-8">
            <?= $run['description'] ? nl2br(h($run['description'])) : '<span class="text-muted">—</span>' ?>
        </dd>
    </dl>
</div>