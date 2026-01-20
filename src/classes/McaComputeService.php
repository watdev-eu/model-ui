<?php
// src/classes/McaComputeService.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class McaComputeService
{
    public static function compute(
        int $presetSetId,
        ?string $cropCode,
        ?array $overrideItems = null,
        ?array $varsOverrides = null,
        ?array $cropVarsOverrides = null
    ): array
    {
        $pdo = Database::pdo();

        $vars = self::loadVariablesForPreset($pdo, $presetSetId); // from default set (DB)
        if (is_array($varsOverrides)) {
            foreach ($varsOverrides as $v) {
                $key = (string)($v['key'] ?? '');
                if ($key === '') continue;
                // choose which field based on data_type
                if (($v['data_type'] ?? '') === 'bool') $vars[$key] = (bool)($v['value_bool'] ?? false);
                elseif (($v['data_type'] ?? '') === 'text') $vars[$key] = (string)($v['value_text'] ?? '');
                else $vars[$key] = ($v['value_num'] ?? null) !== null ? (float)$v['value_num'] : null;
            }
        }

        // apply crop variable overrides into $vars['_crop'][crop_code][key]
        if (is_array($cropVarsOverrides)) {
            if (!isset($vars['_crop']) || !is_array($vars['_crop'])) $vars['_crop'] = [];

            foreach ($cropVarsOverrides as $v) {
                if (!is_array($v)) continue;

                $crop = trim((string)($v['crop_code'] ?? ''));
                $key  = trim((string)($v['key'] ?? ''));
                if ($crop === '' || $key === '') continue;

                if (!isset($vars['_crop'][$crop]) || !is_array($vars['_crop'][$crop])) {
                    $vars['_crop'][$crop] = [];
                }

                $dt = (string)($v['data_type'] ?? 'number');
                if ($dt === 'bool') {
                    $vars['_crop'][$crop][$key] = (bool)($v['value_bool'] ?? false);
                } elseif ($dt === 'text') {
                    $vars['_crop'][$crop][$key] = ($v['value_text'] ?? null) !== null ? (string)$v['value_text'] : null;
                } else {
                    $vars['_crop'][$crop][$key] = ($v['value_num'] ?? null) !== null ? (float)$v['value_num'] : null;
                }
            }
        }

        // scenarios
        $stmt = $pdo->prepare("
            SELECT id, scenario_key, label, run_id, sort_order
            FROM mca_scenarios
            WHERE preset_set_id = :ps
            ORDER BY sort_order
        ");
        $stmt->execute([':ps' => $presetSetId]);
        $scRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$scRows) throw new InvalidArgumentException('No scenarios configured for this preset');

        // enabled indicators
        $stmt = $pdo->prepare("
          SELECT
              i.id AS indicator_id,
              i.code AS indicator_code,
              i.name AS indicator_name,
              i.calc_key,
              COALESCE(pi.direction, i.default_direction) AS direction,
              pi.weight,
              pi.is_enabled
          FROM mca_preset_items pi
          JOIN mca_indicators i ON i.id = pi.indicator_id
          WHERE pi.preset_set_id = :ps
          ORDER BY i.code
        ");
        $stmt->execute([':ps' => $presetSetId]);
        $indRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$indRows) throw new InvalidArgumentException('No preset items found');

        if (is_array($overrideItems)) {
            $byCode = [];
            foreach ($overrideItems as $it) {
                $c = (string)($it['indicator_code'] ?? '');
                if ($c !== '') $byCode[$c] = $it;
            }

            foreach ($indRows as &$row) {
                $code = (string)$row['indicator_code'];
                if (!isset($byCode[$code])) continue;

                $ov = $byCode[$code];

                if (array_key_exists('is_enabled', $ov)) {
                    $row['is_enabled'] = (bool)$ov['is_enabled'];
                }
                if (array_key_exists('direction', $ov)) {
                    $d = (string)$ov['direction'];
                    if ($d === 'pos' || $d === 'neg') $row['direction'] = $d;
                }
                if (array_key_exists('weight', $ov)) {
                    $row['weight'] = (float)$ov['weight'];
                }
            }
            unset($row);
        }

        $indRows = array_values(array_filter($indRows, fn($r) => !empty($r['is_enabled'])));
        if (!$indRows) throw new InvalidArgumentException('No enabled indicators in preset');

        // compute raw values
        $raw = []; // raw[indicator_code][scenario_id] = value
        foreach ($indRows as $ind) {
            $code = (string)$ind['indicator_code'];
            $raw[$code] = [];
            foreach ($scRows as $sc) {
                $raw[$code][(int)$sc['id']] =
                    self::calcRaw(
                        (string)$ind['calc_key'],
                        $sc['run_id'] ? (int)$sc['run_id'] : null,
                        $cropCode,
                        $vars
                    );
            }
        }

        $rows = [];
        $totalsByScenario = []; // scenario_id => total
        foreach ($scRows as $sc) $totalsByScenario[(int)$sc['id']] = 0.0;

        foreach ($indRows as $ind) {
            $code = (string)$ind['indicator_code'];
            $dir  = (string)$ind['direction']; // pos/neg
            $w    = (float)$ind['weight'];

            $vals = array_values($raw[$code]);
            $min = null;
            $max = null;
            foreach ($vals as $v) {
                if ($v === null) continue;
                $fv = (float)$v;
                $min = ($min === null) ? $fv : min($min, $fv);
                $max = ($max === null) ? $fv : max($max, $fv);
            }

            foreach ($scRows as $sc) {
                $scenarioId = (int)$sc['id'];
                $x = $raw[$code][$scenarioId] ?? null;
                $xv = $x === null ? null : (float)$x;

                $score = null;
                if ($xv !== null && $min !== null && $max !== null) {
                    if (abs($max - $min) < 1e-12) {
                        $score = 1.0; // all equal => all equally best
                    } else {
                        if ($dir === 'pos') {
                            $score = ($xv - $min) / ($max - $min);
                        } else {
                            $score = ($max - $xv) / ($max - $min);
                        }
                        if ($score < 0) $score = 0.0;
                        if ($score > 1) $score = 1.0;
                    }
                }

                $weighted = ($score === null) ? null : ($score * $w);
                if ($weighted !== null) $totalsByScenario[$scenarioId] += $weighted;

                $rows[] = [
                    'scenario_id'      => $scenarioId,
                    'scenario_key'     => $sc['scenario_key'],
                    'scenario_label'   => $sc['label'],
                    'indicator_code'   => $code,
                    'indicator_name'   => $ind['indicator_name'],
                    'direction'        => $dir,
                    'weight'           => $w,
                    'crop_code'        => $cropCode,
                    'raw_value'        => $xv,
                    'normalized_score' => $score,
                    'weighted_score'   => $weighted,
                ];
            }
        }

        $totals = [];
        foreach ($scRows as $sc) {
            $sid = (int)$sc['id'];
            $totals[] = [
                'run_id'              => $sc['run_id'] ? (int)$sc['run_id'] : null,
                'scenario_id'         => $sid,
                'scenario_key'        => $sc['scenario_key'],
                'label'               => $sc['label'],
                'crop_code'           => $cropCode,
                'total_weighted_score'=> $totalsByScenario[$sid],
            ];
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'vars_used' => $vars,
        ];
    }

    private static function calcRaw(
        string $calcKey,
        ?int $runId,
        ?string $cropCode,
        array $vars
    ): ?float
    {
        if (!$runId) return null;

        switch ($calcKey) {
            case 'water_use_intensity':
                return self::calcWaterUseIntensity($runId, $cropCode, $vars);

            case 'water_tech_eff':
                return self::calcWaterTechEff($runId, $cropCode, $vars);

            case 'soil_erosion':
                return self::calcSoilErosion($runId, $cropCode, $vars);

            case 'bcr':
                return self::calcBcr($runId, $cropCode, $vars);

            case 'income_increase_pct':
                return self::calcIncomeIncreasePct($runId, $cropCode, $vars);

            case 'labour_use':
                return self::calcLabourUse($runId, $cropCode, $vars);

            // (future from Excel MCA sheet)
            case 'water_econ_eff':
                return self::calcWaterEconEff($runId, $cropCode, $vars);

            case 'depth_to_groundwater':
                return self::calcDepthToGroundwater($runId, $cropCode, $vars);

            case 'fertiliser_use_eff':
                return self::calcFertiliserUseEff($runId, $cropCode, $vars);

            case 'carbon_sequestration':
                return self::calcCarbonSequestration($runId, $cropCode, $vars);

            case 'aqi':
                return self::calcAqi($runId, $cropCode, $vars);

            default:
                return null;
        }
    }

    private static function calcWaterUseIntensity(int $runId, ?string $cropCode, array $vars): ?float
    {
        // m3/ha = irr_mm * 10 (area-weighted avg irr_mm over selected HRUs)
        $pdo = Database::pdo();

        $sql = "
            SELECT
              SUM(area_km2) AS a,
              SUM(irr_mm * area_km2) / NULLIF(SUM(area_km2),0) AS irr_mm_wavg
            FROM swat_hru_kpi
            WHERE run_id = :run_id
              AND (:crop IS NULL OR lulc = :crop)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':run_id' => $runId, ':crop' => $cropCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['irr_mm_wavg'] === null) return null;

        $irrMm = (float)$row['irr_mm_wavg'];
        return $irrMm * 10.0; // mm -> m3/ha
    }

    private static function calcWaterTechEff(int $runId, ?string $cropCode, array $vars): ?float
    {
        // kg/m3 = (yld_t_ha * 1000) / (irr_mm * 10)
        $pdo = Database::pdo();

        $sql = "
            SELECT
              SUM(area_km2) AS a,
              SUM(irr_mm * area_km2) / NULLIF(SUM(area_km2),0) AS irr_mm_wavg,
              SUM(yld_t_ha * area_km2) / NULLIF(SUM(area_km2),0) AS yld_t_ha_wavg
            FROM swat_hru_kpi
            WHERE run_id = :run_id
              AND (:crop IS NULL OR lulc = :crop)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':run_id' => $runId, ':crop' => $cropCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['irr_mm_wavg'] === null || $row['yld_t_ha_wavg'] === null) return null;

        $irrMm = (float)$row['irr_mm_wavg'];
        $yldT  = (float)$row['yld_t_ha_wavg'];

        $den = $irrMm * 10.0;
        if ($den <= 0) return null;

        return ($yldT * 1000.0) / $den;
    }

    private static function calcIncomeIncreasePct(int $runId, ?string $cropCode, array $vars): ?float
    {
        $before = self::vNum($vars, 'baseline_net_income_usd_ha', null);
        $after  = self::vNum($vars, 'net_income_after_usd_ha', null);

        if ($before === null || abs($before) < 1e-12 || $after === null) return null;
        return (($after - $before) / $before) * 100.0;
    }

    private static function calcLabourUse(int $runId, ?string $cropCode, array $vars): ?float
    {
        return self::vNum($vars, 'labour_hours_per_ha', null);
    }

    private static function calcBcr(int $runId, ?string $cropCode, array $vars): ?float
    {
        $r = self::vNum($vars, 'discount_rate', null);
        $T = self::vNum($vars, 'time_horizon_years', null);
        if ($r === null || $T === null) return null;

        $T = (int)round($T);
        if ($T <= 0) return null;

        $I = self::vNum($vars, 'bmp_invest_cost_usd_ha', 0.0) ?? 0.0;
        $C = self::vNum($vars, 'bmp_annual_om_cost_usd_ha', 0.0) ?? 0.0;

        // Benefits: explicit or derived from income delta
        $B = self::vNum($vars, 'bmp_annual_benefit_usd_ha', null);
        if ($B === null) {
            $before = self::vNum($vars, 'baseline_net_income_usd_ha', null);
            $after  = self::vNum($vars, 'net_income_after_usd_ha', null);
            if ($before !== null && $after !== null) {
                $B = $after - $before;
            }
        }
        if ($B === null) return null;

        $pvAnn = function(float $ann) use ($r, $T): float {
            $sum = 0.0;
            for ($t = 1; $t <= $T; $t++) {
                $sum += $ann / pow(1.0 + $r, $t);
            }
            return $sum;
        };

        $pvBenefits = $pvAnn($B);
        $pvCosts    = $I + $pvAnn($C);

        if ($pvCosts <= 0) return null;
        return $pvBenefits / $pvCosts;
    }

    private static function calcSoilErosion(int $runId, ?string $cropCode, array $vars): ?float
    {
        // Use HRU soil loss (syld_t_ha), area-weighted average over HRUs (and all periods available)
        $pdo = Database::pdo();

        $sql = "
        SELECT
          SUM(area_km2) AS a,
          SUM(syld_t_ha * area_km2) / NULLIF(SUM(area_km2),0) AS syld_wavg
        FROM swat_hru_kpi
        WHERE run_id = :run_id
          AND syld_t_ha IS NOT NULL
          AND area_km2 IS NOT NULL
          AND (:crop IS NULL OR lulc = :crop)
    ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':run_id' => $runId, ':crop' => $cropCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['syld_wavg'] === null) return null;

        return (float)$row['syld_wavg']; // t/ha (per period; averaged across periods in table)
    }

    private static function loadVariablesForPreset(PDO $pdo, int $presetSetId): array
    {
        // find study_area_id from preset
        $stmt = $pdo->prepare("SELECT study_area_id FROM mca_preset_sets WHERE id = :id");
        $stmt->execute([':id' => $presetSetId]);
        $studyAreaId = (int)$stmt->fetchColumn();
        if ($studyAreaId <= 0) return [];

        // default variable set for that study area
        $stmt = $pdo->prepare("
        SELECT id
        FROM mca_variable_sets
        WHERE study_area_id = :sa AND user_id IS NULL AND is_default = TRUE
        ORDER BY id ASC
        LIMIT 1
    ");
        $stmt->execute([':sa' => $studyAreaId]);
        $setId = (int)$stmt->fetchColumn();
        if ($setId <= 0) return [];

        // load values
        $stmt = $pdo->prepare("
        SELECT
            v.key,
            v.data_type,
            vv.value_num,
            vv.value_text,
            vv.value_bool
        FROM mca_variable_values vv
        JOIN mca_variables v ON v.id = vv.variable_id
        WHERE vv.variable_set_id = :sid
        ORDER BY v.key
    ");
        $stmt->execute([':sid' => $setId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $key = (string)$r['key'];
            $dt  = (string)$r['data_type'];
            if ($dt === 'bool') $out[$key] = (bool)$r['value_bool'];
            elseif ($dt === 'text') $out[$key] = $r['value_text'] !== null ? (string)$r['value_text'] : null;
            else $out[$key] = $r['value_num'] !== null ? (float)$r['value_num'] : null;
        }

        // crop-specific values
        $stmt = $pdo->prepare("
          SELECT
            c.code AS crop_code,
            v.key,
            v.data_type,
            vvc.value_num,
            vvc.value_text,
            vvc.value_bool
          FROM mca_variable_values_crop vvc
          JOIN mca_variables v ON v.id = vvc.variable_id
          JOIN crops c ON c.code = vvc.crop_code
          WHERE vvc.variable_set_id = :sid
        ");
        $stmt->execute([':sid' => $setId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out['_crop'] = [];
        foreach ($rows as $r) {
            $crop = (string)$r['crop_code'];
            $key  = (string)$r['key'];
            $dt   = (string)$r['data_type'];

            if (!isset($out['_crop'][$crop])) $out['_crop'][$crop] = [];

            if ($dt === 'bool') $out['_crop'][$crop][$key] = (bool)$r['value_bool'];
            elseif ($dt === 'text') $out['_crop'][$crop][$key] = $r['value_text'] !== null ? (string)$r['value_text'] : null;
            else $out['_crop'][$crop][$key] = $r['value_num'] !== null ? (float)$r['value_num'] : null;
        }

        return $out;
    }

    private static function calcWaterEconEff(int $runId, ?string $cropCode, array $vars): ?float
    {
        // USD/m3 = (economic value per ha) / (irrigation m3 per ha)
        // economic value per ha = area-weighted avg (yld_t_ha * crop_price_usd_per_t)
        // water m3/ha = area-weighted avg irr_mm * 10

        $pdo = Database::pdo();

        // Build a per-HRU contribution using lulc as crop_code mapping.
        // Requires lulc codes to match crops.code.
        $sql = "
      SELECT
        SUM(h.area_km2) AS a,
        SUM(h.irr_mm * h.area_km2) / NULLIF(SUM(h.area_km2),0) AS irr_mm_wavg,
        SUM(h.yld_t_ha * h.area_km2) / NULLIF(SUM(h.area_km2),0) AS yld_t_ha_wavg,
        SUM(h.yld_t_ha * h.area_km2) AS yld_area_sum
      FROM swat_hru_kpi h
      WHERE h.run_id = :run_id
        AND h.area_km2 IS NOT NULL
        AND h.yld_t_ha IS NOT NULL
        AND h.irr_mm IS NOT NULL
        AND (:crop IS NULL OR h.lulc = :crop)
    ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':run_id' => $runId, ':crop' => $cropCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['irr_mm_wavg'] === null) return null;

        $irrMm = (float)$row['irr_mm_wavg'];
        $water = $irrMm * 10.0;
        if ($water <= 0) return null;

        // Price lookup
        // If cropCode set: use that price directly.
        if ($cropCode !== null) {
            $price = $vars['_crop'][$cropCode]['crop_price_usd_per_t'] ?? null;
            if ($price === null) return null;

            $yld = $row['yld_t_ha_wavg'] !== null ? (float)$row['yld_t_ha_wavg'] : null;
            if ($yld === null) return null;

            $valueUsdHa = $yld * (float)$price;
            return $valueUsdHa / $water;
        }

        // If no crop filter: we need a weighted price across HRUs by crop.
        // Without that per-row join to crop price, we cannot compute a correct mixed-crop USD/ha.
        // So return null unless you want the "require crop selection" behavior.
        return null;
    }

    private static function calcDepthToGroundwater(int $runId, ?string $cropCode, array $vars): ?float { return null; }
    private static function calcFertiliserUseEff(int $runId, ?string $cropCode, array $vars): ?float { return null; }
    private static function calcCarbonSequestration(int $runId, ?string $cropCode, array $vars): ?float { return null; }
    private static function calcAqi(int $runId, ?string $cropCode, array $vars): ?float { return null; }

    private static function vNum(array $vars, string $key, ?float $default = null): ?float
    {
        if (!array_key_exists($key, $vars)) return $default;
        $v = $vars[$key];
        if ($v === null || $v === '') return $default;
        $f = (float)$v;
        return is_finite($f) ? $f : $default;
    }

    private static function vBool(array $vars, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $vars)) return $default;
        return (bool)$vars[$key];
    }

    private static function vText(array $vars, string $key, ?string $default = null): ?string
    {
        if (!array_key_exists($key, $vars)) return $default;
        $t = $vars[$key];
        if ($t === null) return $default;
        return trim((string)$t);
    }

    private static function requireNum(array $vars, string $key): ?float
    {
        $v = self::vNum($vars, $key, null);
        return ($v === null) ? null : $v;
    }
}