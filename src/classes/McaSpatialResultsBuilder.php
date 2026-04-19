<?php
// src/classes/McaSpatialResultsBuilder.php
declare(strict_types=1);

require_once __DIR__ . '/McaIndicatorRegistry.php';
require_once __DIR__ . '/McaWaterRightsRepository.php';

final class McaSpatialResultsBuilder
{
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

    public static function build(array $args): array
    {
        $datasetContexts     = $args['dataset_contexts'] ?? [];
        $enabledCodes        = array_values(array_unique(array_map('strval', $args['enabled_codes'] ?? [])));
        $swatSeriesByDataset = $args['swat_series_by_dataset'] ?? [];
        $swatSeriesByRun     = $args['swat_series_by_run'] ?? [];
        $cropVarsIdx         = $args['crop_vars_idx'] ?? [];
        $baselineFactorsIdx  = $args['baseline_factors_idx'] ?? [];
        $runVarIdxById       = $args['run_var_idx_by_id'] ?? [];
        $runFactorsById      = $args['run_factors_by_id'] ?? [];
        $globalIdx           = $args['global_idx'] ?? [];
        $baselineRunId       = (int)($args['baseline_run_id'] ?? 0);
        $cropFilter          = isset($args['crop_filter']) && $args['crop_filter'] !== '' ? (string)$args['crop_filter'] : null;

        $out = ['by_dataset' => []];

        foreach ($datasetContexts as $ds) {
            $datasetId = (string)($ds['dataset_id'] ?? '');
            if ($datasetId === '') continue;

            $runVarIdx = $runVarIdxById[$datasetId] ?? [];
            $runFactorsIdx = $runFactorsById[$datasetId] ?? [];

            $swatDataset = $swatSeriesByDataset[$datasetId] ?? [];

            $yieldBySub = $swatDataset['crop_yield_t_ha']['by_sub'] ?? [];
            $yieldBaselineBySub = $swatSeriesByRun[$baselineRunId]['crop_yield_t_ha']['by_sub'] ?? [];
            $irrBySub = $swatDataset['irr_mm']['by_sub'] ?? [];
            $cSeqBySub = $swatDataset['crop_c_seq_t_ha']['by_sub'] ?? [];
            $nueNBySub = $swatDataset['nue_n_pct']['by_sub'] ?? [];
            $nuePBySub = $swatDataset['nue_p_pct']['by_sub'] ?? [];
            $subCropAreaHa = $swatDataset['crop_yield_t_ha']['sub_crop_area_ha'] ?? [];

            $datasetYears = self::yearsFromNestedSeries($yieldBySub);

            $discountPct = self::getNumFromVarIdx($runVarIdx, 'discount_rate') ?? 0.0;
            $life = (int)round(self::getNumFromVarIdx($runVarIdx, 'economic_life_years') ?? 0);
            if ($life <= 0) $life = 1;

            $invest = self::getNumFromVarIdx($runVarIdx, 'bmp_invest_cost_usd_ha') ?? 0.0;
            $om     = self::getNumFromVarIdx($runVarIdx, 'bmp_annual_om_cost_usd_ha') ?? 0.0;

            $farmSizeHa = self::getNumFromVarIdx($globalIdx, 'farm_size_ha');
            $landRent   = self::getNumFromVarIdx($globalIdx, 'land_rent_usd_ha_yr')
                ?? self::getNumFromVarIdx($globalIdx, 'land_rent_usd_ha')
                ?? 0.0;

            $waterFeeHa = self::getNumFromVarIdx($runVarIdx, 'water_use_fee_usd_ha')
                ?? self::getNumFromVarIdx($globalIdx, 'water_use_fee_usd_ha')
                ?? 0.0;

            $waterCostM3 = self::getNumFromVarIdx($runVarIdx, 'water_cost_usd_m3')
                ?? self::getNumFromVarIdx($globalIdx, 'water_cost_usd_m3')
                ?? 0.0;

            $labDayCost = self::getNumFromVarIdx($globalIdx, 'labour_day_cost_usd_per_pd')
                ?? self::getNumFromVarIdx($globalIdx, 'labour_cost_usd_per_day')
                ?? self::getNumFromVarIdx($globalIdx, 'labour_day_cost_usd')
                ?? 0.0;

            $baseProdCostByCrop = [];
            $bmpProdCostByCrop = [];
            $allCrops = self::collectCropsFromSubCropArea($subCropAreaHa, $cropFilter);

            foreach ($allCrops as $crop) {
                $baseMaterial = self::sumFactorKeys($baselineFactorsIdx, $crop, self::MATERIAL_KEYS);
                $basePdSum = self::sumFactorKeys($baselineFactorsIdx, $crop, self::LABOUR_KEYS);
                $baseLabour = $labDayCost * $basePdSum;
                $baseProdCostByCrop[$crop] = $baseMaterial + $baseLabour;

                $bmpMaterial = self::sumFactorKeys($runFactorsIdx, $crop, self::MATERIAL_KEYS);
                $bmpPdSum = self::sumFactorKeys($runFactorsIdx, $crop, self::LABOUR_KEYS);
                $bmpLabour = $labDayCost * $bmpPdSum;
                $bmpProdCostByCrop[$crop] = $bmpMaterial + $bmpLabour;
            }

            foreach ($enabledCodes as $code) {
                if (!isset(McaIndicatorRegistry::MAP[$code])) continue;

                $meta = McaIndicatorRegistry::meta($code);
                $grain = (string)$meta['grain'];

                if ($code === 'water_rights_access') {
                    $out['by_dataset'][$datasetId][$code] = self::buildWaterRightsAccessSpatial(
                        $ds,
                        (float)($farmSizeHa ?? 0.0)
                    );
                    continue;
                }

                if ($grain === 'sub') {
                    $out['by_dataset'][$datasetId][$code] = [
                        'grain' => 'sub',
                        'by_sub' => [],
                    ];
                } else {
                    $out['by_dataset'][$datasetId][$code] = [
                        'grain' => 'sub_crop',
                        'by_sub_crop' => [],
                        'by_sub' => [],
                    ];
                }

                if ($code === 'water_use_intensity') {
                    $bySubCrop = self::mapNestedSeries($irrBySub, static fn(?float $v) => $v === null ? null : ($v * 10.0));
                    $out['by_dataset'][$datasetId][$code]['by_sub_crop'] = $bySubCrop;
                    $out['by_dataset'][$datasetId][$code]['by_sub'] = self::aggregateBySubCropWeightedMean($bySubCrop, $subCropAreaHa, $cropFilter);
                    continue;
                }

                if ($code === 'carbon_sequestration') {
                    $out['by_dataset'][$datasetId][$code]['by_sub_crop'] = $cSeqBySub;
                    $out['by_dataset'][$datasetId][$code]['by_sub'] = self::aggregateBySubCropWeightedMean($cSeqBySub, $subCropAreaHa, $cropFilter);
                    continue;
                }

                if ($code === 'fertiliser_use_eff_n') {
                    $out['by_dataset'][$datasetId][$code]['by_sub_crop'] = $nueNBySub;
                    $out['by_dataset'][$datasetId][$code]['by_sub'] = self::aggregateBySubCropWeightedMean($nueNBySub, $subCropAreaHa, $cropFilter);
                    continue;
                }

                if ($code === 'fertiliser_use_eff_p') {
                    $out['by_dataset'][$datasetId][$code]['by_sub_crop'] = $nuePBySub;
                    $out['by_dataset'][$datasetId][$code]['by_sub'] = self::aggregateBySubCropWeightedMean($nuePBySub, $subCropAreaHa, $cropFilter);
                    continue;
                }

                if ($code === 'labour_use') {
                    $bySubCrop = [];
                    foreach ($subCropAreaHa as $sub => $cropAreas) {
                        foreach ($cropAreas as $crop => $_area) {
                            if ($cropFilter !== null && $crop !== $cropFilter) continue;
                            $pd = self::sumFactorKeys($runFactorsIdx, $crop, self::LABOUR_KEYS);
                            foreach ($datasetYears as $year) {
                                $bySubCrop[(int)$sub][(string)$crop][(int)$year] = $pd;
                            }
                        }
                    }
                    $out['by_dataset'][$datasetId][$code]['by_sub_crop'] = $bySubCrop;
                    $out['by_dataset'][$datasetId][$code]['by_sub'] = self::aggregateBySubCropWeightedMean($bySubCrop, $subCropAreaHa, $cropFilter);
                    continue;
                }

                $bySubCrop = [];
                $bySub = [];

                foreach ($subCropAreaHa as $sub => $cropAreas) {
                    $sub = (int)$sub;
                    $weights = self::cropWeightsForSub($subCropAreaHa, $sub, $cropFilter);
                    $subYears = self::yearsFromNestedSeries($yieldBySub[$sub] ?? []);

                    $aggNum = [];
                    $aggDen = [];
                    $aggAfter = [];
                    $aggBefore = [];

                    foreach ($cropAreas as $crop => $_area) {
                        $crop = (string)$crop;
                        if ($cropFilter !== null && $crop !== $cropFilter) continue;

                        $price = self::getCropVarNum($cropVarsIdx, $crop, 'crop_price_usd_per_t');
                        $yScn = $yieldBySub[$sub][$crop] ?? [];
                        $yRef = $yieldBaselineBySub[$sub][$crop] ?? [];
                        $irrSeries = $irrBySub[$sub][$crop] ?? [];

                        foreach ($subYears as $year) {
                            $i = $year - $subYears[0];

                            $yieldScn = self::numOrNull($yScn[$year] ?? null);
                            $yieldRef = self::numOrNull($yRef[$year] ?? null);
                            $irrMm = self::numOrNull($irrSeries[$year] ?? null);
                            $irrM3Ha = $irrMm === null ? 0.0 : ($irrMm * 10.0);

                            $annualInvest = $invest / $life;
                            $investThisYear = ($i % $life === 0) ? $invest : 0.0;

                            $baseProd = $baseProdCostByCrop[$crop] ?? 0.0;
                            $bmpProd = $bmpProdCostByCrop[$crop] ?? 0.0;
                            $waterCostHa = $waterCostM3 * $irrM3Ha;

                            if ($code === 'water_tech_eff') {
                                $num = ($yieldScn === null) ? null : ($yieldScn * 1000.0);
                                $den = $irrM3Ha;
                                $bySubCrop[$sub][$crop][$year] = ($num !== null && abs($den) > 1e-12) ? ($num / $den) : null;
                                if ($num !== null && abs($den) > 1e-12) {
                                    $aggNum[$year] = ($aggNum[$year] ?? 0.0) + ($num * ($weights[$crop] ?? 0.0));
                                    $aggDen[$year] = ($aggDen[$year] ?? 0.0) + ($den * ($weights[$crop] ?? 0.0));
                                }
                                continue;
                            }

                            if ($code === 'water_econ_eff') {
                                $rev = ($yieldScn !== null && $price !== null) ? ($yieldScn * $price) : null;
                                $den = $irrM3Ha;
                                $bySubCrop[$sub][$crop][$year] = ($rev !== null && abs($den) > 1e-12) ? ($rev / $den) : null;
                                if ($rev !== null && abs($den) > 1e-12) {
                                    $aggNum[$year] = ($aggNum[$year] ?? 0.0) + ($rev * ($weights[$crop] ?? 0.0));
                                    $aggDen[$year] = ($aggDen[$year] ?? 0.0) + ($den * ($weights[$crop] ?? 0.0));
                                }
                                continue;
                            }

                            if ($code === 'price_cost_ratio') {
                                $rev = ($yieldScn !== null && $price !== null) ? ($yieldScn * $price) : null;
                                $cost = $bmpProd + $annualInvest + $om;
                                $bySubCrop[$sub][$crop][$year] = ($rev !== null && abs($cost) > 1e-12) ? ($rev / $cost) : null;
                                if ($rev !== null && abs($cost) > 1e-12) {
                                    $aggNum[$year] = ($aggNum[$year] ?? 0.0) + ($rev * ($weights[$crop] ?? 0.0));
                                    $aggDen[$year] = ($aggDen[$year] ?? 0.0) + ($cost * ($weights[$crop] ?? 0.0));
                                }
                                continue;
                            }

                            if ($code === 'cost_saving_usd') {
                                $bySubCrop[$sub][$crop][$year] = $baseProd - ($bmpProd + $annualInvest + $om);
                                continue;
                            }

                            if ($code === 'net_farm_income_usd_ha') {
                                $rev = ($yieldScn !== null && $price !== null) ? ($yieldScn * $price) : null;
                                $cost = $bmpProd + $investThisYear + $om + $landRent + $waterFeeHa + $waterCostHa;
                                $bySubCrop[$sub][$crop][$year] = $rev === null ? null : ($rev - $cost);
                                continue;
                            }

                            if ($code === 'income_increase_pct') {
                                $afterRev = ($yieldScn !== null && $price !== null) ? ($yieldScn * $price) : null;
                                $beforeRev = ($yieldRef !== null && $price !== null) ? ($yieldRef * $price) : null;
                                $after = ($afterRev === null) ? null : ($afterRev - ($bmpProd + $om + $landRent + $waterFeeHa + $waterCostHa));
                                $before = ($beforeRev === null) ? null : ($beforeRev - ($baseProd + $landRent + $waterFeeHa + $waterCostHa));

                                $bySubCrop[$sub][$crop][$year] = ($after !== null && $before !== null && abs($before) > 1e-12)
                                    ? ((($after - $before) / $before) * 100.0)
                                    : null;

                                if ($after !== null && $before !== null) {
                                    $aggAfter[$year] = ($aggAfter[$year] ?? 0.0) + ($after * ($weights[$crop] ?? 0.0));
                                    $aggBefore[$year] = ($aggBefore[$year] ?? 0.0) + ($before * ($weights[$crop] ?? 0.0));
                                }
                                continue;
                            }

                            if ($code === 'bcr') {
                                $bcr = self::computeBcrCropSeries(
                                    $yScn,
                                    $yRef,
                                    $price,
                                    $discountPct,
                                    $life,
                                    $invest,
                                    $om,
                                    $baseProd,
                                    $bmpProd
                                );

                                $bySubCrop[$sub][$crop] = $bcr['series'];

                                foreach ($bcr['numerator'] as $year => $num) {
                                    $aggNum[(int)$year] = ($aggNum[(int)$year] ?? 0.0) + ($num * ($weights[$crop] ?? 0.0));
                                }
                                foreach ($bcr['denominator'] as $year => $den) {
                                    $aggDen[(int)$year] = ($aggDen[(int)$year] ?? 0.0) + ($den * ($weights[$crop] ?? 0.0));
                                }
                                continue;
                            }
                        }
                    }

                    if (in_array($code, ['cost_saving_usd', 'net_farm_income_usd_ha'], true)) {
                        $bySub[$sub] = self::aggregateSingleSubWeightedMean($bySubCrop[$sub] ?? [], $subCropAreaHa[$sub] ?? [], $cropFilter);
                    } elseif ($code === 'income_increase_pct') {
                        foreach ($aggAfter as $year => $after) {
                            $before = $aggBefore[$year] ?? null;
                            $bySub[$sub][(int)$year] = ($before !== null && abs((float)$before) > 1e-12)
                                ? ((($after - (float)$before) / (float)$before) * 100.0)
                                : null;
                        }
                    } elseif (in_array($code, ['water_tech_eff', 'water_econ_eff', 'price_cost_ratio', 'bcr'], true)) {
                        foreach ($aggNum as $year => $num) {
                            $den = $aggDen[$year] ?? null;
                            $bySub[$sub][(int)$year] = ($den !== null && abs((float)$den) > 1e-12)
                                ? (((float)$num) / ((float)$den))
                                : null;
                        }
                    }
                }

                $out['by_dataset'][$datasetId][$code]['by_sub_crop'] = $bySubCrop;
                $out['by_dataset'][$datasetId][$code]['by_sub'] = $bySub;
            }
        }

        return $out;
    }

