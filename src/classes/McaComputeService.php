<?php
// src/classes/McaComputeService.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/SwatRunRepository.php';
require_once __DIR__ . '/McaDependencyRegistry.php';
require_once __DIR__ . '/McaSwatInputsRepository.php';
require_once __DIR__ . '/McaWaterRightsRepository.php';
require_once __DIR__ . '/DashboardDatasetKey.php';
require_once __DIR__ . '/CustomScenarioRepository.php';
require_once __DIR__ . '/McaIndicatorRegistry.php';
require_once __DIR__ . '/McaSpatialResultsBuilder.php';
require_once __DIR__ . '/SwatIndicatorRegistry.php';

final class McaComputeService
{
    /**
     * Resolve study_area_id from preset set, then resolve baseline_run_id from swat_runs
     * using the canonical is_baseline flag.
     *
     * @return array{baseline_run_id:int, study_area_id:int}
     */
    public static function resolvePresetContext(int $presetSetId): array
    {
        $pdo = Database::pdo();

        // 1) preset set -> study_area_id
        $stmt = $pdo->prepare("
            SELECT
                id,
                study_area_id
            FROM mca_preset_sets
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $presetSetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new InvalidArgumentException('Preset set not found');
        }

        $studyAreaId = (int)($row['study_area_id'] ?? 0);
        if ($studyAreaId <= 0) {
            throw new RuntimeException('Preset set has no study_area_id');
        }

        // 2) study_area_id -> baseline_run_id (canonical baseline)
        $stmt = $pdo->prepare("
            SELECT id
            FROM swat_runs
            WHERE study_area = :sa
              AND is_baseline = TRUE
            LIMIT 1
        ");
        $stmt->execute([':sa' => $studyAreaId]);
        $baselineRunId = (int)($stmt->fetchColumn() ?: 0);

        if ($baselineRunId <= 0) {
            throw new RuntimeException("Baseline run not found for study_area {$studyAreaId}");
        }

        return [
            'baseline_run_id' => $baselineRunId,
            'study_area_id'   => $studyAreaId,
        ];
    }

    private static function fetchIndicatorMeta(array $calcKeys): array
    {
        $calcKeys = array_values(array_unique(array_filter(array_map('strval', $calcKeys))));
        if (!$calcKeys) return [];

        $ordered = [];
        foreach ($calcKeys as $code) {
            try {
                $meta = McaIndicatorRegistry::meta($code);
                $ordered[] = [
                    'calc_key' => $code,
                    'code'     => (string)($meta['code'] ?? $code),
                    'name'     => (string)($meta['name'] ?? $code),
                    'unit'     => $meta['unit'] ?? null,
                ];
            } catch (\Throwable $e) {
                $ordered[] = [
                    'calc_key' => $code,
                    'code'     => $code,
                    'name'     => $code,
                    'unit'     => null,
                ];
            }
        }

        return $ordered;
    }

    /**
     * Ensure all runs exist and belong to the same study area as the preset.
     * @param int $studyAreaId
     * @param int[] $runIds
     * @return int[] normalized unique runIds
     */
    public static function validateRunIds(int $studyAreaId, array $runIds): array
    {
        $uniq = [];
        foreach ($runIds as $id) {
            $n = (int)$id;
            if ($n > 0) $uniq[$n] = true;
        }
        $ids = array_keys($uniq);
        if (!$ids) {
            throw new InvalidArgumentException('No run ids provided');
        }

        foreach ($ids as $rid) {
            $run = SwatRunRepository::find($rid);
            if (!$run) {
                throw new InvalidArgumentException("Run not found: {$rid}");
            }
            $sa = (int)($run['study_area_id'] ?? 0);
            if ($sa !== $studyAreaId) {
                throw new InvalidArgumentException("Run {$rid} does not belong to study area {$studyAreaId}");
            }
        }

        sort($ids);
        return $ids;
    }

    /**
     * @param int $studyAreaId
     * @param string[] $datasetIds
     * @param ?string $userId
     * @return array<int,array{
     *   dataset_id:string,
     *   dataset_type:string,
     *   source_run_ids:int[],
     *   effective_run_map:?array<int,int>
     * }>
     */
    public static function resolveDatasetContexts(int $studyAreaId, array $datasetIds, ?string $userId): array
    {
        $out = [];
        $seen = [];

        foreach ($datasetIds as $rawId) {
            $datasetId = trim((string)$rawId);
            if ($datasetId === '' || isset($seen[$datasetId])) {
                continue;
            }
            $seen[$datasetId] = true;

            $parsed = DashboardDatasetKey::parse($datasetId);

            if ($parsed['type'] === 'run') {
                $runId = (int)$parsed['id'];
                $run = SwatRunRepository::find($runId);
                if (!$run) {
                    throw new InvalidArgumentException("Run not found: {$runId}");
                }
                $sa = (int)($run['study_area_id'] ?? 0);
                if ($sa !== $studyAreaId) {
                    throw new InvalidArgumentException("Run {$runId} does not belong to study area {$studyAreaId}");
                }

                $out[] = [
                    'dataset_id' => $datasetId,
                    'dataset_type' => 'run',
                    'source_run_ids' => [$runId],
                    'effective_run_map' => null,
                ];
                continue;
            }

            if ($userId === null || $userId === '') {
                throw new InvalidArgumentException("Custom scenario requires login: {$datasetId}");
            }

            $scenarioId = (int)$parsed['id'];
            $scenario = CustomScenarioRepository::findByIdForUser($scenarioId, $userId);
            if (!$scenario) {
                throw new InvalidArgumentException("Custom scenario not found: {$datasetId}");
            }

            $sa = (int)($scenario['study_area_id'] ?? 0);
            if ($sa !== $studyAreaId) {
                throw new InvalidArgumentException("Custom scenario {$datasetId} does not belong to study area {$studyAreaId}");
            }

            $effectiveRunMap = CustomScenarioRepository::getEffectiveRunMapForUser($scenarioId, $userId);
            $sourceRunIds = array_values(array_unique(array_map('intval', array_values($effectiveRunMap))));
            sort($sourceRunIds);

            if (!$sourceRunIds) {
                throw new InvalidArgumentException("Custom scenario {$datasetId} has no effective source runs");
            }

            $out[] = [
                'dataset_id' => $datasetId,
                'dataset_type' => 'custom',
                'source_run_ids' => $sourceRunIds,
                'effective_run_map' => $effectiveRunMap,
            ];
        }

        if (!$out) {
            throw new InvalidArgumentException('No dataset ids provided');
        }

        return $out;
    }

    /**
     * Very first MCA-compatible dataset resolver:
     * - run dataset: returns the run's SWAT series directly
     * - custom dataset: currently uses the first effective source run as representative
     *
     * This keeps MCA operational while we introduce custom datasets.
     * Later we can replace this with true per-subbasin merging.
     */
    private static function buildDatasetSwatSeries(
        array $datasetCtx,
        array $swatSeriesByRun
    ): array {
        $datasetType = (string)($datasetCtx['dataset_type'] ?? 'run');

        if ($datasetType === 'custom') {
            return self::buildMergedCustomDatasetSwatSeries($datasetCtx, $swatSeriesByRun);
        }

        $sourceRunIds = $datasetCtx['source_run_ids'] ?? [];
        if (!$sourceRunIds) {
            return [];
        }

        $runId = (int)$sourceRunIds[0];
        return $swatSeriesByRun[$runId] ?? [];
    }

    private static function buildOverallFromMergedBySub(array $bySub, array $subCropAreaHa): array
    {
        $overall = [];

        foreach ($bySub as $sub => $crops) {
            foreach ($crops as $crop => $series) {
                $area = $subCropAreaHa[$sub][$crop] ?? null;
                if ($area === null || $area <= 0) continue;

                foreach ($series as $year => $val) {
                    if ($val === null || !is_numeric($val)) continue;

                    $year = (int)$year;
                    $overall[$crop][$year] = $overall[$crop][$year] ?? ['num' => 0.0, 'den' => 0.0];
                    $overall[$crop][$year]['num'] += (float)$val * (float)$area;
                    $overall[$crop][$year]['den'] += (float)$area;
                }
            }
        }

        $final = [];
        foreach ($overall as $crop => $series) {
            foreach ($series as $year => $nd) {
                $den = (float)($nd['den'] ?? 0.0);
                $final[$crop][(int)$year] = $den > 0 ? ((float)$nd['num'] / $den) : null;
            }
            ksort($final[$crop]);
        }
        ksort($final);

        return $final;
    }

    private static function buildMergedCustomDatasetSwatSeries(
        array $datasetCtx,
        array $swatSeriesByRun
    ): array {
        $effectiveRunMap = is_array($datasetCtx['effective_run_map'] ?? null)
            ? $datasetCtx['effective_run_map']
            : [];

        if (!$effectiveRunMap) {
            return [];
        }

        $indicatorCodes = [];
        foreach (($datasetCtx['source_run_ids'] ?? []) as $rid) {
            foreach (array_keys($swatSeriesByRun[(int)$rid] ?? []) as $code) {
                $indicatorCodes[$code] = true;
            }
        }

        $merged = [];

        foreach (array_keys($indicatorCodes) as $code) {
            $meta = SwatIndicatorRegistry::meta((string)$code);
            $grain = (string)($meta['grain'] ?? 'sub');

            if ($grain === 'sub') {
                $mergedBySub = [];

                foreach ($effectiveRunMap as $sub => $sourceRunId) {
                    $sub = (int)$sub;
                    $sourceRunId = (int)$sourceRunId;
                    if ($sub <= 0 || $sourceRunId <= 0) continue;

                    $runBlock = $swatSeriesByRun[$sourceRunId][$code] ?? null;
                    if (!is_array($runBlock)) continue;

                    if (isset($runBlock['by_sub'][$sub]) && is_array($runBlock['by_sub'][$sub])) {
                        $mergedBySub[$sub] = $runBlock['by_sub'][$sub];
                    }
                }

                $subWeights = [];

// derive sub weights from any available source run crop areas
                foreach ($effectiveRunMap as $sub => $sourceRunId) {
                    $sub = (int)$sub;
                    $sourceRunId = (int)$sourceRunId;
                    if ($sub <= 0 || $sourceRunId <= 0) continue;

                    $runBlock = $swatSeriesByRun[$sourceRunId]['crop_yield_t_ha'] ?? null;
                    $cropAreas = is_array($runBlock['sub_crop_area_ha'][$sub] ?? null) ? $runBlock['sub_crop_area_ha'][$sub] : [];

                    $sum = 0.0;
                    foreach ($cropAreas as $crop => $ha) {
                        if (!is_numeric($ha) || (float)$ha <= 0) continue;
                        $sum += (float)$ha;
                    }
                    if ($sum > 0) {
                        $subWeights[$sub] = $sum;
                    }
                }

                $overallNum = [];
                $overallDen = [];

                foreach ($mergedBySub as $sub => $yearMap) {
                    $w = (float)($subWeights[(int)$sub] ?? 0.0);
                    if ($w <= 0) continue;

                    foreach (($yearMap ?? []) as $year => $val) {
                        if ($val === null || !is_numeric($val)) continue;
                        $year = (int)$year;
                        $overallNum[$year] = ($overallNum[$year] ?? 0.0) + ((float)$val * $w);
                        $overallDen[$year] = ($overallDen[$year] ?? 0.0) + $w;
                    }
                }

                $overall = [];
                foreach ($overallNum as $year => $num) {
                    $den = (float)($overallDen[$year] ?? 0.0);
                    $overall[(int)$year] = $den > 0 ? ($num / $den) : null;
                }

                ksort($mergedBySub);
                foreach ($mergedBySub as $sub => $yearMap) {
                    ksort($mergedBySub[$sub]);
                }
                ksort($overall);

                $merged[$code] = [
                    'grain' => 'sub',
                    'overall' => $overall,
                    'by_sub' => $mergedBySub,
                ];
                continue;
            }

            // grain = sub_crop
            $mergedBySub = [];
            $mergedSubCropAreaHa = [];

            foreach ($effectiveRunMap as $sub => $sourceRunId) {
                $sub = (int)$sub;
                $sourceRunId = (int)$sourceRunId;
                if ($sub <= 0 || $sourceRunId <= 0) continue;

                $runBlock = $swatSeriesByRun[$sourceRunId][$code] ?? null;
                if (!is_array($runBlock)) continue;

                if (isset($runBlock['by_sub'][$sub]) && is_array($runBlock['by_sub'][$sub])) {
                    $mergedBySub[$sub] = $runBlock['by_sub'][$sub];
                }

                if (isset($runBlock['sub_crop_area_ha'][$sub]) && is_array($runBlock['sub_crop_area_ha'][$sub])) {
                    $mergedSubCropAreaHa[$sub] = $runBlock['sub_crop_area_ha'][$sub];
                }
            }

            ksort($mergedBySub);
            ksort($mergedSubCropAreaHa);

            $merged[$code] = [
                'grain' => 'sub_crop',
                'overall' => self::buildOverallFromMergedBySub($mergedBySub, $mergedSubCropAreaHa),
                'by_sub' => $mergedBySub,
                'sub_crop_area_ha' => $mergedSubCropAreaHa,
            ];
        }

        return $merged;
    }

    /**
     * Derive crop weights from sub_crop_area_ha: [sub][crop] => ha
     * Returns weights that sum to ~1 across crops.
     *
     * @return array{weights: array<string,float>, warning:?string}
     */
    private static function cropWeightsFromSubCropArea(array $subCropAreaHa, ?string $cropFilter = null): array
    {
        $areaByCrop = [];

        foreach ($subCropAreaHa as $sub => $crops) {
            if (!is_array($crops)) continue;
            foreach ($crops as $crop => $ha) {
                $crop = (string)$crop;
                if ($cropFilter !== null && $crop !== $cropFilter) continue;
                if (!is_numeric($ha) || (float)$ha <= 0) continue;
                $areaByCrop[$crop] = ($areaByCrop[$crop] ?? 0.0) + (float)$ha;
            }
        }

        if (!$areaByCrop) {
            return ['weights' => [], 'warning' => 'No crop areas found; falling back to equal weights.'];
        }

        $sum = array_sum($areaByCrop);
        if ($sum <= 0) {
            return ['weights' => [], 'warning' => 'Invalid crop area total; falling back to equal weights.'];
        }

        $weights = [];
        foreach ($areaByCrop as $crop => $ha) {
            $weights[$crop] = $ha / $sum;
        }

        ksort($weights);

        return ['weights' => $weights, 'warning' => null];
    }

    // -------------------------
    // Helpers to read UI payload
    // -------------------------

    private static function indexVarsByKey(array $rows): array
    {
        $m = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $k = isset($r['key']) ? (string)$r['key'] : '';
            if ($k !== '') $m[$k] = $r;
        }
        return $m;
    }

