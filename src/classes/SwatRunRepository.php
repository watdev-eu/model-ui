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
                sa.id   AS study_area_id,
                rl.name AS license_name
            FROM swat_runs r
            JOIN study_areas sa ON sa.id = r.study_area
            LEFT JOIN run_licenses rl ON rl.id = r.license_id
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
                sa.id   AS study_area_id,
                rl.name AS license_name
            FROM swat_runs r
            JOIN study_areas sa ON sa.id = r.study_area
            LEFT JOIN run_licenses rl ON rl.id = r.license_id
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

        $currentlyDefault = !empty($run['is_default']);

        $pdo->beginTransaction();
        try {
            if ($currentlyDefault) {
                // Unset default, keep visibility as-is
                $upd = $pdo->prepare("UPDATE swat_runs SET is_default = FALSE WHERE id = :id");
                $upd->execute([':id' => $id]);
                $pdo->commit();
                return false;
            }

            // Just set this run as default and make it public,
            // WITHOUT clearing other defaults in the same study area
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

    public static function visibleForStudyAreaId(int $studyAreaId, ?string $userId): array
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare("
            SELECT
                r.*,
                sa.name AS study_area_name,
                sa.id   AS study_area_id
            FROM swat_runs r
            JOIN study_areas sa ON sa.id = r.study_area
            WHERE r.study_area = :study_area_id
              AND (
                    r.is_default = TRUE
                    OR r.visibility = 'public'
                    OR (:has_user = 1 AND r.created_by = :created_by)
              )
            ORDER BY r.is_default DESC, r.run_date DESC NULLS LAST, r.created_at DESC
        ");

        $stmt->execute([
            ':study_area_id' => $studyAreaId,
            ':has_user'      => $userId !== null ? 1 : 0,
            ':created_by'    => $userId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function userCanAccess(int $runId, ?string $userId): bool
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare("
        SELECT 1
        FROM swat_runs
        WHERE id = :id
          AND (
                is_default = TRUE
                OR visibility = 'public'
                OR (:has_user = 1 AND created_by = :created_by)
          )
        LIMIT 1
    ");

        $stmt->execute([
            ':id'         => $runId,
            ':has_user'   => $userId !== null ? 1 : 0,
            ':created_by' => $userId,
        ]);

        return (bool)$stmt->fetchColumn();
    }

    public static function updateMetadata(int $id, array $data): array
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare("SELECT * FROM swat_runs WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$run) {
            throw new InvalidArgumentException('Run not found');
        }

        $runLabel = trim((string)($data['run_label'] ?? ''));
        if ($runLabel === '') {
            throw new InvalidArgumentException('Run name is required');
        }

        $visibility = (string)($data['visibility'] ?? 'private');
        if (!in_array($visibility, ['private', 'public'], true)) {
            throw new InvalidArgumentException('Invalid visibility');
        }

        if (!empty($run['is_default'])) {
            $visibility = 'public';
        }

        $isBaseline = !empty($data['is_baseline']);

        $runDate = trim((string)($data['run_date'] ?? ''));
        $runDate = $runDate !== '' ? $runDate : null;

        $downloadableFromDate = trim((string)($data['downloadable_from_date'] ?? ''));
        $downloadableFromDate = $downloadableFromDate !== '' ? $downloadableFromDate : null;

        $licenseId = (int)($data['license_id'] ?? 0);
        $licenseId = $licenseId > 0 ? $licenseId : null;

        $stmt = $pdo->prepare("
            UPDATE swat_runs
            SET
                run_label = :run_label,
                run_date = :run_date,
                model_run_author = :model_run_author,
                publication_url = :publication_url,
                license_id = :license_id,
                visibility = :visibility,
                is_baseline = :is_baseline,
                is_downloadable = :is_downloadable,
                downloadable_from_date = :downloadable_from_date,
                description = :description
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $id,
            ':run_label' => $runLabel,
            ':run_date' => $runDate,
            ':model_run_author' => trim((string)($data['model_run_author'] ?? '')),
            ':publication_url' => trim((string)($data['publication_url'] ?? '')) ?: null,
            ':license_id' => $licenseId,
            ':visibility' => $visibility,
            ':is_baseline' => $isBaseline ? 1 : 0,
            ':is_downloadable' => !empty($data['is_downloadable']) ? 1 : 0,
            ':downloadable_from_date' => $downloadableFromDate,
            ':description' => trim((string)($data['description'] ?? '')),
        ]);

        if (isset($data['selected_subbasins'])) {
            self::updateRunSubbasins($id, $data['selected_subbasins']);
        }

        return self::find($id) ?? [];
    }

    public static function selectedSubbasins(int $runId): array
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare("
        SELECT sub
        FROM swat_run_subbasins
        WHERE run_id = :run_id
        ORDER BY sub
    ");
        $stmt->execute([':run_id' => $runId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function updateRunSubbasins(int $runId, array $selectedSubbasins): void
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare("
        SELECT id, study_area
        FROM swat_runs
        WHERE id = :id
    ");
        $stmt->execute([':id' => $runId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$run) {
            throw new InvalidArgumentException('Run not found');
        }

        $studyAreaId = (int)$run['study_area'];

        $selectedSubbasins = array_values(array_unique(array_map('intval', $selectedSubbasins)));
        $selectedSubbasins = array_values(array_filter($selectedSubbasins, fn(int $v) => $v > 0));

        if (!$selectedSubbasins) {
            throw new InvalidArgumentException('Please select at least one subbasin.');
        }

        $stmt = $pdo->prepare("
        SELECT sub
        FROM study_area_subbasins
        WHERE study_area_id = :study_area_id
    ");
        $stmt->execute([':study_area_id' => $studyAreaId]);

        $validSubs = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $validSet = array_fill_keys($validSubs, true);

        foreach ($selectedSubbasins as $sub) {
            if (!isset($validSet[$sub])) {
                throw new InvalidArgumentException("Subbasin {$sub} does not exist in this study area.");
            }
        }

        $pdo->beginTransaction();

        try {
            $del = $pdo->prepare("DELETE FROM swat_run_subbasins WHERE run_id = :run_id");
            $del->execute([':run_id' => $runId]);

            $ins = $pdo->prepare("
            INSERT INTO swat_run_subbasins (run_id, study_area_id, sub)
            VALUES (:run_id, :study_area_id, :sub)
        ");

            foreach ($selectedSubbasins as $sub) {
                $ins->execute([
                    ':run_id' => $runId,
                    ':study_area_id' => $studyAreaId,
                    ':sub' => $sub,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}