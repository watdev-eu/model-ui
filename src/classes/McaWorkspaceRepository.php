<?php
// classes/McaWorkspaceRepository.php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class McaWorkspaceRepository
{
    private static function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_float($value)) {
            return $value != 0.0;
        }

        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 't', 'yes', 'y', 'on'], true)) return true;
            if (in_array($v, ['0', 'false', 'f', 'no', 'n', 'off'], true)) return false;
        }

        return null;
    }

    private static function pgBool(mixed $value): ?string
    {
        $b = self::nullableBool($value);
        if ($b === null) return null;
        return $b ? 'true' : 'false';
    }

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

    private static function replaceWorkspaceStateTx(
        PDO $pdo,
        int $workspaceId,
        array $datasetIds,
        array $presetItems,
        array $variables,
        array $cropVariables,
        array $cropRefFactors,
        array $runInputs
    ): void {
        self::replaceSelectedDatasetsTx($pdo, $workspaceId, $datasetIds);
        self::replacePresetItemsTx($pdo, $workspaceId, $presetItems);
        self::replaceVariablesTx($pdo, $workspaceId, $variables);
        self::replaceCropVariablesTx($pdo, $workspaceId, $cropVariables);
        self::replaceCropRefFactorsTx($pdo, $workspaceId, $cropRefFactors);
        self::replaceRunInputsTx($pdo, $workspaceId, $runInputs);

        $snapshot = [
            'dataset_ids' => array_values($datasetIds),
            'preset_items' => array_values($presetItems),
            'variables' => array_values($variables),
            'crop_variables' => array_values($cropVariables),
            'crop_ref_factors' => array_values($cropRefFactors),
            'run_inputs' => array_values($runInputs),
        ];

        $stmt = $pdo->prepare("
            UPDATE mca_workspaces
            SET workspace_state_json = CAST(:state_json AS jsonb)
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $workspaceId,
            ':state_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
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

    public static function getPresetItems(int $workspaceId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            SELECT
                indicator_calc_key,
                indicator_code,
                indicator_name,
                weight,
                direction,
                is_enabled,
                sort_order
            FROM mca_workspace_preset_items
            WHERE workspace_id = :id
            ORDER BY sort_order ASC, indicator_calc_key ASC
        ");
        $stmt->execute([':id' => $workspaceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getVariables(int $workspaceId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            SELECT
                key,
                name,
                unit,
                description,
                data_type,
                value_num,
                value_text,
                value_bool,
                sort_order
            FROM mca_workspace_variables
            WHERE workspace_id = :id
            ORDER BY sort_order ASC, key ASC
        ");
        $stmt->execute([':id' => $workspaceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getCropVariables(int $workspaceId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            SELECT
                crop_code,
                crop_name,
                key,
                name,
                unit,
                description,
                data_type,
                value_num,
                value_text,
                value_bool,
                sort_order
            FROM mca_workspace_crop_variables
            WHERE workspace_id = :id
            ORDER BY crop_code ASC, sort_order ASC, key ASC
        ");
        $stmt->execute([':id' => $workspaceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getCropRefFactors(int $workspaceId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            SELECT
                crop_code,
                crop_name,
                key,
                name,
                unit,
                description,
                data_type,
                value_num,
                value_text,
                value_bool,
                sort_order
            FROM mca_workspace_crop_ref_factors
            WHERE workspace_id = :id
            ORDER BY crop_code ASC, sort_order ASC, key ASC
        ");
        $stmt->execute([':id' => $workspaceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getRunVariables(int $workspaceId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            SELECT
                dataset_id,
                key,
                name,
                unit,
                description,
                data_type,
                value_num,
                value_text,
                value_bool,
                sort_order
            FROM mca_workspace_run_variables
            WHERE workspace_id = :id
            ORDER BY dataset_id ASC, sort_order ASC, key ASC
        ");
        $stmt->execute([':id' => $workspaceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getRunCropFactors(int $workspaceId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            SELECT
                dataset_id,
                crop_code,
                crop_name,
                key,
                name,
                unit,
                description,
                data_type,
                value_num,
                value_text,
                value_bool,
                sort_order
            FROM mca_workspace_run_crop_factors
            WHERE workspace_id = :id
            ORDER BY dataset_id ASC, crop_code ASC, sort_order ASC, key ASC
        ");
        $stmt->execute([':id' => $workspaceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function create(
        int $studyAreaId,
        string $userId,
        string $name,
        ?string $description,
        int $presetSetId,
        int $variableSetId,
        array $datasetIds,
        bool $isDefault = false,
        array $presetItems = [],
        array $variables = [],
        array $cropVariables = [],
        array $cropRefFactors = [],
        array $runInputs = []
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
            $stmt->bindValue(':uid', $userId, PDO::PARAM_STR);
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

            self::replaceWorkspaceStateTx(
                $pdo,
                $workspaceId,
                $datasetIds,
                $presetItems,
                $variables,
                $cropVariables,
                $cropRefFactors,
                $runInputs
            );

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
        bool $isDefault = false,
        array $presetItems = [],
        array $variables = [],
        array $cropVariables = [],
        array $cropRefFactors = [],
        array $runInputs = []
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
            $stmt->bindValue(':uid', $userId, PDO::PARAM_STR);
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

            self::replaceWorkspaceStateTx(
                $pdo,
                $workspaceId,
                $datasetIds,
                $presetItems,
                $variables,
                $cropVariables,
                $cropRefFactors,
                $runInputs
            );

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

    private static function replacePresetItemsTx(PDO $pdo, int $workspaceId, array $rows): void
    {
        $pdo->prepare("DELETE FROM mca_workspace_preset_items WHERE workspace_id = :id")
            ->execute([':id' => $workspaceId]);

        $ins = $pdo->prepare("
            INSERT INTO mca_workspace_preset_items
                (workspace_id, indicator_calc_key, indicator_code, indicator_name, weight, direction, is_enabled, sort_order)
            VALUES
                (:workspace_id, :indicator_calc_key, :indicator_code, :indicator_name, :weight, :direction, :is_enabled, :sort_order)
        ");

        foreach ($rows as $i => $r) {
            $calcKey = trim((string)($r['indicator_calc_key'] ?? ''));
            if ($calcKey === '') continue;

            $direction = ((string)($r['direction'] ?? 'pos') === 'neg') ? 'neg' : 'pos';
            $isEnabled = self::nullableBool($r['is_enabled'] ?? null) ?? false;

            $ins->bindValue(':workspace_id', $workspaceId, PDO::PARAM_INT);
            $ins->bindValue(':indicator_calc_key', $calcKey, PDO::PARAM_STR);

            if (($r['indicator_code'] ?? null) === null) {
                $ins->bindValue(':indicator_code', null, PDO::PARAM_NULL);
            } else {
                $ins->bindValue(':indicator_code', (string)$r['indicator_code'], PDO::PARAM_STR);
            }

            if (($r['indicator_name'] ?? null) === null) {
                $ins->bindValue(':indicator_name', null, PDO::PARAM_NULL);
            } else {
                $ins->bindValue(':indicator_name', (string)$r['indicator_name'], PDO::PARAM_STR);
            }

            $ins->bindValue(':weight', (string)((float)($r['weight'] ?? 0)), PDO::PARAM_STR);
            $ins->bindValue(':direction', $direction, PDO::PARAM_STR);
            $ins->bindValue(':is_enabled', $isEnabled, PDO::PARAM_BOOL);
            $ins->bindValue(':sort_order', (int)($r['sort_order'] ?? $i), PDO::PARAM_INT);

            $ins->execute();
        }
    }

    private static function replaceVariablesTx(PDO $pdo, int $workspaceId, array $rows): void
    {
        $pdo->prepare("DELETE FROM mca_workspace_variables WHERE workspace_id = :id")
            ->execute([':id' => $workspaceId]);

        $ins = $pdo->prepare("
        INSERT INTO mca_workspace_variables
            (workspace_id, key, name, unit, description, data_type, value_num, value_text, value_bool, sort_order)
        VALUES
            (:workspace_id, :key, :name, :unit, :description, :data_type, :value_num, :value_text, :value_bool, :sort_order)
    ");

        foreach ($rows as $i => $r) {
            $key = trim((string)($r['key'] ?? ''));
            if ($key === '') continue;

            $dataType = (string)($r['data_type'] ?? 'number');
            if (!in_array($dataType, ['number', 'text', 'bool'], true)) {
                $dataType = 'number';
            }

            $ins->execute([
                ':workspace_id' => $workspaceId,
                ':key' => $key,
                ':name' => (($r['name'] ?? null) !== null ? (string)$r['name'] : null),
                ':unit' => (($r['unit'] ?? null) !== null ? (string)$r['unit'] : null),
                ':description' => (($r['description'] ?? null) !== null ? (string)$r['description'] : null),
                ':data_type' => $dataType,
                ':value_num' => $r['value_num'] ?? null,
                ':value_text' => $r['value_text'] ?? null,
                ':value_bool' => self::pgBool($r['value_bool'] ?? null),
                ':sort_order' => (int)($r['sort_order'] ?? $i),
            ]);
        }
    }

    private static function replaceCropVariablesTx(PDO $pdo, int $workspaceId, array $rows): void
    {
        $pdo->prepare("DELETE FROM mca_workspace_crop_variables WHERE workspace_id = :id")
            ->execute([':id' => $workspaceId]);

        $ins = $pdo->prepare("
        INSERT INTO mca_workspace_crop_variables
            (workspace_id, crop_code, crop_name, key, name, unit, description, data_type, value_num, value_text, value_bool, sort_order)
        VALUES
            (:workspace_id, :crop_code, :crop_name, :key, :name, :unit, :description, :data_type, :value_num, :value_text, :value_bool, :sort_order)
    ");

        foreach ($rows as $i => $r) {
            $cropCode = trim((string)($r['crop_code'] ?? ''));
            $key = trim((string)($r['key'] ?? ''));
            if ($cropCode === '' || $key === '') continue;

            $dataType = (string)($r['data_type'] ?? 'number');
            if (!in_array($dataType, ['number', 'text', 'bool'], true)) {
                $dataType = 'number';
            }

            $ins->execute([
                ':workspace_id' => $workspaceId,
                ':crop_code' => $cropCode,
                ':crop_name' => (($r['crop_name'] ?? null) !== null ? (string)$r['crop_name'] : null),
                ':key' => $key,
                ':name' => (($r['name'] ?? null) !== null ? (string)$r['name'] : null),
                ':unit' => (($r['unit'] ?? null) !== null ? (string)$r['unit'] : null),
                ':description' => (($r['description'] ?? null) !== null ? (string)$r['description'] : null),
                ':data_type' => $dataType,
                ':value_num' => $r['value_num'] ?? null,
                ':value_text' => $r['value_text'] ?? null,
                ':value_bool' => self::pgBool($r['value_bool'] ?? null),
                ':sort_order' => (int)($r['sort_order'] ?? $i),
            ]);
        }
    }

    private static function replaceCropRefFactorsTx(PDO $pdo, int $workspaceId, array $rows): void
    {
        $pdo->prepare("DELETE FROM mca_workspace_crop_ref_factors WHERE workspace_id = :id")
            ->execute([':id' => $workspaceId]);

        $ins = $pdo->prepare("
        INSERT INTO mca_workspace_crop_ref_factors
            (workspace_id, crop_code, crop_name, key, name, unit, description, data_type, value_num, value_text, value_bool, sort_order)
        VALUES
            (:workspace_id, :crop_code, :crop_name, :key, :name, :unit, :description, :data_type, :value_num, :value_text, :value_bool, :sort_order)
    ");

        foreach ($rows as $i => $r) {
            $cropCode = trim((string)($r['crop_code'] ?? ''));
            $key = trim((string)($r['key'] ?? ''));
            if ($cropCode === '' || $key === '') continue;

            $dataType = (string)($r['data_type'] ?? 'number');
            if (!in_array($dataType, ['number', 'text', 'bool'], true)) {
                $dataType = 'number';
            }

            $ins->execute([
                ':workspace_id' => $workspaceId,
                ':crop_code' => $cropCode,
                ':crop_name' => (($r['crop_name'] ?? null) !== null ? (string)$r['crop_name'] : null),
                ':key' => $key,
                ':name' => (($r['name'] ?? null) !== null ? (string)$r['name'] : null),
                ':unit' => (($r['unit'] ?? null) !== null ? (string)$r['unit'] : null),
                ':description' => (($r['description'] ?? null) !== null ? (string)$r['description'] : null),
                ':data_type' => $dataType,
                ':value_num' => $r['value_num'] ?? null,
                ':value_text' => $r['value_text'] ?? null,
                ':value_bool' => self::pgBool($r['value_bool'] ?? null),
                ':sort_order' => (int)($r['sort_order'] ?? $i),
            ]);
        }
    }

    private static function replaceRunInputsTx(PDO $pdo, int $workspaceId, array $runInputs): void
    {
        $pdo->prepare("DELETE FROM mca_workspace_run_variables WHERE workspace_id = :id")
            ->execute([':id' => $workspaceId]);

        $pdo->prepare("DELETE FROM mca_workspace_run_crop_factors WHERE workspace_id = :id")
            ->execute([':id' => $workspaceId]);

        $insVar = $pdo->prepare("
        INSERT INTO mca_workspace_run_variables
            (workspace_id, dataset_id, key, name, unit, description, data_type, value_num, value_text, value_bool, sort_order)
        VALUES
            (:workspace_id, :dataset_id, :key, :name, :unit, :description, :data_type, :value_num, :value_text, :value_bool, :sort_order)
    ");

        $insCrop = $pdo->prepare("
        INSERT INTO mca_workspace_run_crop_factors
            (workspace_id, dataset_id, crop_code, crop_name, key, name, unit, description, data_type, value_num, value_text, value_bool, sort_order)
        VALUES
            (:workspace_id, :dataset_id, :crop_code, :crop_name, :key, :name, :unit, :description, :data_type, :value_num, :value_text, :value_bool, :sort_order)
    ");

        foreach ($runInputs as $ri) {
            $datasetId = trim((string)($ri['dataset_id'] ?? ''));
            if ($datasetId === '') continue;

            foreach ((array)($ri['variables_run'] ?? []) as $i => $r) {
                $key = trim((string)($r['key'] ?? ''));
                if ($key === '') continue;

                $dataType = (string)($r['data_type'] ?? 'number');
                if (!in_array($dataType, ['number', 'text', 'bool'], true)) {
                    $dataType = 'number';
                }

                $insVar->execute([
                    ':workspace_id' => $workspaceId,
                    ':dataset_id' => $datasetId,
                    ':key' => $key,
                    ':name' => (($r['name'] ?? null) !== null ? (string)$r['name'] : null),
                    ':unit' => (($r['unit'] ?? null) !== null ? (string)$r['unit'] : null),
                    ':description' => (($r['description'] ?? null) !== null ? (string)$r['description'] : null),
                    ':data_type' => $dataType,
                    ':value_num' => $r['value_num'] ?? null,
                    ':value_text' => $r['value_text'] ?? null,
                    ':value_bool' => self::pgBool($r['value_bool'] ?? null),
                    ':sort_order' => (int)($r['sort_order'] ?? $i),
                ]);
            }

            foreach ((array)($ri['crop_factors'] ?? []) as $i => $r) {
                $cropCode = trim((string)($r['crop_code'] ?? ''));
                $key = trim((string)($r['key'] ?? ''));
                if ($cropCode === '' || $key === '') continue;

                $dataType = (string)($r['data_type'] ?? 'number');
                if (!in_array($dataType, ['number', 'text', 'bool'], true)) {
                    $dataType = 'number';
                }

                $insCrop->execute([
                    ':workspace_id' => $workspaceId,
                    ':dataset_id' => $datasetId,
                    ':crop_code' => $cropCode,
                    ':crop_name' => (($r['crop_name'] ?? null) !== null ? (string)$r['crop_name'] : null),
                    ':key' => $key,
                    ':name' => (($r['name'] ?? null) !== null ? (string)$r['name'] : null),
                    ':unit' => (($r['unit'] ?? null) !== null ? (string)$r['unit'] : null),
                    ':description' => (($r['description'] ?? null) !== null ? (string)$r['description'] : null),
                    ':data_type' => $dataType,
                    ':value_num' => $r['value_num'] ?? null,
                    ':value_text' => $r['value_text'] ?? null,
                    ':value_bool' => self::pgBool($r['value_bool'] ?? null),
                    ':sort_order' => (int)($r['sort_order'] ?? $i),
                ]);
            }
        }
    }
}