    private static function buildWaterRightsAccessSpatial(array $datasetCtx, float $farmSizeHa): array
    {
        $datasetType = (string)($datasetCtx['dataset_type'] ?? 'run');

        if ($farmSizeHa <= 0) {
            return ['grain' => 'sub', 'by_sub' => []];
        }

        if ($datasetType === 'custom') {
            $effectiveRunMap = is_array($datasetCtx['effective_run_map'] ?? null) ? $datasetCtx['effective_run_map'] : [];
            $bySub = [];

            $cacheByRun = [];
            foreach (array_values(array_unique(array_map('intval', array_values($effectiveRunMap)))) as $runId) {
                if ($runId > 0) {
                    $cacheByRun[$runId] = McaWaterRightsRepository::getIrrigatedAreaHaMonthlyAndYearlyAvg($runId, []);
                }
            }

            foreach ($effectiveRunMap as $sub => $sourceRunId) {
                $sub = (int)$sub;
                $sourceRunId = (int)$sourceRunId;
                if ($sub <= 0 || $sourceRunId <= 0) continue;

                $yearly = $cacheByRun[$sourceRunId]['yearly_avg_monthly_irrigated_area_ha_by_sub'][$sub] ?? [];
                foreach ($yearly as $year => $ha) {
                    $bySub[$sub][(int)$year] = ((float)$ha) / $farmSizeHa;
                }
            }

            return ['grain' => 'sub', 'by_sub' => $bySub];
        }

        $runId = (int)($datasetCtx['source_run_ids'][0] ?? 0);
        if ($runId <= 0) {
            return ['grain' => 'sub', 'by_sub' => []];
        }

        $w = McaWaterRightsRepository::getIrrigatedAreaHaMonthlyAndYearlyAvg($runId, []);
        $bySub = [];

        foreach (($w['yearly_avg_monthly_irrigated_area_ha_by_sub'] ?? []) as $sub => $yearMap) {
            foreach ($yearMap as $year => $ha) {
                $bySub[(int)$sub][(int)$year] = ((float)$ha) / $farmSizeHa;
            }
        }

        return ['grain' => 'sub', 'by_sub' => $bySub];
    }

