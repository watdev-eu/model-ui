<?php
// src/classes/McaComputeService.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/SwatRunRepository.php';
require_once __DIR__ . '/McaDependencyRegistry.php';
require_once __DIR__ . '/McaSwatInputsRepository.php';
require_once __DIR__ . '/McaWaterRightsRepository.php';

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

        $pdo = Database::pdo();
        $ph = implode(',', array_fill(0, count($calcKeys), '?'));

        $stmt = $pdo->prepare("
            SELECT calc_key, code, name, unit
            FROM mca_indicators
            WHERE calc_key IN ($ph)
        ");
        $stmt->execute($calcKeys);

        $out = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ck = (string)($r['calc_key'] ?? '');
            if ($ck === '') continue;

            $out[$ck] = [
                'calc_key' => $ck,
                'code'     => (string)($r['code'] ?? ''),   // SDG-style
                'name'     => (string)($r['name'] ?? $ck),
                'unit'     => $r['unit'] ?? null,
            ];
        }

        // keep enabled order (in calc_key order)
        $ordered = [];
        foreach ($calcKeys as $ck) {
            $ordered[] = $out[$ck] ?? ['calc_key'=>$ck,'code'=>null,'name'=>$ck,'unit'=>null];
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
     * @param array $byCrop  from results['bcr']['by_run'][rid]['by_crop']
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
        $runId          = (int)$args['run_id'];
        $baselineRunId  = (int)$args['baseline_run_id'];
        $crop           = (string)$args['crop_code'];

        $swatSeries     = $args['swat_series_by_run']; // [runId][swatCode][crop][year] => val

        $cropVarsIdx    = $args['crop_vars_idx'];      // [crop][key] => row
        $baselineFactorsIdx = $args['baseline_factors_idx']; // [crop][key] => num|null
        $runFactorsIdx  = $args['run_factors_idx'];    // [crop][key] => num|null

        $runVarIdx    = is_array($args['run_var_idx'] ?? null) ? $args['run_var_idx'] : [];
        $globalVarIdx = is_array($args['global_var_idx'] ?? null) ? $args['global_var_idx'] : [];

        $warnings = [];

        // Required: yield series
        $yScn = $swatSeries[$runId]['crop_yield_t_ha']['overall'][$crop] ?? [];
        $yRef = $swatSeries[$baselineRunId]['crop_yield_t_ha']['overall'][$crop] ?? [];

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

        $labDayCost = self::getNumFromVarIdx($globalVarIdx, 'labour_day_cost_usd_per_pd');

        if ($labDayCost === null) {
            $labDayCost = 0.0;
            $warnings[] = "Missing labour day cost (expected global var labour_cost_usd_per_day or labour_day_cost_usd). Using 0.";
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
        if ($presetSetId <= 0) throw new InvalidArgumentException('preset_set_id is required');

        $ctx = self::resolvePresetContext($presetSetId);
        $baselineRunId = $ctx['baseline_run_id'];
        $studyAreaId   = $ctx['study_area_id'];

        $runIds = self::validateRunIds($studyAreaId, $payload['run_ids'] ?? []);

        // enabled MCA
        $presetItems = $payload['preset_items'] ?? [];
        $enabledMcaCodes = [];
        foreach ($presetItems as $it) {
            if (!is_array($it)) continue;
            $calcKey = (string)($it['indicator_calc_key'] ?? '');
            $en      = !empty($it['is_enabled']);
            if ($en && $calcKey !== '') $enabledMcaCodes[$calcKey] = true;
        }
        $enabledMcaCodes = array_keys($enabledMcaCodes);
        sort($enabledMcaCodes);

        $enabledMeta = self::fetchIndicatorMeta($enabledMcaCodes);

        // dependencies
        $requiredSwat = McaDependencyRegistry::requiredSwatIndicatorsForMca($enabledMcaCodes);

        // Always include yield if anything is enabled (axis + crop weights depend on it)
        if (!empty($enabledMcaCodes) && !in_array('crop_yield_t_ha', $requiredSwat, true)) {
            $requiredSwat[] = 'crop_yield_t_ha';
        }

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

        // SWAT series
        $swatSeriesByRun = [];
        foreach ($fetchRunIds as $rid) {
            $swatSeriesByRun[$rid] = ($requiredSwat)
                ? McaSwatInputsRepository::getYearlyPerCropManyWithSub($rid, $requiredSwat, [], $cropFilter)
                : [];
        }

        // Index crop variables (prices)
        $cropVarsIdx = self::indexCropVars($payload['crop_variables'] ?? []);

        // Index baseline ref factors (crop_ref_factors_json)
        $baselineFactorsIdx = self::indexFactors($payload['crop_ref_factors'] ?? []);

        // Index run inputs (variables_run + crop_factors) by run_id
        $runInputs = $payload['run_inputs'] ?? [];
        $runVarsById = [];
        $runVarIdxById = [];
        $runFactorsById = [];
        foreach ($runInputs as $ri) {
            if (!is_array($ri)) continue;
            $rid = (int)($ri['run_id'] ?? 0);
            if ($rid <= 0) continue;

            $vars = is_array($ri['variables_run'] ?? null) ? $ri['variables_run'] : [];
            $runVarsById[$rid] = $vars;
            $runVarIdxById[$rid] = self::indexVarsByKey($vars);

            $runFactorsById[$rid] = self::indexFactors(
                is_array($ri['crop_factors'] ?? null) ? $ri['crop_factors'] : []
            );
        }

        $globalVars = $payload['variables'] ?? [];
        $globalIdx = self::indexVarsByKey($globalVars);

        // ---- Results: BCR (per run, per crop) ----
        $results = [];
        $warnings = [];

        $results['raw'] = ['by_run' => []];

        if (in_array('bcr', $enabledMcaCodes, true)) {
            $results['bcr'] = [
                'by_run' => [],
            ];

            foreach ($runIds as $rid) {
                $runVarIdx = $runVarIdxById[$rid] ?? [];
                $runFactorsIdx = $runFactorsById[$rid] ?? [];

                // Determine which crops to compute:
                // Prefer crops present in scenario yield series (or filtered crop)
                $cropSet = [];
                $y = $swatSeriesByRun[$rid]['crop_yield_t_ha']['overall'] ?? [];
                foreach (array_keys($y) as $crop) $cropSet[(string)$crop] = true;

                // If cropFilter provided, restrict
                if ($cropFilter !== null) {
                    $cropSet = [$cropFilter => true];
                }

                $byCrop = [];
                foreach (array_keys($cropSet) as $crop) {
                    $out = self::computeBcrForRunCrop([
                        'run_id' => $rid,
                        'baseline_run_id' => $baselineRunId,
                        'crop_code' => $crop,
                        'swat_series_by_run' => $swatSeriesByRun,
                        'crop_vars_idx' => $cropVarsIdx,
                        'baseline_factors_idx' => $baselineFactorsIdx,
                        'run_factors_idx' => $runFactorsIdx,
                        'run_var_idx'    => $runVarIdx,
                        'global_var_idx' => $globalIdx,
                    ]);

                    $byCrop[$crop] = $out;
                    foreach ($out['warnings'] as $w) $warnings[] = "run {$rid}, crop {$crop}: {$w}";
                }

                // crop weights for this run (so overall BCR matches other indicators)
                $subCropAreaHa = $swatSeriesByRun[$rid]['crop_yield_t_ha']['sub_crop_area_ha'] ?? [];
                $wInfo = self::cropWeightsFromSubCropArea($subCropAreaHa, $cropFilter);
                $cropWeights = $wInfo['weights'];

                if (!$cropWeights) {
                    // fallback to equal weights based on available crops
                    $yieldPerCrop = $swatSeriesByRun[$rid]['crop_yield_t_ha']['overall'] ?? [];
                    if ($cropFilter !== null) {
                        $yieldPerCrop = isset($yieldPerCrop[$cropFilter]) ? [$cropFilter => $yieldPerCrop[$cropFilter]] : [];
                    }
                    $wInfo2 = self::cropWeightsFromAvailableData($yieldPerCrop);
                    $cropWeights = $wInfo2['weights'];
                    if ($wInfo2['warning']) $warnings[] = "run {$rid}: {$wInfo2['warning']}";
                } elseif ($wInfo['warning']) {
                    $warnings[] = "run {$rid}: {$wInfo['warning']}";
                }

                ksort($byCrop);
                // Build overall BCR raw + time series (exposed in results.raw as well)
                $bcrAgg = self::buildBcrOverallFromByCrop($byCrop, $cropWeights);
                $results['bcr']['by_run'][$rid]['by_crop'] = $byCrop;

                $results['bcr']['by_run'][$rid]['overall'] = [
                    'raw' => $bcrAgg['raw'],
                    'series' => $bcrAgg['series'],
                    'debug' => $bcrAgg['debug'],
                ];

                // Also expose it in the standard raw slot so UI can plot without special casing
                $results['raw']['by_run'][$rid]['bcr'] = [
                    'raw' => $bcrAgg['raw'],
                    'series' => $bcrAgg['series'],
                    'debug' => $bcrAgg['debug'],
                ];
            }
        }

        // ---- Results: Other MCA indicators (single number per run) ----
        foreach ($runIds as $rid) {
            $runVarIdx    = $runVarIdxById[$rid] ?? [];
            $runFactorsIdx = $runFactorsById[$rid] ?? [];

            // Available crops for this run
            $yieldPerCrop = $swatSeriesByRun[$rid]['crop_yield_t_ha']['overall'] ?? [];
            if ($cropFilter !== null) {
                $yieldPerCrop = isset($yieldPerCrop[$cropFilter]) ? [$cropFilter => $yieldPerCrop[$cropFilter]] : [];
            }

            // Build weights (equal for now, until we implement crop areas)
            $subCropAreaHa = $swatSeriesByRun[$rid]['crop_yield_t_ha']['sub_crop_area_ha'] ?? [];
            $wInfo = self::cropWeightsFromSubCropArea($subCropAreaHa, $cropFilter);

            $cropWeights = $wInfo['weights'];
            if (!$cropWeights) {
                // fallback
                $wInfo2 = self::cropWeightsFromAvailableData($yieldPerCrop);
                $cropWeights = $wInfo2['weights'];
                if ($wInfo2['warning']) $warnings[] = "run {$rid}: {$wInfo2['warning']}";
            } else {
                if ($wInfo['warning']) $warnings[] = "run {$rid}: {$wInfo['warning']}";
            }

            // Common run-level vars (use pre-indexed runVarIdx)
            $life   = (int)round(self::getNumFromVarIdx($runVarIdx, 'economic_life_years') ?? 0);
            if ($life <= 0) $life = 1;

            $invest = self::getNumFromVarIdx($runVarIdx, 'bmp_invest_cost_usd_ha') ?? 0.0;
            $om     = self::getNumFromVarIdx($runVarIdx, 'bmp_annual_om_cost_usd_ha') ?? 0.0;

            // Common global vars
            $farmSizeHa  = self::getNumFromVarIdx($globalIdx, 'farm_size_ha');
            $landRent    = self::getNumFromVarIdx($globalIdx, 'land_rent_usd_ha_yr')
                ?? self::getNumFromVarIdx($globalIdx, 'land_rent_usd_ha')
                ?? 0.0;

            // Water fee / cost: run overrides global
            $waterFeeHa  = self::getNumFromVarIdx($runVarIdx, 'water_use_fee_usd_ha')
                ?? self::getNumFromVarIdx($globalIdx, 'water_use_fee_usd_ha')
                ?? 0.0;

            $waterCostM3 = self::getNumFromVarIdx($runVarIdx, 'water_cost_usd_m3')
                ?? self::getNumFromVarIdx($globalIdx, 'water_cost_usd_m3')
                ?? 0.0;

            // Labour day cost per person-day (global)
            $labDayCost  = self::getNumFromVarIdx($globalIdx, 'labour_cost_usd_per_day')
                ?? self::getNumFromVarIdx($globalIdx, 'labour_day_cost_usd')
                ?? 0.0;

            // Production cost functions per crop (same as BCR, but reusable here)
            $baseProdCostByCrop = []; // USD/ha
            $bmpProdCostByCrop  = []; // USD/ha
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

            // Year axis: union of all crop yield years (scenario)
            $years = [];
            foreach ($yieldPerCrop as $c => $ys) {
                foreach (array_keys($ys ?? []) as $y) $years[(int)$y] = true;
            }
            $years = array_keys($years);
            sort($years);

            // ---- price_cost_ratio (annualized invest, ratio of totals) ----
            if (in_array('price_cost_ratio', $enabledMcaCodes, true)) {
                $series = [];
                foreach ($years as $year) {
                    $revTotal = 0.0; $costTotal = 0.0; $have = false;

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
                $results['raw']['by_run'][$rid]['price_cost_ratio'] = [
                    'raw' => $overall,
                    'series' => $series,
                ];
            }

            // ---- cost_saving_usd (per ha; annualized invest) ----
            if (in_array('cost_saving_usd', $enabledMcaCodes, true)) {
                // No SWAT dependency; just costs.
                // Baseline: production cost baseline (per ha) from baseline factors
                // BMP: production cost bmp + annualized invest + om
                $series = [];
                foreach ($years as $year) {
                    $baseTotal = 0.0; $bmpTotal = 0.0; $have = false;
                    foreach ($cropWeights as $crop => $w) {
                        // if crop exists, include it even without yield in that year
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
                $results['raw']['by_run'][$rid]['cost_saving_usd'] = [
                    'raw' => $overall,
                    'series' => $series,
                ];
            }

            // ---- net_farm_income_usd_ha ----
            if (in_array('net_farm_income_usd_ha', $enabledMcaCodes, true)) {
                // Household net farm income:
                // (yield * price) - (prod_cost + invest + maintenance + land_rent + water_fee + water_cost(volume)) * farm_size
                // Investment: only in investment years (0, life, 2*life, ...)
                // NOTE: Your indicator unit in mca_indicators is USD/ha, but the formula includes * farm size.
                // We implement BOTH and report per_ha and household_total in debug. Raw uses per_ha to match your unit.
                $irrPerCrop = $swatSeriesByRun[$rid]['irr_mm']['overall'] ?? [];

                $seriesPerHa = [];
                $seriesHousehold = [];

                // use earliest year as t=0 for investment scheduling
                $y0 = $years ? (int)$years[0] : 0;

                foreach ($years as $year) {
                    $i = $year - $y0;
                    $investThisYear = ($i % $life === 0) ? $invest : 0.0;

                    $revTotal = 0.0; $costTotal = 0.0; $have = false;

                    foreach ($cropWeights as $crop => $w) {
                        $yld = $yieldPerCrop[$crop][$year] ?? null;
                        $price = self::getCropVarNum($cropVarsIdx, $crop, 'crop_price_usd_per_t');
                        if ($yld !== null && $price !== null) {
                            $revTotal += ((float)$yld) * ((float)$price) * (float)$w;
                            $have = true;
                        }

                        $prod = $bmpProdCostByCrop[$crop] ?? 0.0;

                        // optional irrigation volume cost (irr_mm * 10 = m3/ha)
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

                $results['raw']['by_run'][$rid]['net_farm_income_usd_ha'] = [
                    'raw' => $overallPerHa,
                    'series' => $seriesPerHa,
                    'debug' => [
                        'household_series_usd' => $seriesHousehold,
                        'farm_size_ha' => $farmSizeHa,
                    ],
                ];
            }

            // ---- income_increase_pct ----
            if (in_array('income_increase_pct', $enabledMcaCodes, true)) {
                // Use: (after - before) / before * 100
                // before: baseline net farm income (computed like above but REF costs and REF yield)
                // after: scenario net farm income (computed like above but BMP costs/yields)
                $irrPerCrop = $swatSeriesByRun[$rid]['irr_mm']['overall'] ?? [];
                $yieldRefPerCrop = $swatSeriesByRun[$baselineRunId]['crop_yield_t_ha']['overall'] ?? [];

                $series = [];
                foreach ($years as $year) {
                    $revAfter = 0.0; $costAfter = 0.0;
                    $revBefore = 0.0; $costBefore = 0.0;
                    $have = false;

                    foreach ($cropWeights as $crop => $w) {
                        $price = self::getCropVarNum($cropVarsIdx, $crop, 'crop_price_usd_per_t');
                        if ($price === null) continue;

                        $yAfter = $yieldPerCrop[$crop][$year] ?? null;
                        $yBefore = $yieldRefPerCrop[$crop][$year] ?? null;

                        if ($yAfter !== null && $yBefore !== null) $have = true;

                        if ($yAfter !== null)  $revAfter  += ((float)$yAfter)  * (float)$price * (float)$w;
                        if ($yBefore !== null) $revBefore += ((float)$yBefore) * (float)$price * (float)$w;

                        // Costs per ha
                        $prodAfter  = $bmpProdCostByCrop[$crop] ?? 0.0;
                        $prodBefore = $baseProdCostByCrop[$crop] ?? 0.0;

                        $irrMm = $irrPerCrop[$crop][$year] ?? null;
                        $irrM3Ha = (is_numeric($irrMm) ? ((float)$irrMm * 10.0) : 0.0);
                        $waterCostHa = $waterCostM3 * $irrM3Ha;

                        $costAfter  += ($prodAfter  + $om + $landRent + $waterFeeHa + $waterCostHa) * (float)$w;
                        $costBefore += ($prodBefore + $landRent + $waterFeeHa + $waterCostHa) * (float)$w;
                    }

                    if (!$have) { $series[$year] = null; continue; }

                    $after = $revAfter - $costAfter;
                    $before = $revBefore - $costBefore;

                    $series[$year] = (abs($before) > 0.0) ? (($after - $before) / $before) * 100.0 : null;
                }

                $overall = self::mean(array_values($series));
                $results['raw']['by_run'][$rid]['income_increase_pct'] = [
                    'raw' => $overall,
                    'series' => $series,
                ];
            }

            // ---- labour_use (person-days/ha) ----
            if (in_array('labour_use', $enabledMcaCodes, true)) {
                // labour use = sum of labour person-days/ha factors (scenario factors)
                $pdSeries = [];
                foreach ($years as $year) {
                    $tot = 0.0; $have = false;
                    foreach ($cropWeights as $crop => $w) {
                        $have = true;
                        $pd = self::sumFactorKeys($runFactorsIdx, $crop, self::LABOUR_KEYS); // person-days/ha
                        $tot += $pd * (float)$w;
                    }
                    $pdSeries[$year] = $have ? $tot : null;
                }
                $overall = self::mean(array_values($pdSeries));
                $results['raw']['by_run'][$rid]['labour_use'] = [
                    'raw' => $overall,
                    'series' => $pdSeries,
                ];
            }

            // ---- Water indicators ----
            $irrPerCrop = $swatSeriesByRun[$rid]['irr_mm']['overall'] ?? [];

            if (in_array('water_use_intensity', $enabledMcaCodes, true)) {
                // m3/ha = irr_mm * 10
                $series = [];
                foreach ($years as $year) {
                    $v = self::weightedYearValue($irrPerCrop, $cropWeights, (int)$year);
                    $series[$year] = ($v === null) ? null : ($v * 10.0);
                }
                $results['raw']['by_run'][$rid]['water_use_intensity'] = [
                    'raw' => self::mean(array_values($series)),
                    'series' => $series,
                ];
            }

            if (in_array('water_tech_eff', $enabledMcaCodes, true)) {
                // (kg/ha) / (m3/ha)
                $series = [];
                foreach ($years as $year) {
                    // aggregate yield and irr separately then ratio
                    $yAgg = self::weightedYearValue($yieldPerCrop, $cropWeights, (int)$year);
                    $irrAgg = self::weightedYearValue($irrPerCrop, $cropWeights, (int)$year);
                    if ($yAgg === null || $irrAgg === null) { $series[$year] = null; continue; }
                    $m3ha = $irrAgg * 10.0;
                    $series[$year] = (abs($m3ha) > 0.0) ? (($yAgg * 1000.0) / $m3ha) : null;
                }
                $results['raw']['by_run'][$rid]['water_tech_eff'] = [
                    'raw' => self::mean(array_values($series)),
                    'series' => $series,
                ];
            }

            if (in_array('water_econ_eff', $enabledMcaCodes, true)) {
                // (USD/ha) / (m3/ha)
                $series = [];
                foreach ($years as $year) {
                    $revAgg = 0.0; $wSum = 0.0;
                    foreach ($cropWeights as $crop => $w) {
                        $yld = $yieldPerCrop[$crop][$year] ?? null;
                        $price = self::getCropVarNum($cropVarsIdx, $crop, 'crop_price_usd_per_t');
                        if ($yld === null || $price === null) continue;
                        $revAgg += ((float)$yld) * (float)$price * (float)$w;
                        $wSum += (float)$w;
                    }
                    $irrAgg = self::weightedYearValue($irrPerCrop, $cropWeights, (int)$year);
                    if ($wSum <= 0 || $irrAgg === null) { $series[$year] = null; continue; }
                    $m3ha = $irrAgg * 10.0;
                    $series[$year] = (abs($m3ha) > 0.0) ? ($revAgg / $m3ha) : null;
                }
                $results['raw']['by_run'][$rid]['water_econ_eff'] = [
                    'raw' => self::mean(array_values($series)),
                    'series' => $series,
                ];
            }

            // ---- Carbon sequestration ----
            if (in_array('carbon_sequestration', $enabledMcaCodes, true)) {
                $cPerCrop = $swatSeriesByRun[$rid]['crop_c_seq_t_ha']['overall'] ?? [];
                $series = [];
                foreach ($years as $year) {
                    $series[$year] = self::weightedYearValue($cPerCrop, $cropWeights, (int)$year);
                }
                $results['raw']['by_run'][$rid]['carbon_sequestration'] = [
                    'raw' => self::mean(array_values($series)),
                    'series' => $series,
                ];
            }

            // ---- Fertiliser efficiencies ----
            if (in_array('fertiliser_use_eff_n', $enabledMcaCodes, true)) {
                $nue = $swatSeriesByRun[$rid]['nue_n_pct']['overall'] ?? [];
                $series = [];
                foreach ($years as $year) {
                    $series[$year] = self::weightedYearValue($nue, $cropWeights, (int)$year);
                }
                $results['raw']['by_run'][$rid]['fertiliser_use_eff_n'] = [
                    'raw' => self::mean(array_values($series)),
                    'series' => $series,
                ];
            }
            if (in_array('fertiliser_use_eff_p', $enabledMcaCodes, true)) {
                $pue = $swatSeriesByRun[$rid]['nue_p_pct']['overall'] ?? [];
                $series = [];
                foreach ($years as $year) {
                    $series[$year] = self::weightedYearValue($pue, $cropWeights, (int)$year);
                }
                $results['raw']['by_run'][$rid]['fertiliser_use_eff_p'] = [
                    'raw' => self::mean(array_values($series)),
                    'series' => $series,
                ];
            }

            if (in_array('water_rights_access', $enabledMcaCodes, true)) {
                $farmSizeHa = self::getNumFromVarIdx($globalIdx, 'farm_size_ha');

                if ($farmSizeHa === null || $farmSizeHa <= 0) {
                    $warnings[] = "run {$rid}: Missing or invalid global var farm_size_ha; cannot compute water_rights_access.";
                    $results['raw']['by_run'][$rid]['water_rights_access'] = [
                        'raw' => null,
                        'series' => [],
                    ];
                } else {
                    $w = McaWaterRightsRepository::getIrrigatedAreaHaMonthlyAndYearlyAvg($rid, []);

                    // yearly: "number of irrigated farms" = irrigated_area_ha_avg_month / farm_size_ha
                    $seriesYearly = [];
                    foreach (($w['yearly_avg_monthly_irrigated_area_ha'] ?? []) as $year => $irrHaAvg) {
                        $seriesYearly[(int)$year] = ((float)$irrHaAvg) / (float)$farmSizeHa;
                    }

                    // also provide spatial yearly (per subbasin)
                    $seriesYearlyBySub = [];
                    foreach (($w['yearly_avg_monthly_irrigated_area_ha_by_sub'] ?? []) as $sub => $yearMap) {
                        foreach (($yearMap ?? []) as $year => $irrHaAvg) {
                            $seriesYearlyBySub[(int)$sub][(int)$year] = ((float)$irrHaAvg) / (float)$farmSizeHa;
                        }
                    }

                    $results['raw']['by_run'][$rid]['water_rights_access'] = [
                        'raw' => self::mean(array_values($seriesYearly)),
                        'series' => $seriesYearly,
                        'debug' => [
                            'farm_size_ha' => $farmSizeHa,

                            // overall irrigated area (ha)
                            'yearly_avg_monthly_irrigated_area_ha' => $w['yearly_avg_monthly_irrigated_area_ha'] ?? [],
                            'monthly_total_irrigated_area_ha' => $w['monthly_total_irrigated_area_ha'] ?? [],

                            // spatial irrigated area (ha)
                            'yearly_avg_monthly_irrigated_area_ha_by_sub' => $w['yearly_avg_monthly_irrigated_area_ha_by_sub'] ?? [],
                            'monthly_irrigated_area_ha_by_sub' => $w['monthly_irrigated_area_ha_by_sub'] ?? [],

                            // spatial “irrigated farms count”
                            'yearly_irrigated_farms_by_sub' => $seriesYearlyBySub,
                        ],
                    ];
                }
            }
        }

        // -------------------------
        // Normalize across runs + totals
        // -------------------------

        // 0) Build direction map + weights (MUST be before any normalization)
        $dirByCode = [];
        $wByCode   = [];

        // collect only enabled weights, clamp negatives
        $totalW = 0.0;
        foreach (($presetItems ?? []) as $it) {
            if (!is_array($it)) continue;

            $code = (string)($it['indicator_calc_key'] ?? '');
            if ($code === '') continue;

            $enabled = !empty($it['is_enabled']);
            $dirByCode[$code] = (($it['direction'] ?? 'pos') === 'neg') ? 'neg' : 'pos';

            $w = (isset($it['weight']) && is_numeric($it['weight'])) ? (float)$it['weight'] : 0.0;
            if ($w < 0) $w = 0.0;

            // if disabled, force to 0
            if (!$enabled) $w = 0.0;

            $wByCode[$code] = $w;
            $totalW += $w;
        }

        // normalize to sum=1 if any positive
        if ($totalW > 0) {
            foreach ($wByCode as $code => $w) {
                $wByCode[$code] = $w / $totalW;
            }
        }

        // 1) Baseline-anchored scalar normalization (based on per-run RAW)
        // Baseline score = 0.5, others scale by deviation from baseline
        $normalized = ['by_run' => []]; // [runId][code] => score 0..1
        $weighted   = ['by_run' => []]; // [runId][code] => score*weight
        $totals     = [];               // [{run_id,total_weighted_score}]

        // Pick baseline id for normalization (prefer canonical baseline run if included)
        $normBaselineId = in_array($baselineRunId, $runIds, true) ? $baselineRunId : ($runIds[0] ?? null);
        if (!$normBaselineId) {
            throw new RuntimeException('No runs available for normalization.');
        }

        // Optional cap: relative change of +/-100% maps to [0..1] around 0.5
        // You can make this configurable later (e.g. global var).
        $REL_CAP = 1.0; // 1.0 = 100%

        $clamp = static function(float $x, float $lo, float $hi): float {
            return max($lo, min($hi, $x));
        };

        foreach ($enabledMcaCodes as $code) {
            // baseline raw value
            $baseRaw = $results['raw']['by_run'][$normBaselineId][$code]['raw'] ?? null;
            $baseRaw = (is_numeric($baseRaw) ? (float)$baseRaw : null);

            // Build a scale for this indicator (fallback when baseline is 0 or missing)
            // Use max abs deviation from baseline across runs, else max abs raw, else 1.
            $scale = null;
            $maxAbsDev = 0.0;
            $maxAbsRaw = 0.0;

            foreach ($runIds as $rid) {
                $raw = $results['raw']['by_run'][$rid][$code]['raw'] ?? null;
                if ($raw === null || !is_numeric($raw)) continue;
                $rawF = (float)$raw;
                $maxAbsRaw = max($maxAbsRaw, abs($rawF));
                if ($baseRaw !== null) {
                    $maxAbsDev = max($maxAbsDev, abs($rawF - $baseRaw));
                }
            }

            if ($baseRaw !== null && abs($baseRaw) > 1e-12) {
                // Prefer relative scaling against baseline magnitude
                $scale = abs($baseRaw);
            } elseif ($maxAbsDev > 1e-12) {
                // Baseline ~0: scale by observed deviation
                $scale = $maxAbsDev;
            } elseif ($maxAbsRaw > 1e-12) {
                // Fallback: scale by magnitude of raw values
                $scale = $maxAbsRaw;
            } else {
                $scale = 1.0;
            }

            foreach ($runIds as $rid) {
                $raw = $results['raw']['by_run'][$rid][$code]['raw'] ?? null;
                if ($raw === null || !is_numeric($raw) || $baseRaw === null) {
                    $normalized['by_run'][$rid][$code] = null;
                    $weighted['by_run'][$rid][$code] = null;
                    continue;
                }

                $rawF = (float)$raw;

                // Relative delta vs baseline when baseline != 0, else absolute delta scaled by $scale
                if (abs($baseRaw) > 1e-12) {
                    $delta = ($rawF - $baseRaw) / abs($baseRaw); // e.g. 0.2 = +20%
                } else {
                    $delta = ($rawF - $baseRaw) / $scale;        // baseline is ~0
                }

                // cap extreme deltas so score stays stable
                $delta = $clamp($delta, -$REL_CAP, $REL_CAP);

                // map [-cap..cap] to [0..1] around 0.5
                $score = 0.5 + ($delta / $REL_CAP) * 0.5;

                // invert if "lower is better"
                if (($dirByCode[$code] ?? 'pos') === 'neg') {
                    $score = 1.0 - $score;
                }

                $score = $clamp($score, 0.0, 1.0);

                $normalized['by_run'][$rid][$code] = $score;

                $w = $wByCode[$code] ?? 0.0;
                $weighted['by_run'][$rid][$code] = $score * $w;
            }
        }

        // Totals (scalar)
        foreach ($runIds as $rid) {
            $sum = 0.0;
            $have = false;
            foreach ($enabledMcaCodes as $code) {
                $ws = $weighted['by_run'][$rid][$code] ?? null;
                if ($ws === null) continue;
                $have = true;
                $sum += (float)$ws;
            }
            $totals[] = [
                'run_id' => $rid,
                'total_weighted_score' => $have ? $sum : null,
            ];
        }

        // 2) Time-series normalization across runs per YEAR (based on per-run SERIES)
        $normalizedTs = ['by_run' => []]; // [rid][code][year] => score
        $weightedTs   = ['by_run' => []]; // [rid][code][year] => score*weight
        $totalsTs     = ['by_run' => []]; // [rid][year] => total

        foreach ($enabledMcaCodes as $code) {
            // union of years across runs for this code
            $years = [];
            foreach ($runIds as $rid) {
                $series = $results['raw']['by_run'][$rid][$code]['series'] ?? [];
                if (!is_array($series)) continue;
                foreach (array_keys($series) as $y) $years[(int)$y] = true;
            }
            $years = array_keys($years);
            sort($years);

            foreach ($years as $y) {
                // min/max across runs for this (code,year)
                $vals = [];
                foreach ($runIds as $rid) {
                    $v = $results['raw']['by_run'][$rid][$code]['series'][$y] ?? null;
                    if ($v !== null && is_numeric($v)) $vals[] = (float)$v;
                }
                if (!$vals) {
                    foreach ($runIds as $rid) {
                        $normalizedTs['by_run'][$rid][$code][$y] = null;
                        $weightedTs['by_run'][$rid][$code][$y] = null;
                    }
                    continue;
                }

                $min = min($vals);
                $max = max($vals);

                foreach ($runIds as $rid) {
                    $v = $results['raw']['by_run'][$rid][$code]['series'][$y] ?? null;

                    if ($v === null || !is_numeric($v)) {
                        $score = null;
                    } elseif (abs($max - $min) < 1e-12) {
                        $score = 0.5;
                    } else {
                        $t = (((float)$v) - $min) / ($max - $min);
                        $score = (($dirByCode[$code] ?? 'pos') === 'neg') ? (1.0 - $t) : $t;
                        $score = max(0.0, min(1.0, $score));
                    }

                    $normalizedTs['by_run'][$rid][$code][$y] = $score;
                    $w = $wByCode[$code] ?? 0.0;
                    $weightedTs['by_run'][$rid][$code][$y] = ($score === null) ? null : ($score * $w);
                }
            }
        }

        // Totals (time series)
        foreach ($runIds as $rid) {
            $years = [];
            foreach ($enabledMcaCodes as $code) {
                foreach (array_keys($weightedTs['by_run'][$rid][$code] ?? []) as $y) {
                    $years[(int)$y] = true;
                }
            }
            $years = array_keys($years);
            sort($years);

            foreach ($years as $y) {
                $sum = 0.0; $have = false;
                foreach ($enabledMcaCodes as $code) {
                    $ws = $weightedTs['by_run'][$rid][$code][$y] ?? null;
                    if ($ws === null) continue;
                    $sum += (float)$ws;
                    $have = true;
                }
                $totalsTs['by_run'][$rid][$y] = $have ? $sum : null;
            }
        }

        // Attach to results ONCE
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
            'run_ids'          => $runIds,

            'enabled_mca'      => $enabledMcaCodes,
            'enabled_mca_meta' => $enabledMeta,
            'swat_required'    => $requiredSwat,
            'swat_series'      => $swatSeriesByRun,

            'results'          => $results,
            'totals'           => $results['totals'] ?? [],
            'warnings'         => $warnings,

            // you can remove this echo later
            'ui' => [
                'variables'           => $globalVars,
                'crop_variables'      => $payload['crop_variables'] ?? [],
                'crop_ref_factors'    => $payload['crop_ref_factors'] ?? [],
                'preset_items'        => $presetItems,
                'crop_code'           => $cropFilter,
            ],
        ];
    }
}