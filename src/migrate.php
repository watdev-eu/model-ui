<?php
// src/migrate.php

$pageTitle   = 'Database migrations';
$pageButtons = [];

require_once __DIR__ . '/config/app.php';
//require_admin();  // Protect the page
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/layout.php';

$pdo = Database::pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$migrationsDir = __DIR__ . '/../db/migrations';

// If the directory does not exist, just show a notice and bail out gracefully
if (!is_dir($migrationsDir)) {
    echo "<div class='card mb-3'><div class='card-body'>
            <h1 class='title mb-3'>Database migrations</h1>
            <div class='alert alert-info'>
              Migration directory not found (<code>$migrationsDir</code>).<br>
              The PostgreSQL setup currently relies on the <code>db/init/10_schema.sql</code> script only.
            </div>
          </div></div>";
    include 'includes/footer.php';
    exit;
}

// Ensure migrations table exists (PostgreSQL syntax)
$pdo->exec("
  CREATE TABLE IF NOT EXISTS migrations (
    id BIGSERIAL PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
  );
");

// Read applied migrations
$applied = $pdo->query("SELECT filename FROM migrations")->fetchAll(PDO::FETCH_COLUMN);
$appliedSet = array_flip($applied);

// Read filesystem migrations
$files = array_values(array_filter(
        scandir($migrationsDir),
        fn($f) => preg_match('/\.sql$/i', $f)
));

natsort($files);
$files = array_values($files);

$pending = array_values(array_filter($files, fn($f) => !isset($appliedSet[$f])));

// Handle execution request
if (isset($_POST['run_migrations'])) {
    $log = [];

    foreach ($pending as $file) {
        $path = $migrationsDir . '/' . $file;
        $sql  = file_get_contents($path);

        try {
            $pdo->beginTransaction();

            // Split on semicolons (simple migrations only)
            $statements = preg_split('/;(\s*[\r\n]+|$)/', $sql);

            foreach ($statements as $stmt) {
                $stmt = trim($stmt);
                if ($stmt === '') continue;

                // Strip comment-only lines starting with --
                $lines = explode("\n", $stmt);
                $lines = array_filter($lines, fn($l) => !preg_match('/^\s*--/', $l));
                $stmt  = trim(implode("\n", $lines));

                if ($stmt === '') continue; // was only comments

                $pdo->exec($stmt);
            }

            $stmt = $pdo->prepare("INSERT INTO migrations (filename) VALUES (?)");
            $stmt->execute([$file]);

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            $log[] = "<div class='text-success'>✓ Applied <b>$file</b></div>";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $log[] = "<div class='text-danger'>✗ Failed <b>$file</b>: {$e->getMessage()}</div>";
            break;
        }
    }
}
?>

    <div class="card mb-3">
        <div class="card-body">
            <h1 class="title mb-3">Database migrations</h1>

            <h5>Pending migrations</h5>
            <?php if (empty($pending)): ?>
                <div class="alert alert-success">All migrations are up to date.</div>
            <?php else: ?>
                <ul>
                    <?php foreach ($pending as $file): ?>
                        <li><?= htmlspecialchars($file) ?></li>
                    <?php endforeach; ?>
                </ul>

                <form method="post">
                    <button class="btn btn-primary" name="run_migrations">
                        Run <?= count($pending) ?> migration(s)
                    </button>
                </form>
            <?php endif; ?>

            <?php if (isset($log)): ?>
                <hr>
                <h5>Migration log</h5>
                <div class="mt-2">
                    <?= implode("", $log) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php include 'includes/footer.php'; ?>