    private static function computeBcrCropSeries(
        array $yScn,
        array $yRef,
        ?float $price,
        float $discountPct,
        int $life,
        float $invest,
        float $om,
        float $baseProd,
        float $bmpProd
    ): array {
        $years = self::unionYears([$yScn, $yRef]);
        if (!$years || $price === null) {
            return ['series' => [], 'numerator' => [], 'denominator' => []];
        }

        $y0 = $years[0];
        $series = [];
        $numOut = [];
        $denOut = [];

        foreach ($years as $year) {
            $i = $year - $y0;
            $ys = self::numOrNull($yScn[$year] ?? null);
            $yr = self::numOrNull($yRef[$year] ?? null);

            if ($ys === null || $yr === null) {
                $series[$year] = null;
                continue;
            }

            $benefitRaw = ($ys - $yr) * $price;
            $investThisYear = ($i % $life === 0) ? $invest : 0.0;
            $costRaw = ($bmpProd - $baseProd) + $investThisYear + $om;
            $df = self::discountFactor($discountPct, $i);

            $num = $benefitRaw * $df;
            $den = $costRaw * $df;

            $numOut[$year] = $num;
            $denOut[$year] = $den;
            $series[$year] = abs($den) > 1e-12 ? ($num / $den) : null;
        }

        return [
            'series' => $series,
            'numerator' => $numOut,
            'denominator' => $denOut,
        ];
    }

