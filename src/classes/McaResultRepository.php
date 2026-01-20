<?php
// src/classes/McaResultRepository.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class McaResultRepository
{
    public static function byPresetSet(int $presetSetId, ?string $cropCode): array
    {
        $pdo = Database::pdo();

        $where = "r.preset_set_id = :ps";
        $params = [':ps' => $presetSetId];

        if ($cropCode !== null) {
            $where .= " AND r.crop_code = :crop";
            $params[':crop'] = $cropCode;
        }

        $sql = "
        SELECT
            s.id AS scenario_id,
            s.scenario_key,
            s.label AS scenario_label,
            i.code AS indicator_code,
            i.name AS indicator_name,
            COALESCE(pi.direction, i.default_direction) AS direction,
            pi.weight,
            r.crop_code,
            r.raw_value,
            r.normalized_score,
            r.weighted_score,
            r.computed_at
        FROM mca_results r
        JOIN mca_scenarios s ON s.id = r.scenario_id
        JOIN mca_indicators i ON i.id = r.indicator_id
        JOIN mca_preset_items pi
          ON pi.preset_set_id = r.preset_set_id AND pi.indicator_id = r.indicator_id
        WHERE {$where}
        ORDER BY s.sort_order, i.code
    ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function totalsByPresetSet(int $presetSetId, ?string $cropCode): array
    {
        $pdo = Database::pdo();

        $where = "t.preset_set_id = :ps";
        $params = [':ps' => $presetSetId];

        if ($cropCode !== null) {
            $where .= " AND t.crop_code = :crop";
            $params[':crop'] = $cropCode;
        }

        $sql = "
        SELECT
            t.run_id,
            t.scenario_id,
            t.scenario_key,
            t.label,
            t.crop_code,
            t.total_weighted_score
        FROM mca_totals t
        WHERE {$where}
        ORDER BY t.scenario_key
    ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}