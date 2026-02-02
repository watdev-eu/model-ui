<?php
// src/classes/McaSwatInputsRepository.php
declare(strict_types=1);

require_once __DIR__ . '/SwatResultsRepository.php';
require_once __DIR__ . '/../config/database.php';

final class McaSwatInputsRepository
{
    /**
     * Get crop area (ha) per (sub, crop) from HRU areas.
     *
     * We avoid month duplication by taking MAX(area_km2) per (sub,gis,lulc) then summing.
     *
     * @return array<int, array<string,float>> [sub][crop] => ha
     */
    private static function cropAreaHaBySub(int $runId): array
    {
        $pdo = Database::pdo();

        $sql = "
            WITH hru_one AS (
                SELECT
                    h.sub,
                    h.lulc AS crop,
                    h.gis,
                    MAX(COALESCE(h.area_km2,0)) AS area_km2
                FROM swat_hru_kpi h
                WHERE h.run_id = :run_id
                GROUP BY h.sub, h.lulc, h.gis
            )
            SELECT
                sub,
                crop,
                SUM(area_km2) * 100.0 AS area_ha
            FROM hru_one
            WHERE crop IS NOT NULL AND crop <> ''
            GROUP BY sub, crop
            ORDER BY sub, crop
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':run_id' => $runId]);

        $out = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sub  = (int)($r['sub'] ?? 0);
            $crop = (string)($r['crop'] ?? '');
            $ha   = (isset($r['area_ha']) && is_numeric($r['area_ha'])) ? (float)$r['area_ha'] : null;
            if ($sub <= 0 || $crop === '' || $ha === null) continue;
            $out[$sub][$crop] = $ha;
        }

        ksort($out);
        foreach ($out as $sub => $m) ksort($out[$sub]);

        return $out;
    }

    /**
     * Fetch yearly values for multiple SWAT indicators, keeping subbasins.
     *
     * Returns both:
     * - by_sub: [sub][crop][year] => value
     * - overall: [crop][year] => area-weighted across subs (using crop area in sub)
     *
     * @param int $runId
     * @param string[] $swatIndicatorCodes
     * @param array{from?:string,to?:string,sub?:int} $opts
     * @param string|null $cropFilter
     * @return array
     */
    public static function getYearlyPerCropManyWithSub(
        int $runId,
        array $swatIndicatorCodes,
        array $opts = [],
        ?string $cropFilter = null
    ): array {
        if ($runId <= 0) throw new InvalidArgumentException('runId must be > 0');

        $codes = array_values(array_filter(array_map('strval', $swatIndicatorCodes)));
        if (!$codes) return [];

        $raw = SwatResultsRepository::getYearlyMany($runId, $codes, $opts);

        // weights for sub->overall aggregation
        $subCropAreaHa = self::cropAreaHaBySub($runId);

        $out = [];
        foreach ($codes as $code) {
            $block = $raw[$code] ?? null;
            if (!is_array($block) || ($block['status'] ?? null) !== 'ok') {
                $out[$code] = ['overall' => [], 'by_sub' => [], 'sub_crop_area_ha' => $subCropAreaHa];
                continue;
            }

            $rows = $block['rows'] ?? [];
            if (!is_array($rows)) {
                $out[$code] = ['overall' => [], 'by_sub' => [], 'sub_crop_area_ha' => $subCropAreaHa];
                continue;
            }

            $bySub = [];     // [sub][crop][year] => val
            $yearsSeen = []; // for ordering

            foreach ($rows as $r) {
                $year = isset($r['year']) ? (int)$r['year'] : 0;
                $sub  = isset($r['sub']) ? (int)$r['sub'] : 0;
                $crop = $r['crop'] ?? null;

                if ($year <= 0) continue;
                if ($sub <= 0) continue;
                if ($crop === null || $crop === '') continue;

                $cropCode = (string)$crop;
                if ($cropFilter !== null && $cropCode !== $cropFilter) continue;

                $val = $r['value'] ?? null;
                $num = ($val === null || $val === '') ? null : (is_numeric($val) ? (float)$val : null);

                $bySub[$sub][$cropCode][$year] = $num;
                $yearsSeen[$year] = true;
            }

            // overall per crop/year (area-weighted across subs)
            $overall = []; // [crop][year] => val
            foreach ($bySub as $sub => $crops) {
                foreach ($crops as $crop => $series) {
                    $area = $subCropAreaHa[$sub][$crop] ?? null;
                    if ($area === null || $area <= 0) continue;

                    foreach ($series as $year => $val) {
                        if ($val === null || !is_numeric($val)) continue;
                        $y = (int)$year;
                        $overall[$crop][$y] = $overall[$crop][$y] ?? ['num' => 0.0, 'den' => 0.0];
                        $overall[$crop][$y]['num'] += ((float)$val) * (float)$area;
                        $overall[$crop][$y]['den'] += (float)$area;
                    }
                }
            }

            $overallFinal = [];
            foreach ($overall as $crop => $series) {
                foreach ($series as $year => $nd) {
                    $den = $nd['den'] ?? 0.0;
                    $overallFinal[$crop][(int)$year] = ($den > 0) ? (($nd['num'] ?? 0.0) / $den) : null;
                }
            }

            // sorting
            ksort($bySub);
            foreach ($bySub as $sub => $crops) {
                ksort($bySub[$sub]);
                foreach ($bySub[$sub] as $crop => $series) ksort($bySub[$sub][$crop]);
            }
            ksort($overallFinal);
            foreach ($overallFinal as $crop => $series) ksort($overallFinal[$crop]);

            $out[$code] = [
                'overall' => $overallFinal,
                'by_sub' => $bySub,
                'sub_crop_area_ha' => $subCropAreaHa,
            ];
        }

        return $out;
    }
}