    private static function aggregateBySubCropWeightedMean(array $bySubCrop, array $subCropAreaHa, ?string $cropFilter = null): array
    {
        $out = [];
        foreach ($bySubCrop as $sub => $cropSeries) {
            $out[(int)$sub] = self::aggregateSingleSubWeightedMean(
                is_array($cropSeries) ? $cropSeries : [],
                $subCropAreaHa[(int)$sub] ?? [],
                $cropFilter
            );
        }
        return $out;
    }

    private static function aggregateSingleSubWeightedMean(array $cropSeries, array $cropAreas, ?string $cropFilter = null): array
    {
        $weights = [];
        $sum = 0.0;
        foreach ($cropAreas as $crop => $area) {
            if ($cropFilter !== null && $crop !== $cropFilter) continue;
            if (!is_numeric($area) || (float)$area <= 0) continue;
            $weights[(string)$crop] = (float)$area;
            $sum += (float)$area;
        }
        if ($sum <= 0) return [];

        foreach ($weights as $crop => $w) {
            $weights[$crop] = $w / $sum;
        }

        $years = self::yearsFromNestedSeries($cropSeries);
        $out = [];

        foreach ($years as $year) {
            $num = 0.0;
            $den = 0.0;
            foreach ($weights as $crop => $w) {
                $v = self::numOrNull($cropSeries[$crop][$year] ?? null);
                if ($v === null) continue;
                $num += $v * $w;
                $den += $w;
            }
            $out[(int)$year] = $den > 0 ? ($num / $den) : null;
        }

        return $out;
    }

