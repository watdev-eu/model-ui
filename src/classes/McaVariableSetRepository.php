<?php
// classes/McaVariableSetRepository.php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class McaVariableSetRepository
{
    public static function listForStudyArea(int $studyAreaId, ?string $userId): array
    {
        $pdo = Database::pdo();

        if ($userId === null || $userId === '') {
            $stmt = $pdo->prepare("
                SELECT
                    vs.*,
                    TRUE AS is_global
                FROM mca_variable_sets vs
                WHERE vs.study_area_id = :sa
                  AND vs.user_id IS NULL
                ORDER BY vs.is_default DESC, vs.name
            ");
            $stmt->execute([':sa' => $studyAreaId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $stmt = $pdo->prepare("
            SELECT
                vs.*,
                (vs.user_id IS NULL) AS is_global
            FROM mca_variable_sets vs
            WHERE vs.study_area_id = :sa
              AND (vs.user_id IS NULL OR vs.user_id = :uid)
            ORDER BY vs.is_default DESC, vs.user_id NULLS FIRST, vs.name
        ");
        $stmt->execute([
            ':sa' => $studyAreaId,
            ':uid' => $userId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function assertCanEdit(int $variableSetId, string $userId): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("SELECT user_id FROM mca_variable_sets WHERE id = :id");
        $stmt->execute([':id' => $variableSetId]);
        $owner = $stmt->fetchColumn();

        if ($owner === false) {
            throw new InvalidArgumentException('Variable set not found');
        }

        if ($owner === null) {
            throw new InvalidArgumentException('Default variable set cannot be edited');
        }

        if ((string)$owner !== $userId) {
            throw new InvalidArgumentException('Not allowed');
        }
    }

    public static function createUserSet(int $studyAreaId, string $userId, string $name): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            INSERT INTO mca_variable_sets (study_area_id, user_id, name, is_default)
            VALUES (:sa, :uid, :name, FALSE)
            RETURNING id
        ");
        $stmt->execute([
            ':sa' => $studyAreaId,
            ':uid' => $userId,
            ':name' => $name,
        ]);
        return (int)$stmt->fetchColumn();
    }

    public static function deleteSet(int $variableSetId, string $userId): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            DELETE FROM mca_variable_sets
            WHERE id = :id
              AND user_id = :uid
        ");
        $stmt->execute([
            ':id' => $variableSetId,
            ':uid' => $userId,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('Variable set not found or not allowed');
        }
    }
}