    private static function getNumFromVarIdx(array $idx, string $key): ?float
    {
        // $idx is ['key' => row]
        return self::getNumFromVarRow($idx[$key] ?? null);
    }

    private static function getNumFromVarRow(?array $row): ?float
    {
        if (!$row) return null;
        $t = (string)($row['data_type'] ?? 'number');
        if ($t === 'number') {
            $v = $row['value_num'] ?? null;
            return is_numeric($v) ? (float)$v : null;
        }
        if ($t === 'bool') {
            $v = $row['value_bool'] ?? null;
            return is_bool($v) ? ($v ? 1.0 : 0.0) : (is_numeric($v) ? (float)$v : null);
        }
        // text -> numeric if possible
        $v = $row['value_text'] ?? null;
        return is_numeric($v) ? (float)$v : null;
    }

    private static function indexCropVars(array $cropVars): array
    {
        // [crop_code][key] => row
        $m = [];
        foreach ($cropVars as $r) {
            if (!is_array($r)) continue;
            $c = (string)($r['crop_code'] ?? '');
            $k = (string)($r['key'] ?? '');
            if ($c === '' || $k === '') continue;
            $m[$c][$k] = $r;
        }
        return $m;
    }

    private static function getCropVarNum(array $cropVarsIdx, string $crop, string $key): ?float
    {
        return self::getNumFromVarRow($cropVarsIdx[$crop][$key] ?? null);
    }

    private static function indexFactors(array $rows): array
    {
        // [crop_code][key] => value_num
        $m = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $c = (string)($r['crop_code'] ?? '');
            $k = (string)($r['key'] ?? '');
            if ($c === '' || $k === '') continue;
            $v = $r['value_num'] ?? null;
            $m[$c][$k] = is_numeric($v) ? (float)$v : null;
        }
        return $m;
    }

    private static function sumFactorKeys(array $factorIdx, string $crop, array $keys): float
    {
        $sum = 0.0;
        foreach ($keys as $k) {
            $v = $factorIdx[$crop][$k] ?? null;
            if ($v !== null && is_numeric($v)) $sum += (float)$v;
        }
        return $sum;
    }

    private static function discountFactor(float $ratePct, int $yearIndex): float
    {
        // ratePct 5 means 5%
        $r = $ratePct / 100.0;
        if ($yearIndex <= 0) return 1.0;
        return 1.0 / pow(1.0 + $r, $yearIndex);
    }

    // -------------------------