    private static function cropWeightsForSub(array $subCropAreaHa, int $sub, ?string $cropFilter = null): array
    {
        $areas = $subCropAreaHa[$sub] ?? [];
        $sum = 0.0;
        $out = [];

        foreach ($areas as $crop => $ha) {
            if ($cropFilter !== null && $crop !== $cropFilter) continue;
            if (!is_numeric($ha) || (float)$ha <= 0) continue;
            $out[(string)$crop] = (float)$ha;
            $sum += (float)$ha;
        }

        if ($sum <= 0) return [];

        foreach ($out as $crop => $ha) {
            $out[$crop] = $ha / $sum;
        }

        return $out;
    }

    private static function collectCropsFromSubCropArea(array $subCropAreaHa, ?string $cropFilter = null): array
    {
        $set = [];
        foreach ($subCropAreaHa as $crops) {
            foreach ($crops as $crop => $_ha) {
                if ($cropFilter !== null && $crop !== $cropFilter) continue;
                $set[(string)$crop] = true;
            }
        }
        $out = array_keys($set);
        sort($out);
        return $out;
    }

    private static function yearsFromNestedSeries(array $series): array
    {
        $years = [];
        $scan = function ($node) use (&$years, &$scan) {
            if (!is_array($node)) return;
            $allLeaf = true;
            foreach ($node as $v) {
                if (is_array($v)) {
                    $allLeaf = false;
                    break;
                }
            }
            if ($allLeaf) {
                foreach (array_keys($node) as $k) {
                    if (is_numeric($k)) $years[(int)$k] = true;
                }
                return;
            }
            foreach ($node as $v) {
                $scan($v);
            }
        };
        $scan($series);
        $out = array_keys($years);
        sort($out);
        return $out;
    }

