<?php
// src/classes/StudyAreaRepository.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class StudyAreaRepository
{
    public static function all(): array
    {
        $pdo = Database::pdo();
        $sql = "
            SELECT
                sa.id,
                sa.name,
                sa.enabled,
                ST_AsGeoJSON(sa.geom)::json AS geom,
                COUNT(DISTINCT ss.id) AS subbasins,
                COUNT(DISTINCT sr.id) AS reaches
            FROM study_areas sa
            LEFT JOIN study_area_subbasins ss ON ss.study_area_id = sa.id
            LEFT JOIN study_area_reaches   sr ON sr.study_area_id = sa.id
            GROUP BY sa.id, sa.name, sa.enabled, sa.geom
            ORDER BY sa.name
        ";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(int $id): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            SELECT
                id,
                name,
                enabled,
                ST_AsGeoJSON(geom)::json AS geom
            FROM study_areas
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function create(string $name): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            INSERT INTO study_areas (name, enabled)
            VALUES (:name, TRUE)
            RETURNING id
        ");
        $stmt->execute([
            ':name' => $name,
        ]);
        return (int) $stmt->fetchColumn();
    }

    public static function updateGeom(int $id, string $geojson): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            UPDATE study_areas
            SET geom = ST_SetSRID(ST_GeomFromGeoJSON(:geom), 3857)
            WHERE id = :id
        ");
        $stmt->execute([
            ':id'   => $id,
            ':geom' => $geojson,
        ]);
    }

    public static function setEnabled(int $id, bool $enabled): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            UPDATE study_areas
            SET enabled = :enabled
            WHERE id = :id
        ");

        // Make sure types are explicit for Postgres
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);

        $stmt->execute();
    }

    public static function rebuildBoundaryFromSubbasins(int $studyAreaId): void
    {
        $pdo = Database::pdo();

        $sql = "
            UPDATE study_areas sa
            SET geom = sub_union.geom
            FROM (
                SELECT
                    study_area_id,
                    ST_Multi(
                        ST_CollectionExtract(
                            ST_UnaryUnion(
                                ST_Collect(ST_MakeValid(geom))
                            ),
                            3 -- 3 = POLYGON
                        )
                    ) AS geom
                FROM study_area_subbasins
                WHERE study_area_id = :id
                GROUP BY study_area_id
            ) AS sub_union
            WHERE sa.id = sub_union.study_area_id
              AND sa.id = :id
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $studyAreaId]);
    }
}