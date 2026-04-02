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
     * Presence check: does this run have yearly RCH-derived indicator rows?
     */
    public static function runHasRchRows(int $runId): bool
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
            SELECT EXISTS (
                SELECT 1
                FROM swat_indicator_yearly
                WHERE run_id = :run_id
                  AND indicator_code = 'sw_no3_kg_m3'
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

    public static function getYearlyAll(int $runId, array $opts = []): array
    {
        $out = [];
        foreach (SwatIndicatorRegistry::list() as $meta) {
            $code = $meta['code'];
            $out[$code] = self::getYearly($runId, $code, $opts);
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

        $meta = SwatIndicatorRegistry::meta($indicatorCode);
        $pdo = Database::pdo();

        $where = "run_id = :run_id AND indicator_code = :indicator_code";
        $params = [
            ':run_id' => $runId,
            ':indicator_code' => $indicatorCode,
        ];

        if (!empty($opts['sub'])) {
            $where .= " AND sub = :sub";
            $params[':sub'] = (int)$opts['sub'];
        }

        if (!empty($opts['crop'])) {
            $where .= " AND crop = :crop";
            $params[':crop'] = (string)$opts['crop'];
        }

        if ($fromNorm) {
            $where .= " AND year >= :from_year";
            $params[':from_year'] = (int)substr($fromNorm, 0, 4);
        }

        if ($toNorm) {
            $where .= " AND year <= :to_year";
            $params[':to_year'] = (int)substr($toNorm, 0, 4);
        }

        $stmt = $pdo->prepare("
            SELECT
                year,
                sub,
                NULLIF(crop, '') AS crop,
                value
            FROM swat_indicator_yearly
            WHERE {$where}
            ORDER BY year, sub, crop
        ");
        $stmt->execute($params);

        return self::ok($meta, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}