// Generic helpers
// -------------------------

    private static function mean(array $vals): ?float
    {
        $sum = 0.0; $n = 0;
        foreach ($vals as $v) {
            if ($v === null) continue;
            if (!is_numeric($v)) continue;
            $sum += (float)$v;
            $n++;
        }
        return $n > 0 ? ($sum / $n) : null;
    }

    private static function seriesYearsUnion(array ...$seriesList): array
    {
        $years = [];
        foreach ($seriesList as $s) {
            foreach (array_keys($s) as $y) $years[(int)$y] = true;
        }
        $out = array_keys($years);
        sort($out);
        return $out;
    }

    /**
     * Build crop weights.
     * Prefer SWAT 'crop_area_ha' if you have it later, else equal weights.
     *
     * @param array $yieldPerCropYear [crop][year] => val
     * @return array{weights: array<string,float>, warning: ?string}
     */
    private static function cropWeightsFromAvailableData(array $yieldPerCropYear): array
    {
        $crops = array_keys($yieldPerCropYear);
        if (!$crops) return ['weights' => [], 'warning' => 'No crops available for weights.'];

        // TODO later: if you add swatSeries 'crop_area_ha', use it here.
        // For now: equal weights
        $w = 1.0 / max(1, count($crops));
        $weights = [];
        foreach ($crops as $c) $weights[(string)$c] = $w;

        return [
            'weights' => $weights,
            'warning' => 'Using equal crop weights (no crop area weights available yet). Consider adding crop_area_ha from HRU areas.',
        ];
    }

    /**
     * Area-weighted aggregate across crops for a given year.
     * @param array $perCropYear [crop][year] => value
     * @param array $weights [crop] => weight (sum ~ 1)
     */
    private static function weightedYearValue(array $perCropYear, array $weights, int $year): ?float
    {
        $num = 0.0;
        $den = 0.0;
        foreach ($weights as $crop => $w) {
            $v = $perCropYear[$crop][$year] ?? null;
            if ($v === null || !is_numeric($v)) continue;
            $ww = (float)$w;
            if ($ww <= 0) continue;
            $num += ((float)$v) * $ww;
            $den += $ww;
        }
        return $den > 0 ? ($num / $den) : null;
    }

    // -------------------------
    // BCR computation
    // -------------------------

    /**
     * Build overall BCR time series from per-crop BCR series.
     *
     * Strategy:
     * - For each year, sum (numerator) and sum (denominator) across crops.
     * - Then overall_bcr_year = sum_num / sum_den.
     *
     * Optionally applies crop weights (defaults to equal weights).
     *
     * @param array $byCrop  from results['bcr']['by_dataset'][rid]['by_crop']
     * @param array<string,float> $cropWeights
     * @return array{series: array<int,?float>, raw: ?float, debug: array}
     */
    private static function buildBcrOverallFromByCrop(array $byCrop, array $cropWeights = []): array
    {
        // crop => (year => bcr), and crop => (year => [num,den])
        $years = [];

        // default weights: equal
        if (!$cropWeights) {
            $crops = array_keys($byCrop);
            $w = (count($crops) > 0) ? (1.0 / count($crops)) : 0.0;
            foreach ($crops as $c) $cropWeights[(string)$c] = $w;
        }

        $byCropSeries = []; // [crop][year] => bcr
        $numByYear = [];    // [year] => float
        $denByYear = [];    // [year] => float

        foreach ($byCrop as $crop => $blk) {
            $crop = (string)$crop;
            $w = (float)($cropWeights[$crop] ?? 0.0);
            if ($w <= 0) continue;

            $seriesRows = $blk['series'] ?? [];
            if (!is_array($seriesRows)) continue;

            foreach ($seriesRows as $row) {
                if (!is_array($row)) continue;
                $year = isset($row['year']) ? (int)$row['year'] : 0;
                if ($year <= 0) continue;

                $years[$year] = true;

                $num = $row['numerator'] ?? null;
                $den = $row['denominator'] ?? null;

                $numF = (is_numeric($num) ? (float)$num : null);
                $denF = (is_numeric($den) ? (float)$den : null);
                $bcrF = $row['bcr'] ?? null;

                if (!isset($byCropSeries[$crop])) $byCropSeries[$crop] = [];
                $byCropSeries[$crop][$year] = is_numeric($bcrF) ? (float)$bcrF : null;

                if ($numF !== null) $numByYear[$year] = ($numByYear[$year] ?? 0.0) + ($numF * $w);
                if ($denF !== null) $denByYear[$year] = ($denByYear[$year] ?? 0.0) + ($denF * $w);
            }
        }

        $years = array_keys($years);
        sort($years);

        $overallSeries = []; // [year] => bcr
        foreach ($years as $y) {
            $n = $numByYear[$y] ?? null;
            $d = $denByYear[$y] ?? null;
            if ($n === null || $d === null || abs((float)$d) < 1e-12) {
                $overallSeries[$y] = null;
            } else {
                $overallSeries[$y] = ((float)$n) / ((float)$d);
            }
        }

        $sumN = 0.0; $sumD = 0.0;
        foreach ($years as $y) {
            $n = $numByYear[$y] ?? null;
            $d = $denByYear[$y] ?? null;
            if ($n === null || $d === null || abs((float)$d) < 1e-12) continue;
            $sumN += (float)$n;
            $sumD += (float)$d;
        }
        $raw = (abs($sumD) > 1e-12) ? ($sumN / $sumD) : null;

        return [
            'series' => $overallSeries,
            'raw' => $raw,
            'debug' => [
                'by_crop_series' => $byCropSeries,
                'crop_weights' => $cropWeights,
            ],
        ];
    }

    private const LABOUR_KEYS = [
        'bmp_labour_land_preparation_pd_ha',
        'bmp_labour_planting_pd_ha',
        'bmp_labour_fertilizer_application_pd_ha',
        'bmp_labour_weeding_pd_ha',
        'bmp_labour_pest_control_pd_ha',
        'bmp_labour_irrigation_pd_ha',
        'bmp_labour_harvesting_pd_ha',
        'bmp_labour_other_pd_ha',
    ];

    private const MATERIAL_KEYS = [
        'bmp_material_seeds_usd_ha',
        'bmp_material_mineral_fertilisers_usd_ha',
        'bmp_material_organic_amendments_usd_ha',
        'bmp_material_pesticides_usd_ha',
        'bmp_material_tractor_usage_usd_ha',
        'bmp_material_equipment_usage_usd_ha',
        'bmp_material_other_usd_ha',
    ];

    /**
     * Compute BCR series and overall for a single run and crop.
     *
     * @return array{series:array, overall:array, warnings:array}
     */
    private static function computeBcrForRunCrop(array $args): array
    {
        $runId          = (string)$args['run_id'];
        $baselineRunId  = (int)$args['baseline_run_id'];
        $crop           = (string)$args['crop_code'];

        $cropVarsIdx    = $args['crop_vars_idx'];      // [crop][key] => row
        $baselineFactorsIdx = $args['baseline_factors_idx']; // [crop][key] => num|null
        $runFactorsIdx  = $args['run_factors_idx'];    // [crop][key] => num|null

        $runVarIdx    = is_array($args['run_var_idx'] ?? null) ? $args['run_var_idx'] : [];
        $globalVarIdx = is_array($args['global_var_idx'] ?? null) ? $args['global_var_idx'] : [];

        $warnings = [];

        $swatSeriesByDataset = $args['swat_series_by_dataset'];
        $swatSeriesByRun     = $args['swat_series_by_run'];

        $yScn = $swatSeriesByDataset[$runId]['crop_yield_t_ha']['overall'][$crop] ?? [];
        $yRef = $swatSeriesByRun[$baselineRunId]['crop_yield_t_ha']['overall'][$crop] ?? [];

        if (!$yScn || !$yRef) {
            return [
                'series' => [],
                'overall' => ['numerator_sum' => null, 'denominator_sum' => null, 'bcr' => null],
                'warnings' => ["Missing crop_yield_t_ha series for crop {$crop} (run {$runId} or baseline {$baselineRunId})."],
            ];
        }

        // Crop price
        $price = self::getCropVarNum($cropVarsIdx, $crop, 'crop_price_usd_per_t');
        if ($price === null) {
            $warnings[] = "Missing crop_price_usd_per_t for crop {$crop}.";
        }

        // Discount / life / costs (run-level)
        $discountPct = self::getNumFromVarIdx($runVarIdx, 'discount_rate');
        if ($discountPct === null) $discountPct = 0.0;

        $life = (int)round(self::getNumFromVarIdx($runVarIdx, 'economic_life_years') ?? 0);
        if ($life <= 0) $life = 1;

        $invest = self::getNumFromVarIdx($runVarIdx, 'bmp_invest_cost_usd_ha') ?? 0.0;
        $om     = self::getNumFromVarIdx($runVarIdx, 'bmp_annual_om_cost_usd_ha') ?? 0.0;

        $labDayCost = self::getNumFromVarIdx($globalVarIdx, 'labour_day_cost_usd_per_pd')
            ?? self::getNumFromVarIdx($globalVarIdx, 'labour_cost_usd_per_day')
            ?? self::getNumFromVarIdx($globalVarIdx, 'labour_day_cost_usd')
            ?? 0.0;

        if (
            self::getNumFromVarIdx($globalVarIdx, 'labour_day_cost_usd_per_pd') === null &&
            self::getNumFromVarIdx($globalVarIdx, 'labour_cost_usd_per_day') === null &&
            self::getNumFromVarIdx($globalVarIdx, 'labour_day_cost_usd') === null
        ) {
            $warnings[] = "Missing labour day cost (expected global var labour_day_cost_usd_per_pd, labour_cost_usd_per_day or labour_day_cost_usd). Using 0.";
        }

        // Baseline costs per ha for crop
        $baseMaterial = self::sumFactorKeys($baselineFactorsIdx, $crop, self::MATERIAL_KEYS);
        $basePdSum    = self::sumFactorKeys($baselineFactorsIdx, $crop, self::LABOUR_KEYS);
        $baseLabour   = $labDayCost * $basePdSum;
        $baseProdCost = $baseMaterial + $baseLabour;

        // BMP costs per ha for crop (run-specific)
        $bmpMaterial = self::sumFactorKeys($runFactorsIdx, $crop, self::MATERIAL_KEYS);
        $bmpPdSum    = self::sumFactorKeys($runFactorsIdx, $crop, self::LABOUR_KEYS);
        $bmpLabour   = $labDayCost * $bmpPdSum;
        $bmpProdCost = $bmpMaterial + $bmpLabour;

        // Year axis: union (may include years missing one side)
        $years = array_values(array_unique(array_merge(array_keys($yScn), array_keys($yRef))));
        sort($years);

        if (!$years) {
            return [
                'series' => [],
                'overall' => ['numerator_sum' => null, 'denominator_sum' => null, 'bcr' => null],
                'warnings' => ["No years available for crop {$crop}."],
            ];
        }

        $y0 = (int)$years[0];

        $numSum = 0.0;
        $denSum = 0.0;
        $series = [];

        foreach ($years as $year) {
            $year = (int)$year;
            $i = $year - $y0; // index starting at 0

            $ys = isset($yScn[$year]) ? (is_numeric($yScn[$year]) ? (float)$yScn[$year] : null) : null;
            $yr = isset($yRef[$year]) ? (is_numeric($yRef[$year]) ? (float)$yRef[$year] : null) : null;

            if ($ys === null || $yr === null || $price === null) {
                $series[] = [
                    'year' => $year,
                    'discount_factor' => self::discountFactor((float)$discountPct, $i),
                    'benefit_raw' => null,
                    'cost_raw' => null,
                    'numerator' => null,
                    'denominator' => null,
                    'bcr' => null,
                ];
                continue;
            }

            // Benefit: (yield_scn - yield_base) * price
            $benefitRaw = ($ys - $yr) * $price;

            // Cost delta per ha: (bmpProd - baseProd) + invest_if_due + om
            $investThisYear = ($i % $life === 0) ? $invest : 0.0;
            $costRaw = ($bmpProdCost - $baseProdCost) + $investThisYear + $om;

            // Discount
            $df = self::discountFactor((float)$discountPct, $i);
            $num = $benefitRaw * $df;
            $den = $costRaw * $df;

            $bcr = (abs($den) > 0.0) ? ($num / $den) : null;

            $series[] = [
                'year' => $year,
                'discount_factor' => $df,

                'yield_scenario_t_ha' => $ys,
                'yield_baseline_t_ha' => $yr,
                'price_usd_per_t'     => $price,

                'benefit_raw' => $benefitRaw,
                'cost_raw'    => $costRaw,
                'investment_applied' => $investThisYear,

                'numerator'   => $num,
                'denominator' => $den,
                'bcr'         => $bcr,
            ];

            $numSum += $num;
            $denSum += $den;
        }

        $overallBcr = (abs($denSum) > 0.0) ? ($numSum / $denSum) : null;

        return [
            'series' => $series,
            'overall' => [
                'numerator_sum' => $numSum,
                'denominator_sum' => $denSum,
                'bcr' => $overallBcr,
            ],
            'warnings' => $warnings,
        ];
    }

    public static function compute(array $payload): array
    {
        $presetSetId = (int)($payload['preset_set_id'] ?? 0);
        if ($presetSetId <= 0) {
            throw new InvalidArgumentException('preset_set_id is required');
        }

        $ctx = self::resolvePresetContext($presetSetId);
        $baselineRunId = $ctx['baseline_run_id'];
        $studyAreaId   = $ctx['study_area_id'];

        $datasetContexts = self::resolveDatasetContexts(
            $studyAreaId,
            $payload['dataset_ids'] ?? [],
            $payload['user_id'] ?? null
        );

        $datasetIds = array_map(
            static fn(array $d): string => (string)$d['dataset_id'],
            $datasetContexts
        );

        $allSourceRunIds = [];
        foreach ($datasetContexts as $ctxItem) {
            foreach (($ctxItem['source_run_ids'] ?? []) as $rid) {
                $allSourceRunIds[(int)$rid] = true;
            }
        }
        $runIds = array_keys($allSourceRunIds);
        sort($runIds);

        // Enabled indicators from UI
        $presetItems = is_array($payload['preset_items'] ?? null) ? $payload['preset_items'] : [];

        $enabledIndicatorCodes = [];
        foreach ($presetItems as $it) {
            if (!is_array($it)) continue;
            $calcKey = (string)($it['indicator_calc_key'] ?? '');
            $en      = !empty($it['is_enabled']);
            if ($en && $calcKey !== '') {
                $enabledIndicatorCodes[$calcKey] = true;
            }
        }
        $enabledIndicatorCodes = array_keys($enabledIndicatorCodes);
        sort($enabledIndicatorCodes);

        // Split enabled codes by source type
        $directSwatCodes = [];
        $mcaCalcCodes = [];

        foreach ($enabledIndicatorCodes as $code) {
            if (self::isDirectSwatIndicator($code)) {
                $directSwatCodes[] = $code;
            } else {
                $mcaCalcCodes[] = $code;
            }
        }

        $enabledMeta = self::fetchIndicatorMeta($enabledIndicatorCodes);

        $requiredSwat = array_values(array_unique(array_merge(
            McaDependencyRegistry::requiredSwatIndicatorsForMca($mcaCalcCodes),
            $directSwatCodes
        )));
        sort($requiredSwat);

        $cropFilter = null;
        if (isset($payload['crop_code']) && $payload['crop_code'] !== '') {
            $cropFilter = (string)$payload['crop_code'];
        }

        // include baseline in fetch
        $fetchRunIds = $runIds;
        if (!in_array($baselineRunId, $fetchRunIds, true)) {
            $fetchRunIds[] = $baselineRunId;
            sort($fetchRunIds);
        }

        // SWAT series by source run
        $swatSeriesByRun = [];
        foreach ($fetchRunIds as $rid) {
            $swatSeriesByRun[$rid] = $requiredSwat
                ? McaSwatInputsRepository::getYearlyPerCropManyWithSub($rid, $requiredSwat, [], $cropFilter)
                : [];
        }

        // SWAT series by dataset (run or merged custom)
        $swatSeriesByDataset = [];
        foreach ($datasetContexts as $ds) {
            $swatSeriesByDataset[$ds['dataset_id']] = self::buildDatasetSwatSeries($ds, $swatSeriesByRun);
        }

        // Index crop variables (prices)
        $cropVarsIdx = self::indexCropVars($payload['crop_variables'] ?? []);

        // Index baseline reference factors
        $baselineFactorsIdx = self::indexFactors($payload['crop_ref_factors'] ?? []);

        // Index run inputs by dataset_id
        $runInputs = $payload['run_inputs'] ?? [];
        $runVarsById = [];
        $runVarIdxById = [];
        $runFactorsById = [];

        foreach ($runInputs as $ri) {
            if (!is_array($ri)) continue;

            $datasetId = trim((string)($ri['dataset_id'] ?? ''));
            if ($datasetId === '') continue;

            $vars = is_array($ri['variables_run'] ?? null) ? $ri['variables_run'] : [];
            $runVarsById[$datasetId] = $vars;
            $runVarIdxById[$datasetId] = self::indexVarsByKey($vars);

            $runFactorsById[$datasetId] = self::indexFactors(
                is_array($ri['crop_factors'] ?? null) ? $ri['crop_factors'] : []
            );
        }

        $globalVars = $payload['variables'] ?? [];
        $globalIdx = self::indexVarsByKey($globalVars);

        $viewerIndicators = McaIndicatorRegistry::listForCodes($enabledIndicatorCodes);

        $results = [];
        $warnings = [];

        $results['raw'] = ['by_dataset' => []];

        // ------------------------------------------------------------
        // Direct SWAT indicators: expose as raw MCA-capable indicators
        // ------------------------------------------------------------
        foreach ($datasetContexts as $ds) {
            $datasetId = (string)$ds['dataset_id'];

            foreach ($directSwatCodes as $code) {
                $swatBlock = $swatSeriesByDataset[$datasetId][$code] ?? null;
                if (!is_array($swatBlock)) {
                    $results['raw']['by_dataset'][$datasetId][$code] = [
                        'raw' => null,
                        'series' => [],
                    ];
                    continue;
                }

                $results['raw']['by_dataset'][$datasetId][$code] =
                    self::buildDirectSwatSeriesAndRaw($swatBlock, $code);
            }
        }

        // --------------------------------
        // BCR computation (crop-specific)
        // --------------------------------
        if (in_array('bcr', $mcaCalcCodes, true)) {
            $results['bcr'] = [
                'by_dataset' => [],
            ];

            foreach ($datasetContexts as $ds) {
                $datasetId = (string)$ds['dataset_id'];

                $runVarIdx = $runVarIdxById[$datasetId] ?? [];
                $runFactorsIdx = $runFactorsById[$datasetId] ?? [];

                $cropSet = [];
                $y = $swatSeriesByDataset[$datasetId]['crop_yield_t_ha']['overall'] ?? [];
                foreach (array_keys($y) as $crop) {
                    $cropSet[(string)$crop] = true;
                }

                if ($cropFilter !== null) {
                    $cropSet = [$cropFilter => true];
                }

                $byCrop = [];
                foreach (array_keys($cropSet) as $crop) {
                    $out = self::computeBcrForRunCrop([
                        'run_id' => $datasetId,
                        'baseline_run_id' => $baselineRunId,
                        'crop_code' => $crop,
                        'swat_series_by_dataset' => $swatSeriesByDataset,
                        'swat_series_by_run' => $swatSeriesByRun,
                        'crop_vars_idx' => $cropVarsIdx,
                        'baseline_factors_idx' => $baselineFactorsIdx,
                        'run_factors_idx' => $runFactorsIdx,
                        'run_var_idx'    => $runVarIdx,
                        'global_var_idx' => $globalIdx,
                    ]);

                    $byCrop[$crop] = $out;
                    foreach ($out['warnings'] as $w) {
                        $warnings[] = "dataset {$datasetId}, crop {$crop}: {$w}";
                    }
                }

                $subCropAreaHa = $swatSeriesByDataset[$datasetId]['crop_yield_t_ha']['sub_crop_area_ha'] ?? [];
                $wInfo = self::cropWeightsFromSubCropArea($subCropAreaHa, $cropFilter);
                $cropWeights = $wInfo['weights'];

                if (!$cropWeights) {
                    $yieldPerCrop = $swatSeriesByDataset[$datasetId]['crop_yield_t_ha']['overall'] ?? [];
                    if ($cropFilter !== null) {
                        $yieldPerCrop = isset($yieldPerCrop[$cropFilter]) ? [$cropFilter => $yieldPerCrop[$cropFilter]] : [];
                    }
                    $wInfo2 = self::cropWeightsFromAvailableData($yieldPerCrop);
                    $cropWeights = $wInfo2['weights'];
                    if ($wInfo2['warning']) {
                        $warnings[] = "dataset {$datasetId}: {$wInfo2['warning']}";
                    }
                } elseif ($wInfo['warning']) {
                    $warnings[] = "dataset {$datasetId}: {$wInfo['warning']}";
                }

                ksort($byCrop);

                $bcrAgg = self::buildBcrOverallFromByCrop($byCrop, $cropWeights);

                $results['bcr']['by_dataset'][$datasetId]['by_crop'] = $byCrop;
                $results['bcr']['by_dataset'][$datasetId]['overall'] = [
                    'raw' => $bcrAgg['raw'],
                    'series' => $bcrAgg['series'],
                    'debug' => $bcrAgg['debug'],
                ];

                $results['raw']['by_dataset'][$datasetId]['bcr'] = [
                    'raw' => $bcrAgg['raw'],
                    'series' => $bcrAgg['series'],
                    'debug' => $bcrAgg['debug'],
                ];
            }
        }

        // --------------------------------------------------------
        // Other computed MCA indicators (single number + time series)
        // --------------------------------------------------------
        foreach ($datasetContexts as $ds) {
            $datasetId = (string)$ds['dataset_id'];

            $runVarIdx = $runVarIdxById[$datasetId] ?? [];
            $runFactorsIdx = $runFactorsById[$datasetId] ?? [];

            $yieldPerCrop = $swatSeriesByDataset[$datasetId]['crop_yield_t_ha']['overall'] ?? [];
            if ($cropFilter !== null) {
                $yieldPerCrop = isset($yieldPerCrop[$cropFilter]) ? [$cropFilter => $yieldPerCrop[$cropFilter]] : [];
            }

            $subCropAreaHa = $swatSeriesByDataset[$datasetId]['crop_yield_t_ha']['sub_crop_area_ha'] ?? [];
            $wInfo = self::cropWeightsFromSubCropArea($subCropAreaHa, $cropFilter);

            $cropWeights = $wInfo['weights'];
            if (!$cropWeights) {
                $wInfo2 = self::cropWeightsFromAvailableData($yieldPerCrop);
                $cropWeights = $wInfo2['weights'];
                if ($wInfo2['warning']) {
                    $warnings[] = "dataset {$datasetId}: {$wInfo2['warning']}";
                }
            } else {
                if ($wInfo['warning']) {
                    $warnings[] = "dataset {$datasetId}: {$wInfo['warning']}";
                }
            }

            $life   = (int)round(self::getNumFromVarIdx($runVarIdx, 'economic_life_years') ?? 0);
            if ($life <= 0) $life = 1;

            $invest = self::getNumFromVarIdx($runVarIdx, 'bmp_invest_cost_usd_ha') ?? 0.0;
            $om     = self::getNumFromVarIdx($runVarIdx, 'bmp_annual_om_cost_usd_ha') ?? 0.0;

            $farmSizeHa  = self::getNumFromVarIdx($globalIdx, 'farm_size_ha');
            $landRent    = self::getNumFromVarIdx($globalIdx, 'land_rent_usd_ha_yr')
                ?? self::getNumFromVarIdx($globalIdx, 'land_rent_usd_ha')
                ?? 0.0;

            $waterFeeHa  = self::getNumFromVarIdx($runVarIdx, 'water_use_fee_usd_ha')
                ?? self::getNumFromVarIdx($globalIdx, 'water_use_fee_usd_ha')
                ?? 0.0;

            $waterCostM3 = self::getNumFromVarIdx($runVarIdx, 'water_cost_usd_m3')
                ?? self::getNumFromVarIdx($globalIdx, 'water_cost_usd_m3')
                ?? 0.0;

            $labDayCost  = self::getNumFromVarIdx($globalIdx, 'labour_day_cost_usd_per_pd')
                ?? self::getNumFromVarIdx($globalIdx, 'labour_cost_usd_per_day')
                ?? self::getNumFromVarIdx($globalIdx, 'labour_day_cost_usd')
                ?? 0.0;

            $baseProdCostByCrop = [];
            $bmpProdCostByCrop  = [];
            foreach (array_keys($yieldPerCrop) as $crop) {
                $crop = (string)$crop;

                $baseMaterial = self::sumFactorKeys($baselineFactorsIdx, $crop, self::MATERIAL_KEYS);
                $basePdSum    = self::sumFactorKeys($baselineFactorsIdx, $crop, self::LABOUR_KEYS);
                $baseLabour   = $labDayCost * $basePdSum;
                $baseProdCostByCrop[$crop] = $baseMaterial + $baseLabour;

                $bmpMaterial = self::sumFactorKeys($runFactorsIdx, $crop, self::MATERIAL_KEYS);
                $bmpPdSum    = self::sumFactorKeys($runFactorsIdx, $crop, self::LABOUR_KEYS);
                $bmpLabour   = $labDayCost * $bmpPdSum;
                $bmpProdCostByCrop[$crop] = $bmpMaterial + $bmpLabour;
            }

            $years = [];
            foreach ($yieldPerCrop as $c => $ys) {
                foreach (array_keys($ys ?? []) as $y) {
                    $years[(int)$y] = true;
                }
            }
            $years = array_keys($years);
            sort($years);

            if (in_array('price_cost_ratio', $mcaCalcCodes, true)) {
                $series = [];
                foreach ($years as $year) {
                    $revTotal = 0.0;
                    $costTotal = 0.0;
                    $have = false;

                    foreach ($cropWeights as $crop => $w) {
                        $yld = $yieldPerCrop[$crop][$year] ?? null;
                        $price = self::getCropVarNum($cropVarsIdx, $crop, 'crop_price_usd_per_t');
                        if ($yld === null || $price === null) continue;

                        $have = true;
                        $revTotal += ((float)$yld) * ((float)$price) * ((float)$w);

                        $prod = $bmpProdCostByCrop[$crop] ?? 0.0;
                        $annualInvest = $invest / $life;
                        $costTotal += ($prod + $annualInvest + $om) * ((float)$w);
                    }

                    $series[$year] = ($have && abs($costTotal) > 0.0) ? ($revTotal / $costTotal) : null;
                }

                $overall = self::mean(array_values($series));
                $results['raw']['by_dataset'][$datasetId]['price_cost_ratio'] = [
                    'raw' => $overall,
                    'series' => $series,
                ];
            }

            if (in_array('cost_saving_usd', $mcaCalcCodes, true)) {
                $series = [];
                foreach ($years as $year) {
                    $baseTotal = 0.0;
                    $bmpTotal = 0.0;
                    $have = false;

                    foreach ($cropWeights as $crop => $w) {
                        $have = true;
                        $baseProd = $baseProdCostByCrop[$crop] ?? 0.0;
                        $bmpProd  = $bmpProdCostByCrop[$crop] ?? 0.0;

                        $annualInvest = $invest / $life;
                        $baseTotal += $baseProd * (float)$w;
                        $bmpTotal  += ($bmpProd + $annualInvest + $om) * (float)$w;
                    }
                    $series[$year] = $have ? ($baseTotal - $bmpTotal) : null;
                }

                $overall = self::mean(array_values($series));
                $results['raw']['by_dataset'][$datasetId]['cost_saving_usd'] = [
                    'raw' => $overall,
                    'series' => $series,
                ];
            }

            if (in_array('net_farm_income_usd_ha', $mcaCalcCodes, true)) {
                $irrPerCrop = $swatSeriesByDataset[$datasetId]['irr_mm']['overall'] ?? [];

                $seriesPerHa = [];
                $seriesHousehold = [];

                $y0 = $years ? (int)$years[0] : 0;

                foreach ($years as $year) {
                    $i = $year - $y0;
                    $investThisYear = ($i % $life === 0) ? $invest : 0.0;

                    $revTotal = 0.0;
                    $costTotal = 0.0;
                    $have = false;

                    foreach ($cropWeights as $crop => $w) {
                        $yld = $yieldPerCrop[$crop][$year] ?? null;
                        $price = self::getCropVarNum($cropVarsIdx, $crop, 'crop_price_usd_per_t');
                        if ($yld !== null && $price !== null) {
                            $revTotal += ((float)$yld) * ((float)$price) * (float)$w;
                            $have = true;
                        }

                        $prod = $bmpProdCostByCrop[$crop] ?? 0.0;

                        $irrMm = $irrPerCrop[$crop][$year] ?? null;
                        $irrM3Ha = (is_numeric($irrMm) ? ((float)$irrMm * 10.0) : 0.0);
                        $waterCostHa = $waterCostM3 * $irrM3Ha;

                        $costTotal += ($prod + $investThisYear + $om + $landRent + $waterFeeHa + $waterCostHa) * (float)$w;
                    }

                    $netPerHa = $have ? ($revTotal - $costTotal) : null;
                    $seriesPerHa[$year] = $netPerHa;

                    if ($netPerHa !== null && $farmSizeHa !== null) {
                        $seriesHousehold[$year] = $netPerHa * (float)$farmSizeHa;
                    } else {
                        $seriesHousehold[$year] = null;
                    }
                }

                $overallPerHa = self::mean(array_values($seriesPerHa));

                $results['raw']['by_dataset'][$datasetId]['net_farm_income_usd_ha'] = [
                    'raw' => $overallPerHa,
                    'series' => $seriesPerHa,
                    'debug' => [
                        'household_series_usd' => $seriesHousehold,
                        'farm_size_ha' => $farmSizeHa,
                    ],
                ];
            }

            if (in_array('income_increase_pct', $mcaCalcCodes, true)) {
                $irrPerCrop = $swatSeriesByDataset[$datasetId]['irr_mm']['overall'] ?? [];
                $yieldRefPerCrop = $swatSeriesByRun[$baselineRunId]['crop_yield_t_ha']['overall'] ?? [];

                $series = [];
                foreach ($years as $year) {
                    $revAfter = 0.0;
                    $costAfter = 0.0;
                    $revBefore = 0.0;
                    $costBefore = 0.0;
                    $have = false;

                    foreach ($cropWeights as $crop => $w) {
                        $price = self::getCropVarNum($cropVarsIdx, $crop, 'crop_price_usd_per_t');
                        if ($price === null) continue;

                        $yAfter = $yieldPerCrop[$crop][$year] ?? null;
                        $yBefore = $yieldRefPerCrop[$crop][$year] ?? null;

                        if ($yAfter !== null && $yBefore !== null) $have = true;

                        if ($yAfter !== null)  $revAfter  += ((float)$yAfter)  * (float)$price * (float)$w;
                        if ($yBefore !== null) $revBefore += ((float)$yBefore) * (float)$price * (float)$w;

                        $prodAfter  = $bmpProdCostByCrop[$crop] ?? 0.0;
                        $prodBefore = $baseProdCostByCrop[$crop] ?? 0.0;

                        $irrMm = $irrPerCrop[$crop][$year] ?? null;
                        $irrM3Ha = (is_numeric($irrMm) ? ((float)$irrMm * 10.0) : 0.0);
                        $waterCostHa = $waterCostM3 * $irrM3Ha;

                        $costAfter  += ($prodAfter  + $om + $landRent + $waterFeeHa + $waterCostHa) * (float)$w;
                        $costBefore += ($prodBefore + $landRent + $waterFeeHa + $waterCostHa) * (float)$w;
                    }

                    if (!$have) {
                        $series[$year] = null;
                        continue;
                    }

                    $after = $revAfter - $costAfter;
                    $before = $revBefore - $costBefore;

                    $series[$year] = (abs($before) > 0.0) ? (($after - $before) / $before) * 100.0 : null;
                }

                $overall = self::mean(array_values($series));
                $results['raw']['by_dataset'][$datasetId]['income_increase_pct'] = [
                    'raw' => $overall,
                    'series' => $series,
                ];
            }

            if (in_array('labour_use', $mcaCalcCodes, true)) {
                $pdSeries = [];
                foreach ($years as $year) {
                    $tot = 0.0;
                    $have = false;

                    foreach ($cropWeights as $crop => $w) {
                        $have = true;
                        $pd = self::sumFactorKeys($runFactorsIdx, $crop, self::LABOUR_KEYS);
                        $tot += $pd * (float)$w;
                    }

                    $pdSeries[$year] = $have ? $tot : null;
                }

                $overall = self::mean(array_values($pdSeries));
                $results['raw']['by_dataset'][$datasetId]['labour_use'] = [
                    'raw' => $overall,
                    'series' => $pdSeries,
                ];
            }

            $irrPerCrop = $swatSeriesByDataset[$datasetId]['irr_mm']['overall'] ?? [];

            if (in_array('water_use_intensity', $mcaCalcCodes, true)) {
                $series = [];
                foreach ($years as $year) {
                    $v = self::weightedYearValue($irrPerCrop, $cropWeights, (int)$year);
                    $series[$year] = ($v === null) ? null : ($v * 10.0);
                }

                $results['raw']['by_dataset'][$datasetId]['water_use_intensity'] = [
                    'raw' => self::mean(array_values($series)),
                    'series' => $series,
                ];
            }

            if (in_array('water_tech_eff', $mcaCalcCodes, true)) {
                $series = [];
                foreach ($years as $year) {
                    $yAgg = self::weightedYearValue($yieldPerCrop, $cropWeights, (int)$year);
                    $irrAgg = self::weightedYearValue($irrPerCrop, $cropWeights, (int)$year);

                    if ($yAgg === null || $irrAgg === null) {
                        $series[$year] = null;
                        continue;
                    }

                    $m3ha = $irrAgg * 10.0;
                    $series[$year] = (abs($m3ha) > 0.0) ? (($yAgg * 1000.0) / $m3ha) : null;
                }

                $results['raw']['by_dataset'][$datasetId]['water_tech_eff'] = [
                    'raw' => self::mean(array_values($series)),
                    'series' => $series,
                ];
            }

            if (in_array('water_econ_eff', $mcaCalcCodes, true)) {
                $series = [];
                foreach ($years as $year) {
                    $revAgg = 0.0;
                    $wSum = 0.0;

                    foreach ($cropWeights as $crop => $w) {
                        $yld = $yieldPerCrop[$crop][$year] ?? null;
                        $price = self::getCropVarNum($cropVarsIdx, $crop, 'crop_price_usd_per_t');
                        if ($yld === null || $price === null) continue;

                        $revAgg += ((float)$yld) * (float)$price * (float)$w;
                        $wSum += (float)$w;
                    }

                    $irrAgg = self::weightedYearValue($irrPerCrop, $cropWeights, (int)$year);
                    if ($wSum <= 0 || $irrAgg === null) {
                        $series[$year] = null;
                        continue;
                    }

                    $m3ha = $irrAgg * 10.0;
                    $series[$year] = (abs($m3ha) > 0.0) ? ($revAgg / $m3ha) : null;
                }

                $results['raw']['by_dataset'][$datasetId]['water_econ_eff'] = [
                    'raw' => self::mean(array_values($series)),
                    'series' => $series,
                ];
            }

            if (in_array('carbon_sequestration', $mcaCalcCodes, true)) {
                $cPerCrop = $swatSeriesByDataset[$datasetId]['crop_c_seq_t_ha']['overall'] ?? [];
                $series = [];
                foreach ($years as $year) {
                    $series[$year] = self::weightedYearValue($cPerCrop, $cropWeights, (int)$year);
                }

                $results['raw']['by_dataset'][$datasetId]['carbon_sequestration'] = [
                    'raw' => self::mean(array_values($series)),
                    'series' => $series,
                ];
            }

            if (in_array('fertiliser_use_eff_n', $mcaCalcCodes, true)) {
                $nue = $swatSeriesByDataset[$datasetId]['nue_n_pct']['overall'] ?? [];
                $series = [];
                foreach ($years as $year) {
                    $series[$year] = self::weightedYearValue($nue, $cropWeights, (int)$year);
                }

                $results['raw']['by_dataset'][$datasetId]['fertiliser_use_eff_n'] = [
                    'raw' => self::mean(array_values($series)),
                    'series' => $series,
                ];
            }

            if (in_array('fertiliser_use_eff_p', $mcaCalcCodes, true)) {
                $pue = $swatSeriesByDataset[$datasetId]['nue_p_pct']['overall'] ?? [];
                $series = [];
                foreach ($years as $year) {
                    $series[$year] = self::weightedYearValue($pue, $cropWeights, (int)$year);
                }

                $results['raw']['by_dataset'][$datasetId]['fertiliser_use_eff_p'] = [
                    'raw' => self::mean(array_values($series)),
                    'series' => $series,
                ];
            }

            if (in_array('water_rights_access', $mcaCalcCodes, true)) {
                $farmSizeHa = self::getNumFromVarIdx($globalIdx, 'farm_size_ha');

                if ($farmSizeHa === null || $farmSizeHa <= 0) {
                    $warnings[] = "dataset {$datasetId}: Missing or invalid global var farm_size_ha; cannot compute water_rights_access.";
                    $results['raw']['by_dataset'][$datasetId]['water_rights_access'] = [
                        'raw' => null,
                        'series' => [],
                    ];
                } elseif (($ds['dataset_type'] ?? 'run') === 'custom') {
                    $results['raw']['by_dataset'][$datasetId]['water_rights_access'] =
                        self::buildMergedCustomDatasetWaterRightsSeries($ds, (float)$farmSizeHa);
                } else {
                    $sourceRunId = (int)($ds['source_run_ids'][0] ?? 0);
                    $w = McaWaterRightsRepository::getIrrigatedAreaHaMonthlyAndYearlyAvg($sourceRunId, []);

                    $seriesYearly = [];
                    foreach (($w['yearly_avg_monthly_irrigated_area_ha'] ?? []) as $year => $irrHaAvg) {
                        $seriesYearly[(int)$year] = ((float)$irrHaAvg) / (float)$farmSizeHa;
                    }

                    $seriesYearlyBySub = [];
                    foreach (($w['yearly_avg_monthly_irrigated_area_ha_by_sub'] ?? []) as $sub => $yearMap) {
                        foreach (($yearMap ?? []) as $year => $irrHaAvg) {
                            $seriesYearlyBySub[(int)$sub][(int)$year] = ((float)$irrHaAvg) / (float)$farmSizeHa;
                        }
                    }

                    $results['raw']['by_dataset'][$datasetId]['water_rights_access'] = [
                        'raw' => self::mean(array_values($seriesYearly)),
                        'series' => $seriesYearly,
                        'debug' => [
                            'farm_size_ha' => $farmSizeHa,
                            'yearly_avg_monthly_irrigated_area_ha' => $w['yearly_avg_monthly_irrigated_area_ha'] ?? [],
                            'monthly_total_irrigated_area_ha' => $w['monthly_total_irrigated_area_ha'] ?? [],
                            'yearly_avg_monthly_irrigated_area_ha_by_sub' => $w['yearly_avg_monthly_irrigated_area_ha_by_sub'] ?? [],
                            'monthly_irrigated_area_ha_by_sub' => $w['monthly_irrigated_area_ha_by_sub'] ?? [],
                            'yearly_irrigated_farms_by_sub' => $seriesYearlyBySub,
                        ],
                    ];
                }
            }
        }

        // Build spatial results once, not only when BCR is enabled
        $results['raw_spatial'] = McaSpatialResultsBuilder::build([
            'dataset_contexts'       => $datasetContexts,
            'enabled_codes'          => $enabledIndicatorCodes,
            'swat_series_by_dataset' => $swatSeriesByDataset,
            'swat_series_by_run'     => $swatSeriesByRun,
            'crop_vars_idx'          => $cropVarsIdx,
            'baseline_factors_idx'   => $baselineFactorsIdx,
            'run_var_idx_by_id'      => $runVarIdxById,
            'run_factors_by_id'      => $runFactorsById,
            'global_idx'             => $globalIdx,
            'baseline_run_id'        => $baselineRunId,
            'crop_filter'            => $cropFilter,
        ]);

        // ------------------------------------------------------------
        // Normalize across datasets + totals
        // ------------------------------------------------------------
        $dirByCode = [];
        $wByCode   = [];

        $totalW = 0.0;
        foreach ($presetItems as $it) {
            if (!is_array($it)) continue;

            $code = (string)($it['indicator_calc_key'] ?? '');
            if ($code === '') continue;

            $enabled = !empty($it['is_enabled']);
            $dirByCode[$code] = (($it['direction'] ?? 'pos') === 'neg') ? 'neg' : 'pos';

            $w = (isset($it['weight']) && is_numeric($it['weight'])) ? (float)$it['weight'] : 0.0;
            if ($w < 0) $w = 0.0;
            if (!$enabled) $w = 0.0;

            $wByCode[$code] = $w;
            $totalW += $w;
        }

        if ($totalW > 0) {
            foreach ($wByCode as $code => $w) {
                $wByCode[$code] = $w / $totalW;
            }
        }

        $normalized = ['by_dataset' => []];
        $weighted   = ['by_dataset' => []];
        $totals     = [];

        $normBaselineId = null;
        foreach ($datasetContexts as $ds) {
            if (($ds['dataset_type'] ?? '') === 'run' && ((int)($ds['source_run_ids'][0] ?? 0) === $baselineRunId)) {
                $normBaselineId = (string)$ds['dataset_id'];
                break;
            }
        }
        if ($normBaselineId === null) {
            $normBaselineId = $datasetIds[0] ?? null;
        }
        if (!$normBaselineId) {
            throw new RuntimeException('No runs available for normalization.');
        }

        $REL_CAP = 1.0;

        $clamp = static function(float $x, float $lo, float $hi): float {
            return max($lo, min($hi, $x));
        };

        foreach ($enabledIndicatorCodes as $code) {
            $baseRaw = $results['raw']['by_dataset'][$normBaselineId][$code]['raw'] ?? null;
            $baseRaw = is_numeric($baseRaw) ? (float)$baseRaw : null;

            $scale = null;
            $maxAbsDev = 0.0;
            $maxAbsRaw = 0.0;

            foreach ($datasetContexts as $ds) {
                $datasetId = (string)$ds['dataset_id'];
                $raw = $results['raw']['by_dataset'][$datasetId][$code]['raw'] ?? null;
                if ($raw === null || !is_numeric($raw)) continue;

                $rawF = (float)$raw;
                $maxAbsRaw = max($maxAbsRaw, abs($rawF));
                if ($baseRaw !== null) {
                    $maxAbsDev = max($maxAbsDev, abs($rawF - $baseRaw));
                }
            }

            if ($baseRaw !== null && abs($baseRaw) > 1e-12) {
                $scale = abs($baseRaw);
            } elseif ($maxAbsDev > 1e-12) {
                $scale = $maxAbsDev;
            } elseif ($maxAbsRaw > 1e-12) {
                $scale = $maxAbsRaw;
            } else {
                $scale = 1.0;
            }

            foreach ($datasetContexts as $ds) {
                $datasetId = (string)$ds['dataset_id'];
                $raw = $results['raw']['by_dataset'][$datasetId][$code]['raw'] ?? null;

                if ($raw === null || !is_numeric($raw) || $baseRaw === null) {
                    $normalized['by_dataset'][$datasetId][$code] = null;
                    $weighted['by_dataset'][$datasetId][$code] = null;
                    continue;
                }

                $rawF = (float)$raw;

                if (abs($baseRaw) > 1e-12) {
                    $delta = ($rawF - $baseRaw) / abs($baseRaw);
                } else {
                    $delta = ($rawF - $baseRaw) / $scale;
                }

                $delta = $clamp($delta, -$REL_CAP, $REL_CAP);

                $score = 0.5 + ($delta / $REL_CAP) * 0.5;

                if (($dirByCode[$code] ?? 'pos') === 'neg') {
                    $score = 1.0 - $score;
                }

                $score = $clamp($score, 0.0, 1.0);

                $normalized['by_dataset'][$datasetId][$code] = $score;

                $w = $wByCode[$code] ?? 0.0;
                $weighted['by_dataset'][$datasetId][$code] = $score * $w;
            }
        }

        foreach ($datasetContexts as $ds) {
            $datasetId = (string)$ds['dataset_id'];
            $sum = 0.0;
            $have = false;

            foreach ($enabledIndicatorCodes as $code) {
                $ws = $weighted['by_dataset'][$datasetId][$code] ?? null;
                if ($ws === null) continue;
                $have = true;
                $sum += (float)$ws;
            }

            $totals[] = [
                'dataset_id' => $datasetId,
                'total_weighted_score' => $have ? $sum : null,
            ];
        }

        // ------------------------------------------------------------
        // Time-series normalization
        // ------------------------------------------------------------
        $normalizedTs = ['by_dataset' => []];
        $weightedTs   = ['by_dataset' => []];
        $totalsTs     = ['by_dataset' => []];

        foreach ($enabledIndicatorCodes as $code) {
            $years = [];
            foreach ($datasetContexts as $ds) {
                $datasetId = (string)$ds['dataset_id'];
                $series = $results['raw']['by_dataset'][$datasetId][$code]['series'] ?? [];
                if (!is_array($series)) continue;
                foreach (array_keys($series) as $y) {
                    $years[(int)$y] = true;
                }
            }
            $years = array_keys($years);
            sort($years);

            foreach ($years as $y) {
                $vals = [];
                foreach ($datasetContexts as $ds) {
                    $datasetId = (string)$ds['dataset_id'];
                    $v = $results['raw']['by_dataset'][$datasetId][$code]['series'][$y] ?? null;
                    if ($v !== null && is_numeric($v)) {
                        $vals[] = (float)$v;
                    }
                }

                if (!$vals) {
                    foreach ($datasetContexts as $ds) {
                        $datasetId = (string)$ds['dataset_id'];
                        $normalizedTs['by_dataset'][$datasetId][$code][$y] = null;
                        $weightedTs['by_dataset'][$datasetId][$code][$y] = null;
                    }
                    continue;
                }

                $min = min($vals);
                $max = max($vals);

                foreach ($datasetContexts as $ds) {
                    $datasetId = (string)$ds['dataset_id'];
                    $v = $results['raw']['by_dataset'][$datasetId][$code]['series'][$y] ?? null;

                    if ($v === null || !is_numeric($v)) {
                        $score = null;
                    } elseif (abs($max - $min) < 1e-12) {
                        $score = 0.5;
                    } else {
                        $t = (((float)$v) - $min) / ($max - $min);
                        $score = (($dirByCode[$code] ?? 'pos') === 'neg') ? (1.0 - $t) : $t;
                        $score = max(0.0, min(1.0, $score));
                    }

                    $normalizedTs['by_dataset'][$datasetId][$code][$y] = $score;
                    $w = $wByCode[$code] ?? 0.0;
                    $weightedTs['by_dataset'][$datasetId][$code][$y] = ($score === null) ? null : ($score * $w);
                }
            }
        }

        foreach ($datasetContexts as $ds) {
            $datasetId = (string)$ds['dataset_id'];
            $years = [];

            foreach ($enabledIndicatorCodes as $code) {
                foreach (array_keys($weightedTs['by_dataset'][$datasetId][$code] ?? []) as $y) {
                    $years[(int)$y] = true;
                }
            }

            $years = array_keys($years);
            sort($years);

            foreach ($years as $y) {
                $sum = 0.0;
                $have = false;

                foreach ($enabledIndicatorCodes as $code) {
                    $ws = $weightedTs['by_dataset'][$datasetId][$code][$y] ?? null;
                    if ($ws === null) continue;
                    $sum += (float)$ws;
                    $have = true;
                }

                $totalsTs['by_dataset'][$datasetId][$y] = $have ? $sum : null;
            }
        }

        $results['normalized']    = $normalized;
        $results['weighted']      = $weighted;
        $results['totals']        = $totals;
        $results['normalized_ts'] = $normalizedTs;
        $results['weighted_ts']   = $weightedTs;
        $results['totals_ts']     = $totalsTs;

        return [
            'ok' => true,

            'preset_set_id'    => $presetSetId,
            'study_area_id'    => $studyAreaId,
            'baseline_run_id'  => $baselineRunId,

            'dataset_ids'      => $datasetIds,
            'run_ids'          => $runIds,

            'enabled_mca'      => $enabledIndicatorCodes,
            'enabled_mca_meta' => $enabledMeta,
            'swat_required'    => $requiredSwat,
            'swat_series'      => $swatSeriesByRun,

            'viewer_indicators' => $viewerIndicators,

            'results'          => $results,
            'totals'           => $results['totals'] ?? [],
            'warnings'         => $warnings,

            'ui' => [
                'variables'           => $globalVars,
                'crop_variables'      => $payload['crop_variables'] ?? [],
                'crop_ref_factors'    => $payload['crop_ref_factors'] ?? [],
                'preset_items'        => $presetItems,
                'crop_code'           => $cropFilter,
            ],
        ];
    }

    private static function loadIrrigationAreaContextByRun(int $runId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("
        SELECT year, month, sub, irrigated_area_ha
        FROM swat_irrigation_area_context
        WHERE run_id = :run_id
        ORDER BY year, month, sub
    ");
        $stmt->execute([':run_id' => $runId]);

        $out = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $year = (int)($r['year'] ?? 0);
            $month = (int)($r['month'] ?? 0);
            $sub = (int)($r['sub'] ?? 0);
            $ha = isset($r['irrigated_area_ha']) && is_numeric($r['irrigated_area_ha'])
                ? (float)$r['irrigated_area_ha']
                : 0.0;

            if ($year <= 0 || $month < 1 || $month > 12 || $sub <= 0) {
                continue;
            }

            $out[$sub][$year][$month] = $ha;
        }

        ksort($out);
        foreach ($out as $sub => $years) {
            ksort($out[$sub]);
            foreach ($out[$sub] as $year => $months) {
                ksort($out[$sub][$year]);
            }
        }

        return $out;
    }

    private static function buildMergedCustomDatasetWaterRightsSeries(
        array $datasetCtx,
        float $farmSizeHa
    ): array {
        $effectiveRunMap = is_array($datasetCtx['effective_run_map'] ?? null)
            ? $datasetCtx['effective_run_map']
            : [];

        if (!$effectiveRunMap || $farmSizeHa <= 0) {
            return [
                'raw' => null,
                'series' => [],
                'debug' => [
                    'farm_size_ha' => $farmSizeHa,
                    'yearly_avg_monthly_irrigated_area_ha' => [],
                    'monthly_total_irrigated_area_ha' => [],
                    'yearly_avg_monthly_irrigated_area_ha_by_sub' => [],
                    'monthly_irrigated_area_ha_by_sub' => [],
                    'yearly_irrigated_farms_by_sub' => [],
                ],
            ];
        }

        $monthlyBySub = [];

        $cacheByRun = [];
        foreach (array_values(array_unique(array_map('intval', array_values($effectiveRunMap)))) as $runId) {
            if ($runId > 0) {
                $cacheByRun[$runId] = self::loadIrrigationAreaContextByRun($runId);
            }
        }

        foreach ($effectiveRunMap as $sub => $sourceRunId) {
            $sub = (int)$sub;
            $sourceRunId = (int)$sourceRunId;
            if ($sub <= 0 || $sourceRunId <= 0) {
                continue;
            }

            $runData = $cacheByRun[$sourceRunId][$sub] ?? null;
            if (!is_array($runData)) {
                continue;
            }

            foreach ($runData as $year => $months) {
                foreach ($months as $month => $ha) {
                    $monthKey = sprintf('%04d-%02d-01', (int)$year, (int)$month);
                    $monthlyBySub[$sub][$monthKey] = (float)$ha;
                }
            }
        }

        $monthlyTotal = [];
        $monthsByYear = [];
        foreach ($monthlyBySub as $sub => $series) {
            foreach ($series as $monthKey => $ha) {
                $monthlyTotal[$monthKey] = ($monthlyTotal[$monthKey] ?? 0.0) + (float)$ha;
                $year = (int)substr($monthKey, 0, 4);
                $monthsByYear[$year][$monthKey] = true;
            }
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

        $seriesYearly = [];
        foreach ($yearlyTotal as $year => $irrHaAvg) {
            $seriesYearly[(int)$year] = ((float)$irrHaAvg) / $farmSizeHa;
        }

        $seriesYearlyBySub = [];
        foreach ($yearlyBySub as $sub => $yearMap) {
            foreach ($yearMap as $year => $irrHaAvg) {
                $seriesYearlyBySub[(int)$sub][(int)$year] = ((float)$irrHaAvg) / $farmSizeHa;
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
        ksort($seriesYearly);
        ksort($seriesYearlyBySub);
        foreach ($seriesYearlyBySub as $sub => $s) {
            ksort($seriesYearlyBySub[$sub]);
        }

        return [
            'raw' => self::mean(array_values($seriesYearly)),
            'series' => $seriesYearly,
            'debug' => [
                'farm_size_ha' => $farmSizeHa,
                'yearly_avg_monthly_irrigated_area_ha' => $yearlyTotal,
                'monthly_total_irrigated_area_ha' => $monthlyTotal,
                'yearly_avg_monthly_irrigated_area_ha_by_sub' => $yearlyBySub,
                'monthly_irrigated_area_ha_by_sub' => $monthlyBySub,
                'yearly_irrigated_farms_by_sub' => $seriesYearlyBySub,
            ],
        ];
    }

    private static function isDirectSwatIndicator(string $code): bool
    {
        try {
            SwatIndicatorRegistry::meta($code);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function cropWeightsFromOverallBlock(array $overallByCrop, array $subCropAreaHa): array
    {
        $areaByCrop = [];

        foreach ($subCropAreaHa as $sub => $crops) {
            if (!is_array($crops)) continue;
            foreach ($crops as $crop => $ha) {
                if (!is_numeric($ha) || (float)$ha <= 0) continue;
                $areaByCrop[(string)$crop] = ($areaByCrop[(string)$crop] ?? 0.0) + (float)$ha;
            }
        }

        if ($areaByCrop) {
            $sum = array_sum($areaByCrop);
            if ($sum > 0) {
                $weights = [];
                foreach ($areaByCrop as $crop => $ha) {
                    $weights[(string)$crop] = (float)$ha / $sum;
                }
                ksort($weights);
                return $weights;
            }
        }

        // fallback: equal weights for crops present in overall block
        $crops = array_keys($overallByCrop);
        if (!$crops) return [];

        $w = 1.0 / count($crops);
        $weights = [];
        foreach ($crops as $crop) {
            $weights[(string)$crop] = $w;
        }
        ksort($weights);

        return $weights;
    }

    private static function buildDirectSwatSeriesFromSubCropOverall(array $overallByCrop, array $subCropAreaHa): array
    {
        $weights = self::cropWeightsFromOverallBlock($overallByCrop, $subCropAreaHa);

        $years = [];
        foreach ($overallByCrop as $crop => $yearMap) {
            foreach (array_keys($yearMap ?? []) as $year) {
                $years[(int)$year] = true;
            }
        }
        $years = array_keys($years);
        sort($years);

        $series = [];
        foreach ($years as $year) {
            $num = 0.0;
            $den = 0.0;

            foreach ($weights as $crop => $w) {
                $v = $overallByCrop[$crop][$year] ?? null;
                if ($v === null || !is_numeric($v)) continue;
                $num += (float)$v * (float)$w;
                $den += (float)$w;
            }

            $series[(int)$year] = $den > 0 ? ($num / $den) : null;
        }

        ksort($series);
        return $series;
    }

    private static function buildDirectSwatSeriesFromSub(array $bySub, array $subWeights = []): array
    {
        $num = [];
        $den = [];

        foreach ($bySub as $sub => $yearMap) {
            $w = (float)($subWeights[(int)$sub] ?? 0.0);
            if ($w <= 0) continue;

            foreach (($yearMap ?? []) as $year => $value) {
                if (!is_numeric($value)) continue;
                $year = (int)$year;
                $num[$year] = ($num[$year] ?? 0.0) + ((float)$value * $w);
                $den[$year] = ($den[$year] ?? 0.0) + $w;
            }
        }

        $series = [];
        foreach ($num as $year => $n) {
            $d = (float)($den[$year] ?? 0.0);
            $series[(int)$year] = $d > 0 ? ($n / $d) : null;
        }

        ksort($series);
        return $series;
    }

    private static function buildDirectSwatSeriesAndRaw(array $swatBlock, string $indicatorCode): array
    {
        $meta = SwatIndicatorRegistry::meta($indicatorCode);
        $grain = (string)($meta['grain'] ?? 'sub');

        if ($grain === 'sub_crop') {
            $overallByCrop = is_array($swatBlock['overall'] ?? null) ? $swatBlock['overall'] : [];
            $subCropAreaHa = is_array($swatBlock['sub_crop_area_ha'] ?? null) ? $swatBlock['sub_crop_area_ha'] : [];

            $series = self::buildDirectSwatSeriesFromSubCropOverall($overallByCrop, $subCropAreaHa);

            return [
                'raw' => self::mean(array_values($series)),
                'series' => $series,
            ];
        }

        $bySub = is_array($swatBlock['by_sub'] ?? null) ? $swatBlock['by_sub'] : [];
        $subWeights = is_array($swatBlock['sub_weights'] ?? null) ? $swatBlock['sub_weights'] : [];
        $series = self::buildDirectSwatSeriesFromSub($bySub, $subWeights);

        return [
            'raw' => self::mean(array_values($series)),
            'series' => $series,
        ];
    }
}