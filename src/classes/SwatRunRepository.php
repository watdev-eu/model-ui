<?php
// src/classes/SwatRunRepository.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class SwatRunRepository
{
    /**
     * List all runs with their study area name.
     */
    public static function all(): array
    {
        $pdo = Database::pdo();
        $sql = "
            SELECT
                r.*,
                sa.name AS study_area_name,
                sa.id   AS study_area_id
            FROM swat_runs r
            JOIN study_areas sa ON sa.id = r.study_area
            ORDER BY sa.name, r.run_label
        ";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find a single run by id.
     */
    public static function find(int $id): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            SELECT
                r.*,
                sa.name AS study_area_name,
                sa.id   AS study_area_id
            FROM swat_runs r
            JOIN study_areas sa ON sa.id = r.study_area
            WHERE r.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * All runs for a given study_area id.
     */
    public static function forStudyAreaId(int $studyAreaId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            SELECT
                r.*,
                sa.name AS study_area_name,
                sa.id   AS study_area_id
            FROM swat_runs r
            JOIN study_areas sa ON sa.id = r.study_area
            WHERE r.study_area = :id
            ORDER BY r.is_default DESC, r.run_date DESC NULLS LAST, r.created_at DESC
        ");
        $stmt->execute([':id' => $studyAreaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Backwards-compatible helper: if a non-numeric value is passed,
     * try to look up the study area by name (case-insensitive).
     * New code should pass an integer id and hit forStudyAreaId directly.
     */
    public static function forStudyArea(string $area): array
    {
        $area = trim($area);
        if ($area === '') {
            return [];
        }

        // If it's numeric already, treat it as an id.
        if (ctype_digit($area)) {
            return self::forStudyAreaId((int)$area);
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare("SELECT id FROM study_areas WHERE LOWER(name) = LOWER(:name)");
        $stmt->execute([':name' => $area]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            return [];
        }
        return self::forStudyAreaId((int)$id);
    }

    /**
     * Toggle default run for its study area; making a run default also makes it public.
     */
    public static function toggleDefault(int $id): bool
    {
        $pdo = Database::pdo();

        // Get current run
        $stmt = $pdo->prepare("SELECT id, study_area, is_default, visibility FROM swat_runs WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            throw new InvalidArgumentException('Run not found');
        }

        $studyAreaId = (int)$run['study_area'];
        $currentlyDefault = !empty($run['is_default']);

        $pdo->beginTransaction();
        try {
            if ($currentlyDefault) {
                // Unset default
                $upd = $pdo->prepare("UPDATE swat_runs SET is_default = FALSE WHERE id = :id");
                $upd->execute([':id' => $id]);
                $pdo->commit();
                return false;
            }

            // Clear defaults for this area
            $clear = $pdo->prepare("UPDATE swat_runs SET is_default = FALSE WHERE study_area = :area");
            $clear->execute([':area' => $studyAreaId]);

            // Set this one as default and make it public
            $set = $pdo->prepare("
                UPDATE swat_runs
                SET is_default = TRUE,
                    visibility = 'public'
                WHERE id = :id
            ");
            $set->execute([':id' => $id]);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Toggle visibility (private/public).
     */
    public static function toggleVisibility(int $id): string
    {
        $pdo = Database::pdo();

        // Default runs must remain public – enforce server-side too
        $stmt = $pdo->prepare("SELECT visibility, is_default FROM swat_runs WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            throw new InvalidArgumentException('Run not found');
        }
        if (!empty($run['is_default'])) {
            throw new InvalidArgumentException('Default scenarios must remain public');
        }

        $current = $run['visibility'] ?? 'private';
        $new     = ($current === 'public') ? 'private' : 'public';

        $upd = $pdo->prepare("UPDATE swat_runs SET visibility = :vis WHERE id = :id");
        $upd->execute([':vis' => $new, ':id' => $id]);

        return $new;
    }

    public static function delete(int $id): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("DELETE FROM swat_runs WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }
}