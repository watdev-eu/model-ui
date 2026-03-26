<?php
// classes/McaWorkspaceRepository.php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class McaWorkspaceRepository
{
    private static function assertPresetAccessible(PDO $pdo, int $studyAreaId, int $presetSetId, string $userId): void
    {
        $stmt = $pdo->prepare("
        SELECT 1
        FROM mca_preset_sets
        WHERE id = :id
          AND study_area_id = :sa
          AND (user_id IS NULL OR user_id = :uid)
        LIMIT 1
    ");
        $stmt->execute([
            ':id' => $presetSetId,
            ':sa' => $studyAreaId,
            ':uid' => $userId,
        ]);

        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('Preset set not found or not accessible');
        }
    }

    private static function assertVariableSetAccessible(PDO $pdo, int $studyAreaId, int $variableSetId, string $userId): void
    {
        $stmt = $pdo->prepare("
        SELECT 1
        FROM mca_variable_sets
        WHERE id = :id
          AND study_area_id = :sa
          AND (user_id IS NULL OR user_id = :uid)
        LIMIT 1
    ");
        $stmt->execute([
            ':id' => $variableSetId,
            ':sa' => $studyAreaId,
            ':uid' => $userId,
        ]);

        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('Variable set not found or not accessible');
        }
    }

    public static function listForStudyArea(int $studyAreaId, string $userId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            SELECT
                w.*,
                ps.name AS preset_set_name,
                vs.name AS variable_set_name
            FROM mca_workspaces w
            JOIN mca_preset_sets ps ON ps.id = w.preset_set_id
            JOIN mca_variable_sets vs ON vs.id = w.variable_set_id
            WHERE w.study_area_id = :sa
              AND w.user_id = :uid
            ORDER BY w.is_default DESC, w.updated_at DESC, w.name ASC
        ");
        $stmt->execute([
            ':sa' => $studyAreaId,
            ':uid' => $userId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function findByIdForUser(int $workspaceId, string $userId): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            SELECT *
            FROM mca_workspaces
            WHERE id = :id
              AND user_id = :uid
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $workspaceId,
            ':uid' => $userId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function findDefaultForUser(int $studyAreaId, string $userId): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            SELECT *
            FROM mca_workspaces
            WHERE study_area_id = :sa
              AND user_id = :uid
              AND is_default = TRUE
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':sa' => $studyAreaId,
            ':uid' => $userId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function getSelectedDatasetIds(int $workspaceId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            SELECT dataset_id
            FROM mca_workspace_selected_datasets
            WHERE workspace_id = :id
            ORDER BY sort_order ASC, dataset_id ASC
        ");
        $stmt->execute([':id' => $workspaceId]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    public static function create(
        int $studyAreaId,
        string $userId,
        string $name,
        ?string $description,
        int $presetSetId,
        int $variableSetId,
        array $datasetIds,
        bool $isDefault = false
    ): int {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            self::assertPresetAccessible($pdo, $studyAreaId, $presetSetId, $userId);
            self::assertVariableSetAccessible($pdo, $studyAreaId, $variableSetId, $userId);

            if ($isDefault) {
                $pdo->prepare("
                    UPDATE mca_workspaces
                    SET is_default = FALSE
                    WHERE study_area_id = :sa
                      AND user_id = :uid
                      AND is_default = TRUE
                ")->execute([
                    ':sa' => $studyAreaId,
                    ':uid' => $userId,
                ]);
            }

            $stmt = $pdo->prepare("
                INSERT INTO mca_workspaces
                    (study_area_id, user_id, name, description, is_default, preset_set_id, variable_set_id)
                VALUES
                    (:sa, :uid, :name, :description, :is_default, :preset_set_id, :variable_set_id)
                RETURNING id
            ");

            $stmt->bindValue(':sa', $studyAreaId, PDO::PARAM_INT);
            $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);

            if ($description === null) {
                $stmt->bindValue(':description', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':description', $description, PDO::PARAM_STR);
            }

            $stmt->bindValue(':is_default', $isDefault, PDO::PARAM_BOOL);
            $stmt->bindValue(':preset_set_id', $presetSetId, PDO::PARAM_INT);
            $stmt->bindValue(':variable_set_id', $variableSetId, PDO::PARAM_INT);

            $stmt->execute();

            $workspaceId = (int)$stmt->fetchColumn();

            self::replaceSelectedDatasetsTx($pdo, $workspaceId, $datasetIds);

            $pdo->commit();
            return $workspaceId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function update(
        int $workspaceId,
        string $userId,
        string $name,
        ?string $description,
        int $presetSetId,
        int $variableSetId,
        array $datasetIds,
        bool $isDefault = false
    ): void {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $current = self::findByIdForUser($workspaceId, $userId);
            if (!$current) {
                throw new InvalidArgumentException('Workspace not found');
            }

            $studyAreaId = (int)$current['study_area_id'];

            self::assertPresetAccessible($pdo, $studyAreaId, $presetSetId, $userId);
            self::assertVariableSetAccessible($pdo, $studyAreaId, $variableSetId, $userId);

            if ($isDefault) {
                $pdo->prepare("
                    UPDATE mca_workspaces
                    SET is_default = FALSE
                    WHERE study_area_id = :sa
                      AND user_id = :uid
                      AND id <> :id
                      AND is_default = TRUE
                ")->execute([
                    ':sa' => $studyAreaId,
                    ':uid' => $userId,
                    ':id' => $workspaceId,
                ]);
            }

            $stmt = $pdo->prepare("
                UPDATE mca_workspaces
                SET
                    name = :name,
                    description = :description,
                    is_default = :is_default,
                    preset_set_id = :preset_set_id,
                    variable_set_id = :variable_set_id,
                    updated_at = NOW()
                WHERE id = :id
                  AND user_id = :uid
            ");

            $stmt->bindValue(':id', $workspaceId, PDO::PARAM_INT);
            $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);

            if ($description === null) {
                $stmt->bindValue(':description', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':description', $description, PDO::PARAM_STR);
            }

            $stmt->bindValue(':is_default', $isDefault, PDO::PARAM_BOOL);
            $stmt->bindValue(':preset_set_id', $presetSetId, PDO::PARAM_INT);
            $stmt->bindValue(':variable_set_id', $variableSetId, PDO::PARAM_INT);

            $stmt->execute();

            self::replaceSelectedDatasetsTx($pdo, $workspaceId, $datasetIds);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function delete(int $workspaceId, string $userId): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            DELETE FROM mca_workspaces
            WHERE id = :id
              AND user_id = :uid
        ");
        $stmt->execute([
            ':id' => $workspaceId,
            ':uid' => $userId,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('Workspace not found or not allowed');
        }
    }

    private static function replaceSelectedDatasetsTx(PDO $pdo, int $workspaceId, array $datasetIds): void
    {
        $pdo->prepare("
            DELETE FROM mca_workspace_selected_datasets
            WHERE workspace_id = :id
        ")->execute([':id' => $workspaceId]);

        $ins = $pdo->prepare("
            INSERT INTO mca_workspace_selected_datasets
                (workspace_id, dataset_id, sort_order)
            VALUES
                (:workspace_id, :dataset_id, :sort_order)
        ");

        $seen = [];
        $sort = 0;
        foreach ($datasetIds as $datasetId) {
            $datasetId = trim((string)$datasetId);
            if ($datasetId === '' || isset($seen[$datasetId])) {
                continue;
            }
            $seen[$datasetId] = true;

            $ins->execute([
                ':workspace_id' => $workspaceId,
                ':dataset_id' => $datasetId,
                ':sort_order' => $sort++,
            ]);
        }
    }
}