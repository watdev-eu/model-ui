<?php
// src/classes/McaSwatInputsRepository.php
declare(strict_types=1);

require_once __DIR__ . '/SwatResultsRepository.php';
require_once __DIR__ . '/SwatIndicatorRegistry.php';
require_once __DIR__ . '/../config/database.php';

final class McaSwatInputsRepository
{
    /**
     * Get crop area (ha) per (sub, crop) from persisted crop-area context.
     *
     * @return array<int, array<string,float>> [sub][crop] => ha
     */
    private static function cropAreaHaBySub(int $runId): array
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare("
            SELECT sub, crop, area_ha
            FROM swat_crop_area_context
            WHERE run_id = :run_id
            ORDER BY sub, crop
        ");
        $stmt->execute([':run_id' => $runId]);

        $out = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sub  = (int)($r['sub'] ?? 0);
            $crop = (string)($r['crop'] ?? '');
            $ha   = (isset($r['area_ha']) && is_numeric($r['area_ha'])) ? (float)$r['area_ha'] : null;

            if ($sub <= 0 || $crop === '' || $ha === null) {
                continue;
            }

            $out[$sub][$crop] = $ha;
        }

        ksort($out);
        foreach ($out as $sub => $m) {
            ksort($out[$sub]);
        }

        return $out;
    }

    /**
     * Fetch yearly values for multiple SWAT indicators, keeping subbasins.
     *
     * Supported shapes by grain:
     *
     * grain=sub_crop:
     * - by_sub: [sub][crop][year] => value
     * - overall: [crop][year] => area-weighted across subs (using crop area in sub)
     * - sub_crop_area_ha: [sub][crop] => ha
     *
     * grain=sub:
     * - by_sub: [sub][year] => value
     * - overall: [year] => mean across subs
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
        if ($runId <= 0) {
            throw new InvalidArgumentException('runId must be > 0');
        }

        $codes = array_values(array_filter(array_map('strval', $swatIndicatorCodes)));
        if (!$codes) {
            return [];
        }

        $raw = SwatResultsRepository::getYearlyMany($runId, $codes, $opts);
        $subCropAreaHa = self::cropAreaHaBySub($runId);

        $out = [];

        foreach ($codes as $code) {
            $meta = SwatIndicatorRegistry::meta($code);
            $grain = (string)($meta['grain'] ?? 'sub');

            $block = $raw[$code] ?? null;
            $rows = (is_array($block) && ($block['status'] ?? null) === 'ok' && is_array($block['rows'] ?? null))
                ? $block['rows']
                : null;

            if ($rows === null) {
                $out[$code] = ($grain === 'sub_crop')
                    ? [
                        'grain' => 'sub_crop',
                        'overall' => [],
                        'by_sub' => [],
                        'sub_crop_area_ha' => $subCropAreaHa,
                    ]
                    : [
                        'grain' => 'sub',
                        'overall' => [],
                        'by_sub' => [],
                    ];
                continue;
            }

            if ($grain === 'sub_crop') {
                $bySub = [];

                foreach ($rows as $r) {
                    $year = isset($r['year']) ? (int)$r['year'] : 0;
                    $sub  = isset($r['sub']) ? (int)$r['sub'] : 0;
                    $crop = isset($r['crop']) ? trim((string)$r['crop']) : '';

                    if ($year <= 0 || $sub <= 0 || $crop === '') {
                        continue;
                    }
                    if ($cropFilter !== null && $crop !== $cropFilter) {
                        continue;
                    }

                    $val = $r['value'] ?? null;
                    $num = ($val === null || $val === '') ? null : (is_numeric($val) ? (float)$val : null);

                    $bySub[$sub][$crop][$year] = $num;
                }

                $overall = [];
                foreach ($bySub as $sub => $crops) {
                    foreach ($crops as $crop => $series) {
                        $area = $subCropAreaHa[$sub][$crop] ?? null;
                        if ($area === null || $area <= 0) {
                            continue;
                        }

                        foreach ($series as $year => $val) {
                            if ($val === null || !is_numeric($val)) {
                                continue;
                            }

                            $year = (int)$year;
                            $overall[$crop][$year] = $overall[$crop][$year] ?? ['num' => 0.0, 'den' => 0.0];
                            $overall[$crop][$year]['num'] += (float)$val * (float)$area;
                            $overall[$crop][$year]['den'] += (float)$area;
                        }
                    }
                }

                $overallFinal = [];
                foreach ($overall as $crop => $series) {
                    foreach ($series as $year => $nd) {
                        $den = (float)($nd['den'] ?? 0.0);
                        $overallFinal[$crop][(int)$year] = $den > 0 ? ((float)$nd['num'] / $den) : null;
                    }
                    ksort($overallFinal[$crop]);
                }

                ksort($bySub);
                foreach ($bySub as $sub => $crops) {
                    ksort($bySub[$sub]);
                    foreach ($bySub[$sub] as $crop => $series) {
                        ksort($bySub[$sub][$crop]);
                    }
                }

                ksort($overallFinal);

                $out[$code] = [
                    'grain' => 'sub_crop',
                    'overall' => $overallFinal,
                    'by_sub' => $bySub,
                    'sub_crop_area_ha' => $subCropAreaHa,
                ];
                continue;
            }

            // grain = sub
            $bySub = [];
            foreach ($rows as $r) {
                $year = isset($r['year']) ? (int)$r['year'] : 0;
                $sub  = isset($r['sub']) ? (int)$r['sub'] : 0;

                if ($year <= 0 || $sub <= 0) {
                    continue;
                }

                $val = $r['value'] ?? null;
                $num = ($val === null || $val === '') ? null : (is_numeric($val) ? (float)$val : null);

                $bySub[$sub][$year] = $num;
            }

            $subWeights = self::subWeightsFromCropArea($subCropAreaHa);

            $overallNum = [];
            $overallDen = [];

            foreach ($bySub as $sub => $series) {
                $w = (float)($subWeights[(int)$sub] ?? 0.0);
                if ($w <= 0) continue;

                foreach ($series as $year => $val) {
                    if ($val === null || !is_numeric($val)) {
                        continue;
                    }
                    $year = (int)$year;
                    $overallNum[$year] = ($overallNum[$year] ?? 0.0) + ((float)$val * $w);
                    $overallDen[$year] = ($overallDen[$year] ?? 0.0) + $w;
                }
            }

            $overallFinal = [];
            foreach ($overallNum as $year => $num) {
                $den = (float)($overallDen[$year] ?? 0.0);
                $overallFinal[(int)$year] = $den > 0 ? ($num / $den) : null;
            }

            ksort($bySub);
            foreach ($bySub as $sub => $series) {
                ksort($bySub[$sub]);
            }
            ksort($overallFinal);

            $out[$code] = [
                'grain' => 'sub',
                'overall' => $overallFinal,
                'by_sub' => $bySub,
                'sub_weights' => $subWeights,
            ];
        }

        return $out;
    }

    /**
     * @param array<int,array<string,float>> $subCropAreaHa
     * @return array<int,float> [sub] => total_ha
     */
    private static function subWeightsFromCropArea(array $subCropAreaHa): array
    {
        $out = [];
        foreach ($subCropAreaHa as $sub => $crops) {
            $sum = 0.0;
            foreach (($crops ?? []) as $crop => $ha) {
                if (!is_numeric($ha) || (float)$ha <= 0) continue;
                $sum += (float)$ha;
            }
            if ($sum > 0) {
                $out[(int)$sub] = $sum;
            }
        }
        ksort($out);
        return $out;
    }
}