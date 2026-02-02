<?php
// src/classes/McaWaterRightsRepository.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class McaWaterRightsRepository
{
    /**
     * Compute irrigated area (irr_mm > 0) from swat_hru_kpi.
     *
     * HRU rows are per crop, per HRU, per subbasin, per month.
     * We aggregate:
     * 1) (sub, month): SUM(area_km2 WHERE irr_mm > 0)  -- collapses crop/HRU dimensions
     * 2) (month total): SUM across subs
     * 3) (year): AVG across months
     *
     * Returns hectares (km2 * 100).
     *
     * @return array{
     *   monthly_total_irrigated_area_ha: array<string,float>,                 // 'YYYY-MM-01' => ha
     *   yearly_avg_monthly_irrigated_area_ha: array<int,float>,              // year => ha
     *   monthly_irrigated_area_ha_by_sub: array<int, array<string,float>>,   // sub => ['YYYY-MM-01'=>ha]
     *   yearly_avg_monthly_irrigated_area_ha_by_sub: array<int, array<int,float>> // sub => [year=>ha]
     * }
     */
    public static function getIrrigatedAreaHaMonthlyAndYearlyAvg(int $runId, array $opts = []): array
    {
        if ($runId <= 0) throw new InvalidArgumentException('runId must be > 0');

        $pdo = Database::pdo();

        $where = "h.run_id = :run_id AND h.period_res = 'MONTHLY'";
        $params = [':run_id' => $runId];

        // optional filters
        if (!empty($opts['sub'])) {
            $where .= " AND h.sub = :sub";
            $params[':sub'] = (int)$opts['sub'];
        }
        if (!empty($opts['from'])) {
            $where .= " AND h.period_date >= :from";
            $params[':from'] = (string)$opts['from'];
        }
        if (!empty($opts['to'])) {
            $where .= " AND h.period_date <= :to";
            $params[':to'] = (string)$opts['to'];
        }

        // 1) sub+month irrigated area km2 (sum area_km2 where irr_mm > 0)
        $sql = "
            SELECT
                date_trunc('month', h.period_date)::date AS month,
                EXTRACT(YEAR FROM h.period_date)::int AS year,
                h.sub,
                SUM(
                    CASE
                        WHEN COALESCE(h.irr_mm, 0) > 0
                        THEN COALESCE(h.area_km2, 0)
                        ELSE 0
                    END
                ) AS irrigated_area_km2
            FROM swat_hru_kpi h
            WHERE {$where}
            GROUP BY date_trunc('month', h.period_date), EXTRACT(YEAR FROM h.period_date), h.sub
            ORDER BY month, h.sub
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $monthlyBySub = [];      // [sub][month] => ha
        $monthlyTotal = [];      // [month] => ha
        $monthsByYear = [];      // [year][month] => true (for averaging)

        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $month = (string)($r['month'] ?? '');
            $year  = (int)($r['year'] ?? 0);
            $sub   = (int)($r['sub'] ?? 0);
            $km2   = (isset($r['irrigated_area_km2']) && is_numeric($r['irrigated_area_km2']))
                ? (float)$r['irrigated_area_km2']
                : 0.0;

            if ($month === '' || $year <= 0 || $sub <= 0) continue;

            $ha = $km2 * 100.0;

            $monthlyBySub[$sub][$month] = $ha;
            $monthlyTotal[$month] = ($monthlyTotal[$month] ?? 0.0) + $ha;

            $monthsByYear[$year][$month] = true;
        }

        // 3) yearly averages (avg across months)
        $yearlyTotal = [];       // [year] => ha (avg monthly total)
        foreach ($monthsByYear as $year => $monthSet) {
            $sum = 0.0; $n = 0;
            foreach (array_keys($monthSet) as $m) {
                if (isset($monthlyTotal[$m])) {
                    $sum += (float)$monthlyTotal[$m];
                    $n++;
                }
            }
            $yearlyTotal[(int)$year] = $n > 0 ? ($sum / $n) : 0.0;
        }

        $yearlyBySub = [];       // [sub][year] => ha (avg monthly sub area)
        foreach ($monthlyBySub as $sub => $series) {
            foreach ($monthsByYear as $year => $monthSet) {
                $sum = 0.0; $n = 0;
                foreach (array_keys($monthSet) as $m) {
                    if (isset($series[$m])) {
                        $sum += (float)$series[$m];
                        $n++;
                    }
                }
                if ($n > 0) {
                    $yearlyBySub[(int)$sub][(int)$year] = $sum / $n;
                }
            }
        }

        // stable ordering
        ksort($monthlyTotal);
        ksort($yearlyTotal);
        ksort($monthlyBySub);
        foreach ($monthlyBySub as $sub => $s) ksort($monthlyBySub[$sub]);
        ksort($yearlyBySub);
        foreach ($yearlyBySub as $sub => $s) ksort($yearlyBySub[$sub]);

        return [
            'monthly_total_irrigated_area_ha' => $monthlyTotal,
            'yearly_avg_monthly_irrigated_area_ha' => $yearlyTotal,
            'monthly_irrigated_area_ha_by_sub' => $monthlyBySub,
            'yearly_avg_monthly_irrigated_area_ha_by_sub' => $yearlyBySub,
        ];
    }
}