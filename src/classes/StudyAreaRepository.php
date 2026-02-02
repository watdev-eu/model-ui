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
                sa.has_rch_results,
                ST_AsGeoJSON(sa.geom)::json AS geom,
                COUNT(DISTINCT ss.id) AS subbasins,
                COUNT(DISTINCT sr.id) AS reaches
            FROM study_areas sa
            LEFT JOIN study_area_subbasins ss ON ss.study_area_id = sa.id
            LEFT JOIN study_area_reaches   sr ON sr.study_area_id = sa.id
            GROUP BY sa.id, sa.name, sa.enabled, sa.has_rch_results, sa.geom
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
                has_rch_results,
                ST_AsGeoJSON(geom)::json AS geom
            FROM study_areas
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function create(string $name, bool $hasRchResults = true): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            INSERT INTO study_areas (name, enabled, has_rch_results)
            VALUES (:name, TRUE, :has_rch_results)
            RETURNING id
        ");

        // Explicit bindings for Postgres
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':has_rch_results', $hasRchResults, PDO::PARAM_BOOL);

        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    public static function createWithMcaDefaults(string $name, bool $hasRchResults = true): array
    {
        $pdo = Database::pdo();

        // Expect caller to be in a transaction (but don't require it).
        // We won't begin/commit/rollback here.

        // 1) Create study area
        $stmt = $pdo->prepare("
            INSERT INTO study_areas (name, enabled, has_rch_results)
            VALUES (:name, TRUE, :has_rch_results)
            RETURNING id
        ");
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':has_rch_results', $hasRchResults, PDO::PARAM_BOOL);
        $stmt->execute();
        $studyAreaId = (int)$stmt->fetchColumn();

        // 2) Create default preset set
        $stmtPreset = $pdo->prepare("
            INSERT INTO mca_preset_sets (study_area_id, user_id, name, is_default)
            VALUES (:sa, NULL, 'Default MCA', TRUE)
            RETURNING id
        ");
        $stmtPreset->execute([':sa' => $studyAreaId]);
        $presetSetId = (int)$stmtPreset->fetchColumn();

        // 3) Seed preset items
        $stmtSeedItems = $pdo->prepare("
            INSERT INTO mca_preset_items (preset_set_id, indicator_id, weight, direction, is_enabled)
            SELECT :ps, i.id, :w, NULL, TRUE
            FROM mca_indicators i
            ORDER BY i.code
            ON CONFLICT (preset_set_id, indicator_id) DO NOTHING
        ");
        $stmtSeedItems->execute([
            ':ps' => $presetSetId,
            ':w'  => 10,
        ]);

        // 4) Create default variable set linked to preset set
        $stmtVarSet = $pdo->prepare("
            INSERT INTO mca_variable_sets (study_area_id, user_id, name, is_default, preset_set_id)
            VALUES (:sa, NULL, 'Default MCA variables', TRUE, :ps)
            RETURNING id
        ");
        $stmtVarSet->execute([':sa' => $studyAreaId, ':ps' => $presetSetId]);
        $varSetId = (int)$stmtVarSet->fetchColumn();

        return [
            'study_area_id' => $studyAreaId,
            'preset_set_id' => $presetSetId,
            'variable_set_id' => $varSetId,
        ];
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

    public static function hasRchResults(int $studyAreaId): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
        SELECT has_rch_results
        FROM study_areas
        WHERE id = :id
    ");
        $stmt->execute([':id' => $studyAreaId]);
        $val = $stmt->fetchColumn();

        // If study area missing, treat as false
        return $val === true || $val === 't' || $val === 1 || $val === '1';
    }

    public static function setHasRchResults(int $studyAreaId, bool $has): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
        UPDATE study_areas
        SET has_rch_results = :has
        WHERE id = :id
    ");
        $stmt->bindValue(':id', $studyAreaId, PDO::PARAM_INT);
        $stmt->bindValue(':has', $has, PDO::PARAM_BOOL);
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