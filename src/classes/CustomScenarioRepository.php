<?php
// classes/CustomScenarioRepository.php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class CustomScenarioRepository
{
    public static function listByStudyAreaForUser(int $studyAreaId, string $userId): array
    {
        $pdo = Database::pdo();

        $sql = <<<SQL
            select
                cs.id,
                cs.study_area_id,
                cs.created_by,
                cs.name,
                cs.description,
                cs.created_at,
                cs.updated_at,
                count(ca.sub) as assigned_subbasins
            from custom_scenarios cs
            left join custom_scenario_subbasin_runs ca
                on ca.custom_scenario_id = cs.id
            where cs.study_area_id = :study_area_id
              and cs.created_by = :created_by
            group by
                cs.id, cs.study_area_id, cs.created_by,
                cs.name, cs.description, cs.created_at, cs.updated_at
            order by cs.updated_at desc, cs.name asc
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':study_area_id' => $studyAreaId,
            ':created_by'    => $userId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function findByIdForUser(int $scenarioId, string $userId): ?array
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'select * from custom_scenarios where id = :id and created_by = :created_by limit 1'
        );
        $stmt->execute([
            ':id'         => $scenarioId,
            ':created_by' => $userId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function findAssignments(int $scenarioId, string $userId): array
    {
        $pdo = Database::pdo();

        $sql = <<<SQL
            select
                ca.sub,
                ca.source_run_id
            from custom_scenario_subbasin_runs ca
            join custom_scenarios cs
              on cs.id = ca.custom_scenario_id
            where ca.custom_scenario_id = :scenario_id
              and cs.created_by = :created_by
            order by ca.sub asc
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':scenario_id' => $scenarioId,
            ':created_by'  => $userId,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(string)$row['sub']] = (int)$row['source_run_id'];
        }

        return $out;
    }

    public static function getBaselineRunId(int $studyAreaId): ?int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'select id
             from swat_runs
             where study_area = :study_area_id
               and is_baseline = true
             limit 1'
        );
        $stmt->execute([':study_area_id' => $studyAreaId]);

        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    public static function countSubbasins(int $studyAreaId): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'select count(*) from study_area_subbasins where study_area_id = :study_area_id'
        );
        $stmt->execute([':study_area_id' => $studyAreaId]);

        return (int)$stmt->fetchColumn();
    }

    public static function validateSubIds(int $studyAreaId, array $subIds): array
    {
        if (!$subIds) return [];

        $pdo = Database::pdo();
        $placeholders = implode(',', array_fill(0, count($subIds), '?'));

        $sql = "
            select sub
            from study_area_subbasins
            where study_area_id = ?
              and sub in ($placeholders)
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$studyAreaId], array_values($subIds)));

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function validateRunIds(int $studyAreaId, array $runIds): array
    {
        if (!$runIds) return [];

        $pdo = Database::pdo();
        $placeholders = implode(',', array_fill(0, count($runIds), '?'));

        $sql = "
            select id
            from swat_runs
            where study_area = ?
              and id in ($placeholders)
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$studyAreaId], array_values($runIds)));

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function create(
        int $studyAreaId,
        string $userId,
        string $name,
        ?string $description,
        array $assignments
    ): int {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'insert into custom_scenarios (study_area_id, created_by, name, description)
                 values (:study_area_id, :created_by, :name, :description)
                 returning id'
            );
            $stmt->execute([
                ':study_area_id' => $studyAreaId,
                ':created_by'    => $userId,
                ':name'          => $name,
                ':description'   => $description,
            ]);

            $scenarioId = (int)$stmt->fetchColumn();

            if ($assignments) {
                $ins = $pdo->prepare(
                    'insert into custom_scenario_subbasin_runs
                        (custom_scenario_id, study_area_id, sub, source_run_id)
                     values
                        (:custom_scenario_id, :study_area_id, :sub, :source_run_id)'
                );

                foreach ($assignments as $sub => $runId) {
                    $ins->execute([
                        ':custom_scenario_id' => $scenarioId,
                        ':study_area_id'      => $studyAreaId,
                        ':sub'                => (int)$sub,
                        ':source_run_id'      => (int)$runId,
                    ]);
                }
            }

            $pdo->commit();
            return $scenarioId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function update(
        int $scenarioId,
        string $userId,
        string $name,
        ?string $description,
        array $assignments
    ): void {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $scenario = self::findByIdForUser($scenarioId, $userId);
            if (!$scenario) {
                throw new RuntimeException('Scenario not found.');
            }

            $studyAreaId = (int)$scenario['study_area_id'];

            $stmt = $pdo->prepare(
                'update custom_scenarios
                 set name = :name,
                     description = :description
                 where id = :id
                   and created_by = :created_by'
            );
            $stmt->execute([
                ':id'          => $scenarioId,
                ':created_by'  => $userId,
                ':name'        => $name,
                ':description' => $description,
            ]);

            $del = $pdo->prepare(
                'delete from custom_scenario_subbasin_runs
                 where custom_scenario_id = :custom_scenario_id'
            );
            $del->execute([':custom_scenario_id' => $scenarioId]);

            if ($assignments) {
                $ins = $pdo->prepare(
                    'insert into custom_scenario_subbasin_runs
                        (custom_scenario_id, study_area_id, sub, source_run_id)
                     values
                        (:custom_scenario_id, :study_area_id, :sub, :source_run_id)'
                );

                foreach ($assignments as $sub => $runId) {
                    $ins->execute([
                        ':custom_scenario_id' => $scenarioId,
                        ':study_area_id'      => $studyAreaId,
                        ':sub'                => (int)$sub,
                        ':source_run_id'      => (int)$runId,
                    ]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function delete(int $scenarioId, string $userId): bool
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            'delete from custom_scenarios
             where id = :id
               and created_by = :created_by'
        );
        $stmt->execute([
            ':id'         => $scenarioId,
            ':created_by' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public static function getEffectiveRunMap(int $scenarioId, string $userId): array
    {
        $scenario = self::findByIdForUser($scenarioId, $userId);
        if (!$scenario) {
            throw new RuntimeException('Scenario not found.');
        }

        $studyAreaId = (int)$scenario['study_area_id'];
        $baselineRunId = self::getBaselineRunId($studyAreaId);

        if (!$baselineRunId) {
            throw new RuntimeException('No baseline run configured for this study area.');
        }

        $pdo = Database::pdo();

        $sql = <<<SQL
            select
                sb.sub,
                coalesce(ca.source_run_id, :baseline_run_id) as effective_run_id
            from study_area_subbasins sb
            left join custom_scenario_subbasin_runs ca
              on ca.study_area_id = sb.study_area_id
             and ca.sub = sb.sub
             and ca.custom_scenario_id = :scenario_id
            where sb.study_area_id = :study_area_id
            order by sb.sub asc
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':baseline_run_id' => $baselineRunId,
            ':scenario_id'     => $scenarioId,
            ':study_area_id'   => $studyAreaId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function existsForUser(int $scenarioId, string $userId): bool
    {
        return self::findByIdForUser($scenarioId, $userId) !== null;
    }
}