<?php
// src/classes/McaPresetRepository.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class McaPresetRepository
{
    public static function listForStudyArea(int $studyAreaId, int $userId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            SELECT
                ps.*,
                (ps.user_id IS NULL) AS is_global
            FROM mca_preset_sets ps
            WHERE ps.study_area_id = :sa
              AND (ps.user_id IS NULL OR ps.user_id = :uid)
            ORDER BY ps.is_default DESC, ps.user_id NULLS FIRST, ps.name
        ");
        $stmt->execute([':sa' => $studyAreaId, ':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function assertCanEdit(int $presetSetId, int $userId): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("SELECT user_id FROM mca_preset_sets WHERE id = :id");
        $stmt->execute([':id' => $presetSetId]);
        $owner = $stmt->fetchColumn();

        if ($owner === false) {
            throw new InvalidArgumentException('Preset set not found');
        }

        // Only allow edit if it's user-owned (or you later allow admins)
        if ($owner === null) {
            throw new InvalidArgumentException('Default preset set cannot be edited');
        }
        if ((int)$owner !== $userId) {
            throw new InvalidArgumentException('Not allowed');
        }
    }

    public static function createUserSet(int $studyAreaId, int $userId, string $name): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            INSERT INTO mca_preset_sets (study_area_id, user_id, name, is_default)
            VALUES (:sa, :uid, :name, FALSE)
            RETURNING id
        ");
        $stmt->execute([':sa' => $studyAreaId, ':uid' => $userId, ':name' => $name]);
        return (int)$stmt->fetchColumn();
    }

    public static function cloneDefaultToUser(int $studyAreaId, int $userId, string $name): int
    {
        $pdo = Database::pdo();

        // find default preset set for this area
        $stmt = $pdo->prepare("
            SELECT id
            FROM mca_preset_sets
            WHERE study_area_id = :sa AND user_id IS NULL AND is_default = TRUE
            LIMIT 1
        ");
        $stmt->execute([':sa' => $studyAreaId]);
        $defaultId = $stmt->fetchColumn();
        if (!$defaultId) {
            throw new InvalidArgumentException('No default preset set found for this study area');
        }

        $pdo->beginTransaction();
        try {
            // create new set
            $ins = $pdo->prepare("
                INSERT INTO mca_preset_sets (study_area_id, user_id, name, is_default)
                VALUES (:sa, :uid, :name, FALSE)
                RETURNING id
            ");
            $ins->execute([':sa' => $studyAreaId, ':uid' => $userId, ':name' => $name]);
            $newId = (int)$ins->fetchColumn();

            // clone items
            $pdo->prepare("
                INSERT INTO mca_preset_items (preset_set_id, indicator_id, weight, direction, is_enabled)
                SELECT :new_id, indicator_id, weight, direction, is_enabled
                FROM mca_preset_items
                WHERE preset_set_id = :old_id
            ")->execute([':new_id' => $newId, ':old_id' => (int)$defaultId]);

            // clone scenarios
            $pdo->prepare("
                INSERT INTO mca_scenarios (preset_set_id, scenario_key, label, run_id, sort_order)
                SELECT :new_id, scenario_key, label, run_id, sort_order
                FROM mca_scenarios
                WHERE preset_set_id = :old_id
            ")->execute([':new_id' => $newId, ':old_id' => (int)$defaultId]);

            $pdo->commit();
            return $newId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * items = [
     *   { "indicator_code":"15.1", "weight":0.1, "direction":"neg"|"pos"|null, "is_enabled":true }
     * ]
     */
    public static function saveItems(int $presetSetId, array $items): void
    {
        $pdo = Database::pdo();

        $sum = 0;
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            if (empty($it['is_enabled'])) continue;

            $w = $it['weight'] ?? 0;

            if (!is_numeric($w) || (float)$w < 0) {
                throw new InvalidArgumentException('Weights must be numbers >= 0');
            }

            $sum += (float)$w;
        }

        if ($sum <= 0) {
            throw new InvalidArgumentException('At least one enabled indicator must have weight > 0');
        }

        $pdo->beginTransaction();
        try {
            $stmtFind = $pdo->prepare("SELECT id FROM mca_indicators WHERE code = :code");
            $stmtUp = $pdo->prepare("
                INSERT INTO mca_preset_items (preset_set_id, indicator_id, weight, direction, is_enabled)
                VALUES (:ps, :ind, :w, :dir, :en)
                ON CONFLICT (preset_set_id, indicator_id) DO UPDATE SET
                    weight = EXCLUDED.weight,
                    direction = EXCLUDED.direction,
                    is_enabled = EXCLUDED.is_enabled
            ");

            foreach ($items as $it) {
                $code = trim((string)($it['indicator_code'] ?? ''));
                if ($code === '') continue;

                $stmtFind->execute([':code' => $code]);
                $indId = $stmtFind->fetchColumn();
                if (!$indId) {
                    throw new InvalidArgumentException("Unknown indicator code: {$code}");
                }

                $dir = $it['direction'] ?? null;
                if ($dir !== null) {
                    $dir = trim((string)$dir);
                    if ($dir !== 'pos' && $dir !== 'neg') {
                        throw new InvalidArgumentException("Invalid direction for {$code}");
                    }
                }

                $stmtUp->execute([
                    ':ps'  => $presetSetId,
                    ':ind' => (int)$indId,
                    ':w'   => (float)($it['weight'] ?? 0),
                    ':dir' => $dir,
                    ':en'  => !empty($it['is_enabled']),
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * scenarios = [
     *   { "scenario_key":"baseline", "label":"Baseline", "run_id":123, "sort_order":10 }
     * ]
     */
    public static function saveScenarios(int $presetSetId, array $scenarios): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            INSERT INTO mca_scenarios (preset_set_id, scenario_key, label, run_id, sort_order)
            VALUES (:ps, :key, :label, :run_id, :sort)
            ON CONFLICT (preset_set_id, scenario_key) DO UPDATE SET
                label = EXCLUDED.label,
                run_id = EXCLUDED.run_id,
                sort_order = EXCLUDED.sort_order
        ");

        foreach ($scenarios as $sc) {
            if (!is_array($sc)) continue;
            $key = trim((string)($sc['scenario_key'] ?? ''));
            if ($key === '') continue;

            $stmt->execute([
                ':ps'    => $presetSetId,
                ':key'   => $key,
                ':label' => trim((string)($sc['label'] ?? $key)),
                ':run_id'=> ($sc['run_id'] ?? null) !== null ? (int)$sc['run_id'] : null,
                ':sort'  => (int)($sc['sort_order'] ?? 0),
            ]);
        }
    }

    public static function deleteSet(int $presetSetId, int $userId): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("DELETE FROM mca_preset_sets WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $presetSetId, ':uid' => $userId]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('Preset not found or not allowed');
        }
    }
}