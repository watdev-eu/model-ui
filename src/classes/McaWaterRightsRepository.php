<?php
// src/classes/McaWaterRightsRepository.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class McaWaterRightsRepository
{
    /**
     * Read irrigated area context from persisted monthly context table.
     *
     * @return array{
     *   monthly_total_irrigated_area_ha: array<string,float>,
     *   yearly_avg_monthly_irrigated_area_ha: array<int,float>,
     *   monthly_irrigated_area_ha_by_sub: array<int, array<string,float>>,
     *   yearly_avg_monthly_irrigated_area_ha_by_sub: array<int, array<int,float>>
     * }
     */
    public static function getIrrigatedAreaHaMonthlyAndYearlyAvg(int $runId, array $opts = []): array
    {
        if ($runId <= 0) {
            throw new InvalidArgumentException('runId must be > 0');
        }

        $pdo = Database::pdo();

        $where = "run_id = :run_id";
        $params = [':run_id' => $runId];

        if (!empty($opts['sub'])) {
            $where .= " AND sub = :sub";
            $params[':sub'] = (int)$opts['sub'];
        }

        if (!empty($opts['from'])) {
            $from = new DateTimeImmutable((string)$opts['from']);
            $where .= " AND (year > :from_year OR (year = :from_year AND month >= :from_month))";
            $params[':from_year'] = (int)$from->format('Y');
            $params[':from_month'] = (int)$from->format('m');
        }

        if (!empty($opts['to'])) {
            $to = new DateTimeImmutable((string)$opts['to']);
            $where .= " AND (year < :to_year OR (year = :to_year AND month <= :to_month))";
            $params[':to_year'] = (int)$to->format('Y');
            $params[':to_month'] = (int)$to->format('m');
        }

        $stmt = $pdo->prepare("
            SELECT
                year,
                month,
                sub,
                irrigated_area_ha
            FROM swat_irrigation_area_context
            WHERE {$where}
            ORDER BY year, month, sub
        ");
        $stmt->execute($params);

        $monthlyBySub = [];
        $monthlyTotal = [];
        $monthsByYear = [];

        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $year = (int)($r['year'] ?? 0);
            $month = (int)($r['month'] ?? 0);
            $sub = (int)($r['sub'] ?? 0);
            $ha = (isset($r['irrigated_area_ha']) && is_numeric($r['irrigated_area_ha']))
                ? (float)$r['irrigated_area_ha']
                : 0.0;

            if ($year <= 0 || $month < 1 || $month > 12 || $sub <= 0) {
                continue;
            }

            $monthKey = sprintf('%04d-%02d-01', $year, $month);

            $monthlyBySub[$sub][$monthKey] = $ha;
            $monthlyTotal[$monthKey] = ($monthlyTotal[$monthKey] ?? 0.0) + $ha;
            $monthsByYear[$year][$monthKey] = true;
        }

        $yearlyTotal = [];
        foreach ($monthsByYear as $year => $monthSet) {
            $sum = 0.0;
            $n = 0;

            foreach (array_keys($monthSet) as $m) {
                if (isset($monthlyTotal[$m])) {
                    $sum += (float)$monthlyTotal[$m];
                    $n++;
                }
            }

            $yearlyTotal[(int)$year] = $n > 0 ? ($sum / $n) : 0.0;
        }

        $yearlyBySub = [];
        foreach ($monthlyBySub as $sub => $series) {
            foreach ($monthsByYear as $year => $monthSet) {
                $sum = 0.0;
                $n = 0;

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

        ksort($monthlyTotal);
        ksort($yearlyTotal);
        ksort($monthlyBySub);
        foreach ($monthlyBySub as $sub => $s) {
            ksort($monthlyBySub[$sub]);
        }
        ksort($yearlyBySub);
        foreach ($yearlyBySub as $sub => $s) {
            ksort($yearlyBySub[$sub]);
        }

        return [
            'monthly_total_irrigated_area_ha' => $monthlyTotal,
            'yearly_avg_monthly_irrigated_area_ha' => $yearlyTotal,
            'monthly_irrigated_area_ha_by_sub' => $monthlyBySub,
            'yearly_avg_monthly_irrigated_area_ha_by_sub' => $yearlyBySub,
        ];
    }
}