    private static function unionYears(array $seriesList): array
    {
        $years = [];
        foreach ($seriesList as $s) {
            foreach (array_keys($s) as $y) {
                $years[(int)$y] = true;
            }
        }
        $out = array_keys($years);
        sort($out);
        return $out;
    }

    private static function mapNestedSeries(array $series, callable $fn): array
    {
        $out = [];
        foreach ($series as $sub => $cropMap) {
            foreach ($cropMap as $crop => $yearMap) {
                foreach ($yearMap as $year => $val) {
                    $out[(int)$sub][(string)$crop][(int)$year] = $fn(self::numOrNull($val));
                }
            }
        }
        return $out;
    }

    private static function getNumFromVarIdx(array $idx, string $key): ?float
    {
        return self::getNumFromVarRow($idx[$key] ?? null);
    }

    private static function getNumFromVarRow(?array $row): ?float
    {
        if (!$row) return null;
        $t = (string)($row['data_type'] ?? 'number');

        if ($t === 'number') return self::numOrNull($row['value_num'] ?? null);
        if ($t === 'bool') {
            $v = $row['value_bool'] ?? null;
            if (is_bool($v)) return $v ? 1.0 : 0.0;
            return self::numOrNull($v);
        }
        return self::numOrNull($row['value_text'] ?? null);
    }

    private static function getCropVarNum(array $cropVarsIdx, string $crop, string $key): ?float
    {
        return self::getNumFromVarRow($cropVarsIdx[$crop][$key] ?? null);
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
        $r = $ratePct / 100.0;
        if ($yearIndex <= 0) return 1.0;
        return 1.0 / pow(1.0 + $r, $yearIndex);
    }

    private static function numOrNull($v): ?float
    {
        return is_numeric($v) ? (float)$v : null;
    }
}