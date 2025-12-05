<?php
// src/classes/SwatRunRepository.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class SwatRunRepository
{
    public static function all(): array
    {
        $pdo = Database::pdo();
        $sql = "SELECT id, study_area, bmp, run_label,
                       period_start, period_end, time_step, created_at
                FROM swat_runs
                ORDER BY created_at DESC";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create(array $data): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
          INSERT INTO swat_runs (
              study_area, bmp, run_label,
              swat_version, period_start, period_end, time_step, run_path
          )
          VALUES (?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $data['study_area'],
            $data['bmp'],
            $data['run_label'],
            null,          // swat_version (unused)
            null,          // period_start (filled from KPI after import)
            null,          // period_end   (filled from KPI after import)
            'MONTHLY',     // time_step
            null,          // run_path (optional)
        ]);
        return (int)$pdo->lastInsertId();
    }
}