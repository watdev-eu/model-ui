<?php
// src/model.php
declare(strict_types=1);

$pageTitle   = 'Model runs';
$pageButtons = [];

require_once __DIR__ . '/includes/layout.php';

require_once __DIR__ . '/classes/SwatRunRepository.php';
$runs = SwatRunRepository::all();

function renderStatusBadge(): string {
    // placeholder for now – later you can add a column `status`
    return '<span class="badge bg-success">Imported</span>';
}
?>

    <div class="card mb-3">
        <div class="card-body">
            <h1 class="title">Current runs</h1>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Run</th>
                        <th>Area</th>
                        <th>Period</th>
                        <th>Time step</th>
                        <th>Created</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$runs): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">No runs yet</td>
                        </tr>
                    <?php else: foreach ($runs as $r): ?>
                        <tr>
                            <td class="fw-medium">
                                <?= htmlspecialchars($r['run_label']) ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($r['study_area_name'] ?? ('Area #' . (int)$r['study_area'])) ?>
                            </td>
                            <td>
                                <?php
                                $start = $r['period_start'] ?? null;
                                $end   = $r['period_end'] ?? null;
                                echo htmlspecialchars(
                                        ($start ?: '—') . ' → ' . ($end ?: '—')
                                );
                                ?>
                            </td>
                            <td>
                            <span class="badge bg-secondary">
                                <?= htmlspecialchars($r['time_step']) ?>
                            </span>
                            </td>
                            <td>
                                <?= htmlspecialchars(date('Y-m-d H:i', strtotime($r['created_at']))) ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/includes/footer.php'; ?>