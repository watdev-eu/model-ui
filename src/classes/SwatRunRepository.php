<?php
// src/classes/SwatRunRepository.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class SwatRunRepository
{
    public static function all(): array
    {
        $pdo = Database::pdo();
        $sql = "
        SELECT
            id,
            study_area,
            run_label,
            run_date,
            visibility,
            description,
            period_start,
            period_end,
            time_step,
            created_at,
            is_default
        FROM swat_runs
        ORDER BY created_at DESC
    ";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function forStudyArea(string $studyArea): array
    {
        $pdo = Database::pdo();
        $sql = "
        SELECT
            id,
            study_area,
            run_label,
            run_date,
            visibility,
            description,
            period_start,
            period_end,
            time_step,
            created_at,
            is_default
        FROM swat_runs
        WHERE study_area = :study_area
        ORDER BY created_at DESC
    ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':study_area' => $studyArea]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(int $id): ?array
    {
        $pdo = Database::pdo();
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
                time_step,
                created_at,
                is_default
            FROM swat_runs
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Toggle default flag for a run.
     * If it was not default, make it default and clear defaults for the same study_area.
     * If it was default, unset it.
     *
     * @return bool new is_default state
     */
    public static function toggleDefault(int $id): bool
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare("SELECT study_area, is_default FROM swat_runs WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new InvalidArgumentException('Run not found');
        }

        $area           = $row['study_area'];
        $wasDefault     = !empty($row['is_default']);
        $becomesDefault = !$wasDefault;

        $pdo->beginTransaction();
        try {
            if ($becomesDefault) {
                // clear other defaults for this area
                $stmt = $pdo->prepare("
                UPDATE swat_runs
                SET is_default = FALSE
                WHERE study_area = :area
            ");
                $stmt->execute([':area' => $area]);

                // make selected run default *and* public
                $stmt = $pdo->prepare("
                UPDATE swat_runs
                SET is_default = TRUE,
                    visibility = 'public'
                WHERE id = :id
            ");
                $stmt->execute([':id' => $id]);
            } else {
                // just unset this one
                $stmt = $pdo->prepare("
                UPDATE swat_runs
                SET is_default = FALSE
                WHERE id = :id
            ");
                $stmt->execute([':id' => $id]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $becomesDefault;
    }

    /**
     * Toggle visibility (public/private) and return the new value.
     */
    public static function toggleVisibility(int $id): string
    {
        $pdo = Database::pdo();

        // Fetch current state first
        $stmt = $pdo->prepare("
            SELECT visibility, is_default
            FROM swat_runs
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new InvalidArgumentException('Run not found');
        }

        $currentVis = $row['visibility'] ?? 'private';
        $isDefault  = !empty($row['is_default']);

        // Business rule: default runs must remain public
        if ($isDefault && $currentVis === 'public') {
            throw new InvalidArgumentException('Default runs must remain public');
        }

        $newVis = ($currentVis === 'public') ? 'private' : 'public';

        $stmt = $pdo->prepare("
            UPDATE swat_runs
            SET visibility = :vis
            WHERE id = :id
        ");
        $stmt->execute([
            ':vis' => $newVis,
            ':id'  => $id,
        ]);

        return $newVis;
    }
}