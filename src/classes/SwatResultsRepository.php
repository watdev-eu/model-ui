<?php
// src/classes/SwatResultsRepository.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/SwatRunRepository.php';
require_once __DIR__ . '/StudyAreaRepository.php';
require_once __DIR__ . '/SwatIndicatorRegistry.php';
require_once __DIR__ . '/SwatIndicatorConfig.php';

final class SwatResultsRepository
{
    /**
     * Tells you whether RCH queries should be attempted for this run.
     */
    public static function runHasRchEnabled(int $runId): bool
    {
        $run = SwatRunRepository::find($runId);
        if (!$run) {
            throw new InvalidArgumentException('Run not found');
        }
        return StudyAreaRepository::hasRchResults((int)$run['study_area_id']);
    }

    /**
     * Presence check: does this run have any rows in swat_rch_kpi?
     * Only call if RCH is enabled for the study area.
     */
    public static function runHasRchRows(int $runId): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            SELECT EXISTS (
                SELECT 1
                FROM swat_rch_kpi
                WHERE run_id = :run_id
                LIMIT 1
            )
        ");
        $stmt->execute([':run_id' => $runId]);
        $val = $stmt->fetchColumn();
        return $val === true || $val === 't' || $val === 1 || $val === '1';
    }

    /**
     * Standard response wrapper (so RCH can return not_applicable cleanly).
     */
    private static function ok(array $meta, array $rows): array
    {
        return ['status' => 'ok', 'meta' => $meta, 'rows' => $rows];
    }

    private static function notApplicable(array $meta, string $reason): array
    {
        return ['status' => 'not_applicable', 'meta' => $meta, 'reason' => $reason, 'rows' => []];
    }

    private static function normalizeRange(?string $from, ?string $to, string $scale): array
    {
        // returns [fromNorm, toNorm] or [null,null]
        $fromNorm = null;
        $toNorm   = null;

        if ($from) {
            $d = new DateTimeImmutable($from);
            if ($scale === 'year') {
                $fromNorm = $d->setDate((int)$d->format('Y'), 1, 1)->format('Y-m-d');
            } else { // month
                $fromNorm = $d->setDate((int)$d->format('Y'), (int)$d->format('m'), 1)->format('Y-m-d');
            }
        }

        if ($to) {
            $d = new DateTimeImmutable($to);
            if ($scale === 'year') {
                $toNorm = $d->setDate((int)$d->format('Y'), 1, 1)->format('Y-m-d');
            } else {
                $toNorm = $d->setDate((int)$d->format('Y'), (int)$d->format('m'), 1)->format('Y-m-d');
            }
        }

        return [$fromNorm, $toNorm];
    }

    public static function getMonthlyAll(int $runId, array $opts = []): array
    {
        $out = [];
        foreach (SwatIndicatorRegistry::list() as $meta) {
            $code = $meta['code'];
            $out[$code] = self::getMonthly($runId, $code, $opts);
        }
        return $out;
    }

    public static function getYearlyAll(int $runId, array $opts = []): array
    {
        $out = [];
        foreach (SwatIndicatorRegistry::list() as $meta) {
            $code = $meta['code'];
            $out[$code] = self::getYearly($runId, $code, $opts);
        }
        return $out;
    }

    public static function getMonthlyMany(int $runId, array $indicatorCodes, array $opts = []): array
    {
        $out = [];
        foreach ($indicatorCodes as $code) {
            $out[$code] = self::getMonthly($runId, $code, $opts);
        }
        return $out;
    }

    public static function getYearlyMany(int $runId, array $indicatorCodes, array $opts = []): array
    {
        $out = [];
        foreach ($indicatorCodes as $code) {
            $out[$code] = self::getYearly($runId, $code, $opts);
        }
        return $out;
    }

    /**
     * Public API: monthly values.
     * rows contain:
     *  - period_date (YYYY-MM-01)
     *  - sub (int)
     *  - crop (string|null)
     *  - value (float|int|null)  (bool is represented as 0/1; UI can map)
     */
    public static function getMonthly(int $runId, string $indicatorCode, array $opts = []): array
    {
        [$fromNorm, $toNorm] = self::normalizeRange($opts['from'] ?? null, $opts['to'] ?? null, 'month');
        if ($fromNorm) $opts['from'] = $fromNorm;
        if ($toNorm)   $opts['to']   = $toNorm;

        $def  = SwatIndicatorRegistry::get($indicatorCode);
        $meta = SwatIndicatorRegistry::meta($indicatorCode);

        return match ($def['source']) {
            'hru' => self::fetchHruMonthly($runId, $def, $meta, $opts),
            'snu' => self::fetchSnuMonthly($runId, $def, $meta, $opts),
            'rch' => self::fetchRchMonthly($runId, $def, $meta, $opts),
            default => throw new InvalidArgumentException("Unsupported source: {$def['source']}"),
        };
    }

    /**
     * Public API: yearly values.
     * rows contain:
     *  - year (int)
     *  - sub (int)
     *  - crop (string|null)
     *  - value
     */
    public static function getYearly(int $runId, string $indicatorCode, array $opts = []): array
    {
        [$fromNorm, $toNorm] = self::normalizeRange($opts['from'] ?? null, $opts['to'] ?? null, 'year');
        if ($fromNorm) $opts['from'] = $fromNorm;
        if ($toNorm)   $opts['to']   = $toNorm;

        $def  = SwatIndicatorRegistry::get($indicatorCode);
        $meta = SwatIndicatorRegistry::meta($indicatorCode);

        return match ($def['source']) {
            'hru' => self::fetchHruYearly($runId, $def, $meta, $opts),
            'snu' => self::fetchSnuYearly($runId, $def, $meta, $opts),
            'rch' => self::fetchRchYearly($runId, $def, $meta, $opts),
            default => throw new InvalidArgumentException("Unsupported source: {$def['source']}"),
        };
    }

    // ---------------- HRU ----------------

    private static function fetchHruMonthly(int $runId, array $def, array $meta, array $opts): array
    {
        $pdo = Database::pdo();
        $hru = $def['hru'];

        $where = "h.run_id = :run_id AND h.period_res = 'MONTHLY'";
        $params = [':run_id' => $runId];

        // optional filters
        if (!empty($opts['sub'])) {
            $where .= " AND h.sub = :sub";
            $params[':sub'] = (int)$opts['sub'];
        }
        if (!empty($opts['crop'])) {
            $where .= " AND h.lulc = :crop";
            $params[':crop'] = (string)$opts['crop'];
        }
        if (!empty($opts['from'])) {
            $where .= " AND h.period_date >= :from";
            $params[':from'] = (string)$opts['from'];
        }
        if (!empty($opts['to'])) {
            $where .= " AND h.period_date <= :to";
            $params[':to'] = (string)$opts['to'];
        }

        $selectValue = self::hruMonthlyValueExpr($hru, $opts);

        $sql = "
            SELECT
                h.period_date,
                h.sub,
                h.lulc AS crop,
                {$selectValue} AS value
            FROM swat_hru_kpi h
            WHERE {$where}
            GROUP BY h.period_date, h.sub, h.lulc
            ORDER BY h.period_date, h.sub, h.lulc
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return self::ok($meta, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function fetchHruYearly(int $runId, array $def, array $meta, array $opts): array
    {
        $pdo = Database::pdo();
        $hru = $def['hru'];

        $where = "h.run_id = :run_id AND h.period_res = 'MONTHLY'";
        $params = [':run_id' => $runId];

        if (!empty($opts['sub'])) {
            $where .= " AND h.sub = :sub";
            $params[':sub'] = (int)$opts['sub'];
        }
        if (!empty($opts['crop'])) {
            $where .= " AND h.lulc = :crop";
            $params[':crop'] = (string)$opts['crop'];
        }
        if (!empty($opts['from'])) {
            $where .= " AND h.period_date >= :from";
            $params[':from'] = (string)$opts['from'];
        }
        if (!empty($opts['to'])) {
            $where .= " AND h.period_date <= :to";
            $params[':to'] = (string)$opts['to'];
        }

        // First compute MONTHLY values per (month,sub,crop), THEN aggregate yearly from those monthly values.
        $monthlyValue = self::hruMonthlyValueExpr($hru, $opts);
        $yearAgg = $hru['yearly_agg'] ?? 'avg_months';

        if ($hru['type'] === 'bool_threshold') {
            // monthly boolean represented as 0/1 (ties => false): (AVG(flag_int) > 0.5)
            // yearly mode (ties => false): true_count > false_count
            $sql = "
                WITH monthly AS (
                    SELECT
                        date_trunc('month', h.period_date)::date AS period_date,
                        EXTRACT(YEAR FROM h.period_date)::int AS year,
                        h.sub,
                        h.lulc AS crop,
                        {$monthlyValue} AS value_int
                    FROM swat_hru_kpi h
                    WHERE {$where}
                    GROUP BY date_trunc('month', h.period_date), EXTRACT(YEAR FROM h.period_date), h.sub, h.lulc
                )
                SELECT
                    year,
                    sub,
                    crop,
                    CASE
                        WHEN SUM(CASE WHEN value_int = 1 THEN 1 ELSE 0 END) >
                             SUM(CASE WHEN value_int = 0 THEN 1 ELSE 0 END)
                        THEN 1 ELSE 0
                    END AS value
                FROM monthly
                GROUP BY year, sub, crop
                ORDER BY year, sub, crop
            ";
        } else {
            $aggSql = match ($yearAgg) {
                'sum_months' => 'SUM(value)',
                'max_month'  => 'MAX(value)',
                default      => 'AVG(value)', // avg_months
            };

            $sql = "
                WITH monthly AS (
                    SELECT
                        date_trunc('month', h.period_date)::date AS period_date,
                        EXTRACT(YEAR FROM h.period_date)::int AS year,
                        h.sub,
                        h.lulc AS crop,
                        {$monthlyValue} AS value
                    FROM swat_hru_kpi h
                    WHERE {$where}
                    GROUP BY date_trunc('month', h.period_date), EXTRACT(YEAR FROM h.period_date), h.sub, h.lulc
                )
                SELECT
                    year,
                    sub,
                    crop,
                    {$aggSql} AS value
                FROM monthly
                GROUP BY year, sub, crop
                ORDER BY year, sub, crop
            ";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return self::ok($meta, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function hruMonthlyValueExpr(array $hruDef, array $opts): string
    {
        // NOTE: returns an expression that is already aggregated across HRU rows in the group
        // (we group by period_date,sub,lulc and use AVG(...) in the expression).

        return match ($hruDef['type']) {
            'col' => "AVG(h.{$hruDef['col']})",

            'bool_threshold' => self::hruBoolThresholdExpr($hruDef, $opts),

            'expr_nue_n' => "
                AVG(
                    CASE
                        WHEN (h.n_app_kg_ha + h.nauto_kg_ha + h.ngraz_kg_ha + h.cfertn_kg_ha) > 0
                        THEN (h.yld_t_ha / (h.n_app_kg_ha + h.nauto_kg_ha + h.ngraz_kg_ha + h.cfertn_kg_ha)) * 100.0
                        ELSE NULL
                    END
                )
            ",

            'expr_nue_p' => "
                AVG(
                    CASE
                        WHEN (h.p_app_kg_ha + h.pauto_kg_ha + h.pgraz_kg_ha + h.cfertp_kg_ha) > 0
                        THEN (h.yld_t_ha / (h.p_app_kg_ha + h.pauto_kg_ha + h.pgraz_kg_ha + h.cfertp_kg_ha)) * 100.0
                        ELSE NULL
                    END
                )
            ",

            'expr_biom_frac' => self::hruBiomFracExpr($hruDef, $opts),

            default => throw new InvalidArgumentException("Unsupported HRU type: {$hruDef['type']}"),
        };
    }

    private static function hruBoolThresholdExpr(array $hruDef, array $opts): string
    {
        $threshold = SwatIndicatorConfig::get($opts['config'] ?? [], $hruDef['threshold_key']);

        // Monthly boolean for the group (ties => false): AVG(flag_int) > 0.5
        // Represent boolean as 0/1 in the DB result.
        return "
            CASE
                WHEN AVG(
                    CASE WHEN h.{$hruDef['col']} > {$threshold} THEN 1 ELSE 0 END
                ) > 0.5
                THEN 1 ELSE 0
            END
        ";
    }

    private static function hruBiomFracExpr(array $hruDef, array $opts): string
    {
        $frac = SwatIndicatorConfig::get($opts['config'] ?? [], $hruDef['factor_key']);
        return "AVG(h.biom_t_ha * {$frac})";
    }

    // ---------------- SNU ----------------

    private static function fetchSnuMonthly(int $runId, array $def, array $meta, array $opts): array
    {
        $pdo = Database::pdo();
        $snu = $def['snu'];

        $where = "s.run_id = :run_id";
        $params = [':run_id' => $runId];

        if (!empty($opts['sub'])) {
            // applied after mapping/fallback, so do it in outer query
            $params[':sub'] = (int)$opts['sub'];
        }
        if (!empty($opts['from'])) {
            $where .= " AND s.period_date >= :from";
            $params[':from'] = (string)$opts['from'];
        }
        if (!empty($opts['to'])) {
            $where .= " AND s.period_date <= :to";
            $params[':to'] = (string)$opts['to'];
        }

        $valueExpr = self::snuValueExpr($snu, $opts);

        $sql = "
            WITH hru_map AS (
                SELECT DISTINCT h.gis, h.sub
                FROM swat_hru_kpi h
                WHERE h.run_id = :run_id
            ),
            eom AS (
                SELECT
                    date_trunc('month', s.period_date)::date AS period_date,
                    COALESCE(m.sub, (s.gisnum / 10000)::int) AS sub,
                    {$valueExpr} AS value_raw
                FROM swat_snu_kpi s
                LEFT JOIN hru_map m ON m.gis = s.gisnum
                WHERE {$where}
                  AND s.period_date = (date_trunc('month', s.period_date) + interval '1 month - 1 day')::date
            )
            SELECT
                period_date,
                sub,
                NULL::text AS crop,
                AVG(value_raw) AS value
            FROM eom
            " . (!empty($opts['sub']) ? "WHERE sub = :sub" : "") . "
            GROUP BY period_date, sub
            ORDER BY period_date, sub
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return self::ok($meta, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function fetchSnuYearly(int $runId, array $def, array $meta, array $opts): array
    {
        $pdo = Database::pdo();
        $snu = $def['snu'];

        $where = "s.run_id = :run_id";
        $params = [':run_id' => $runId];

        if (!empty($opts['sub'])) {
            $params[':sub'] = (int)$opts['sub'];
        }
        if (!empty($opts['from'])) {
            $where .= " AND s.period_date >= :from";
            $params[':from'] = (string)$opts['from'];
        }
        if (!empty($opts['to'])) {
            $where .= " AND s.period_date <= :to";
            $params[':to'] = (string)$opts['to'];
        }

        $valueExpr = self::snuValueExpr($snu, $opts);

        // monthly first, then yearly avg (avg_months)
        $sql = "
            WITH hru_map AS (
                SELECT DISTINCT h.gis, h.sub
                FROM swat_hru_kpi h
                WHERE h.run_id = :run_id
            ),
            monthly AS (
                SELECT
                    EXTRACT(YEAR FROM s.period_date)::int AS year,
                    date_trunc('month', s.period_date)::date AS period_date,
                    COALESCE(m.sub, (s.gisnum / 10000)::int) AS sub,
                    AVG({$valueExpr}) AS value
                FROM swat_snu_kpi s
                LEFT JOIN hru_map m ON m.gis = s.gisnum
                WHERE {$where}
                  AND s.period_date = (date_trunc('month', s.period_date) + interval '1 month - 1 day')::date
                GROUP BY EXTRACT(YEAR FROM s.period_date), date_trunc('month', s.period_date), COALESCE(m.sub, (s.gisnum / 10000)::int)
            )
            SELECT
                year,
                sub,
                NULL::text AS crop,
                AVG(value) AS value
            FROM monthly
            " . (!empty($opts['sub']) ? "WHERE sub = :sub" : "") . "
            GROUP BY year, sub
            ORDER BY year, sub
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return self::ok($meta, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function snuValueExpr(array $snuDef, array $opts): string
    {
        return match ($snuDef['type']) {
            'col' => "s.{$snuDef['col']}",
            'expr_factor' => self::snuFactorExpr($snuDef, $opts),
            default => throw new InvalidArgumentException("Unsupported SNU type: {$snuDef['type']}"),
        };
    }

    private static function snuFactorExpr(array $snuDef, array $opts): string
    {
        $factor = SwatIndicatorConfig::get($opts['config'] ?? [], $snuDef['factor_key']);
        return "(s.{$snuDef['col']} * {$factor})";
    }

    // ---------------- RCH ----------------

    private static function fetchRchMonthly(int $runId, array $def, array $meta, array $opts): array
    {
        if (!self::runHasRchEnabled($runId)) {
            return self::notApplicable($meta, 'Study area has_rch_results=false');
        }

        $pdo = Database::pdo();
        $sec = SwatIndicatorConfig::get($opts['config'] ?? [], 'rch_flow_seconds_per_month_equiv');

        $where = "r.run_id = :run_id AND r.period_res = 'MONTHLY'";
        $params = [':run_id' => $runId];

        if (!empty($opts['sub'])) {
            $where .= " AND COALESCE(r.sub, r.rch) = :sub";
            $params[':sub'] = (int)$opts['sub'];
        }
        if (!empty($opts['from'])) {
            $where .= " AND r.period_date >= :from";
            $params[':from'] = (string)$opts['from'];
        }
        if (!empty($opts['to'])) {
            $where .= " AND r.period_date <= :to";
            $params[':to'] = (string)$opts['to'];
        }

        $sql = "
            SELECT
                r.period_date,
                COALESCE(r.sub, r.rch) AS sub,
                NULL::text AS crop,
                AVG(
                    CASE
                        WHEN r.flow_out_cms > 0
                        THEN (r.no3_out_kg / (r.flow_out_cms * {$sec})) * 1000.0
                        ELSE NULL
                    END
                ) AS value
            FROM swat_rch_kpi r
            WHERE {$where}
            GROUP BY r.period_date, COALESCE(r.sub, r.rch)
            ORDER BY r.period_date, sub
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return self::ok($meta, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function fetchRchYearly(int $runId, array $def, array $meta, array $opts): array
    {
        if (!self::runHasRchEnabled($runId)) {
            return self::notApplicable($meta, 'Study area has_rch_results=false');
        }

        $pdo = Database::pdo();
        $sec = SwatIndicatorConfig::get($opts['config'] ?? [], 'rch_flow_seconds_per_month_equiv');

        $where = "r.run_id = :run_id AND r.period_res = 'MONTHLY'";
        $params = [':run_id' => $runId];

        if (!empty($opts['sub'])) {
            $where .= " AND COALESCE(r.sub, r.rch) = :sub";
            $params[':sub'] = (int)$opts['sub'];
        }
        if (!empty($opts['from'])) {
            $where .= " AND r.period_date >= :from";
            $params[':from'] = (string)$opts['from'];
        }
        if (!empty($opts['to'])) {
            $where .= " AND r.period_date <= :to";
            $params[':to'] = (string)$opts['to'];
        }

        $sql = "
            WITH monthly AS (
                SELECT
                    EXTRACT(YEAR FROM r.period_date)::int AS year,
                    r.period_date,
                    COALESCE(r.sub, r.rch) AS sub,
                    AVG(
                        CASE
                            WHEN r.flow_out_cms > 0
                            THEN (r.no3_out_kg / (r.flow_out_cms * {$sec})) * 1000.0
                            ELSE NULL
                        END
                    ) AS value
                FROM swat_rch_kpi r
                WHERE {$where}
                GROUP BY EXTRACT(YEAR FROM r.period_date), r.period_date, COALESCE(r.sub, r.rch)
            )
            SELECT
                year,
                sub,
                NULL::text AS crop,
                AVG(value) AS value
            FROM monthly
            GROUP BY year, sub
            ORDER BY year, sub
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return self::ok($meta, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}