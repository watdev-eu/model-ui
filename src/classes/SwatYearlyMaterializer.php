<?php
// classes/SwatYearlyMaterializer.php

declare(strict_types=1);

require_once __DIR__ . '/SwatRawImportHelper.php';
require_once __DIR__ . '/SwatIndicatorConfig.php';

final class SwatYearlyMaterializer
{
    public static function buildImportArtifacts(
        array $rawFiles,
        array $cioMeta,
        array $selectedSubbasins,
        string $sourceType = 'original'
    ): array {
        $selectedSet = array_fill_keys(array_map('intval', $selectedSubbasins), true);

        $yearlyRows = [];

        $hruMonthly = self::collectHruMonthly($rawFiles['hru'], $cioMeta, $selectedSet, $sourceType);
        $yearlyRows = array_merge($yearlyRows, self::buildHruYearlyIndicators($hruMonthly));

        if (!empty($rawFiles['rch']) && is_file($rawFiles['rch'])) {
            $rchMonthly = self::collectRchMonthly($rawFiles['rch'], $cioMeta, $selectedSet, $sourceType);
            $yearlyRows = array_merge($yearlyRows, self::buildRchYearlyIndicators($rchMonthly));
        }

        $snuMonthly = self::collectSnuMonthly($rawFiles['snu'], $rawFiles['hru'], $cioMeta, $selectedSet, $sourceType);
        $yearlyRows = array_merge($yearlyRows, self::buildSnuYearlyIndicators($snuMonthly));

        $cropAreaRows = self::collectCropAreaContextRows($rawFiles['hru'], $selectedSet, $sourceType);
        $irrigationAreaRows = self::collectIrrigationAreaContextRows($rawFiles['hru'], $cioMeta, $selectedSet, $sourceType);

        return [
            'yearly_rows' => $yearlyRows,
            'crop_area_rows' => $cropAreaRows,
            'irrigation_area_rows' => $irrigationAreaRows,
        ];
    }

    private static function collectCropAreaContextRows(string $hruPath, array $selectedSet, string $sourceType): array
    {
        $hruAreaKm2 = [];

        if ($sourceType === 'csv') {
            foreach (SwatRawImportHelper::csvRowsAssocPublic($hruPath) as $row) {
                $sub = (int)($row['SUB'] ?? 0);
                if (!isset($selectedSet[$sub])) {
                    continue;
                }

                $crop = trim((string)($row['LULC'] ?? ''));
                if ($crop === '') {
                    continue;
                }

                $gis = (int)($row['HRUGIS'] ?? 0);
                if ($gis <= 0) {
                    continue;
                }

                $areaKm2 = self::toFloat($row['AREAKM2'] ?? '');
                $key = $sub . '|' . $gis . '|' . $crop;

                if (!isset($hruAreaKm2[$key]) || $areaKm2 > $hruAreaKm2[$key]['area_km2']) {
                    $hruAreaKm2[$key] = [
                        'sub' => $sub,
                        'crop' => $crop,
                        'area_km2' => $areaKm2,
                    ];
                }
            }
        } else {
            $fh = fopen($hruPath, 'rb');
            if (!$fh) {
                throw new RuntimeException("Cannot open output.hru: {$hruPath}");
            }

            while (($line = fgets($fh)) !== false) {
                $row = SwatRawImportHelper::parseHruDataLine($line);
                if ($row === null) {
                    continue;
                }

                $sub = (int)$row['SUB'];
                if (!isset($selectedSet[$sub])) {
                    continue;
                }

                $crop = trim((string)$row['LULC']);
                if ($crop === '') {
                    continue;
                }

                $gis = (int)$row['HRUGIS'];
                if ($gis <= 0) {
                    continue;
                }

                $areaKm2 = self::toFloat($row['AREAkm2']);
                $key = $sub . '|' . $gis . '|' . $crop;

                if (!isset($hruAreaKm2[$key]) || $areaKm2 > $hruAreaKm2[$key]['area_km2']) {
                    $hruAreaKm2[$key] = [
                        'sub' => $sub,
                        'crop' => $crop,
                        'area_km2' => $areaKm2,
                    ];
                }
            }

            fclose($fh);
        }

        $bySubCrop = [];
        foreach ($hruAreaKm2 as $r) {
            $sub = (int)$r['sub'];
            $crop = (string)$r['crop'];
            $bySubCrop[$sub][$crop] = ($bySubCrop[$sub][$crop] ?? 0.0) + (float)$r['area_km2'];
        }

        $rows = [];
        ksort($bySubCrop);
        foreach ($bySubCrop as $sub => $crops) {
            ksort($crops);
            foreach ($crops as $crop => $areaKm2) {
                $rows[] = [
                    'sub' => (int)$sub,
                    'crop' => (string)$crop,
                    'area_ha' => (float)$areaKm2 * 100.0,
                ];
            }
        }

        return $rows;
    }

    private static function collectIrrigationAreaContextRows(
        string $hruPath,
        array $cioMeta,
        array $selectedSet,
        string $sourceType
    ): array {
        $monthlyHru = [];

        if ($sourceType === 'csv') {
            foreach (SwatRawImportHelper::csvRowsAssocPublic($hruPath) as $row) {
                $year = (int)($row['YEAR'] ?? 0);
                $mon  = (int)($row['MON'] ?? 0);
                $sub  = (int)($row['SUB'] ?? 0);
                $crop = trim((string)($row['LULC'] ?? ''));
                $gis  = (int)($row['HRUGIS'] ?? 0);

                if ($year <= 0 || $mon < 1 || $mon > 12 || !isset($selectedSet[$sub]) || $crop === '' || $gis <= 0) {
                    continue;
                }

                $areaKm2 = self::toFloat($row['AREAKM2'] ?? '');
                $irrMm   = self::toFloat($row['IRRMM'] ?? '');

                $key = $year . '|' . $mon . '|' . $sub . '|' . $gis . '|' . $crop;

                if (!isset($monthlyHru[$key])) {
                    $monthlyHru[$key] = [
                        'year' => $year,
                        'month' => $mon,
                        'sub' => $sub,
                        'area_km2' => $areaKm2,
                        'is_irrigated' => ($irrMm > 0.0),
                    ];
                } else {
                    if ($areaKm2 > $monthlyHru[$key]['area_km2']) {
                        $monthlyHru[$key]['area_km2'] = $areaKm2;
                    }
                    if ($irrMm > 0.0) {
                        $monthlyHru[$key]['is_irrigated'] = true;
                    }
                }
            }
        } else {
            $fh = fopen($hruPath, 'rb');
            if (!$fh) {
                throw new RuntimeException("Cannot open output.hru: {$hruPath}");
            }

            $baseYear = (int)$cioMeta['printed_begin_year'];
            $monthIndex = 0;
            $firstSequenceKey = null;
            $seenAtLeastOneRow = false;

            while (($line = fgets($fh)) !== false) {
                $row = SwatRawImportHelper::parseHruDataLine($line);
                if ($row === null) {
                    continue;
                }

                [$year, $mon, $monthIndex, $firstSequenceKey, $seenAtLeastOneRow] =
                    SwatRawImportHelper::deriveHruYearMonthPublic(
                        $row,
                        $baseYear,
                        $monthIndex,
                        $firstSequenceKey,
                        $seenAtLeastOneRow
                    );

                $sub = (int)$row['SUB'];
                if (!isset($selectedSet[$sub])) {
                    continue;
                }

                $crop = trim((string)$row['LULC']);
                $gis = (int)$row['HRUGIS'];
                if ($crop === '' || $gis <= 0) {
                    continue;
                }

                $areaKm2 = self::toFloat($row['AREAkm2']);
                $irrMm = self::toFloat($row['IRRmm']);

                $key = $year . '|' . $mon . '|' . $sub . '|' . $gis . '|' . $crop;

                if (!isset($monthlyHru[$key])) {
                    $monthlyHru[$key] = [
                        'year' => $year,
                        'month' => $mon,
                        'sub' => $sub,
                        'area_km2' => $areaKm2,
                        'is_irrigated' => ($irrMm > 0.0),
                    ];
                } else {
                    if ($areaKm2 > $monthlyHru[$key]['area_km2']) {
                        $monthlyHru[$key]['area_km2'] = $areaKm2;
                    }
                    if ($irrMm > 0.0) {
                        $monthlyHru[$key]['is_irrigated'] = true;
                    }
                }
            }

            fclose($fh);
        }

        $byMonthSub = [];
        foreach ($monthlyHru as $r) {
            if (empty($r['is_irrigated'])) {
                continue;
            }

            $year = (int)$r['year'];
            $month = (int)$r['month'];
            $sub = (int)$r['sub'];

            $byMonthSub[$year][$month][$sub] = ($byMonthSub[$year][$month][$sub] ?? 0.0) + (float)$r['area_km2'];
        }

        $rows = [];
        ksort($byMonthSub);
        foreach ($byMonthSub as $year => $months) {
            ksort($months);
            foreach ($months as $month => $subs) {
                ksort($subs);
                foreach ($subs as $sub => $areaKm2) {
                    $rows[] = [
                        'year' => (int)$year,
                        'month' => (int)$month,
                        'sub' => (int)$sub,
                        'irrigated_area_ha' => (float)$areaKm2 * 100.0,
                    ];
                }
            }
        }

        return $rows;
    }

    private static function collectHruMonthly(string $path, array $cioMeta, array $selectedSet, string $sourceType): array
    {
        $monthly = [];

        if ($sourceType === 'csv') {
            foreach (SwatRawImportHelper::csvRowsAssocPublic($path) as $row) {
                $year = (int)($row['YEAR'] ?? 0);
                $mon  = (int)($row['MON'] ?? 0);
                $sub  = (int)($row['SUB'] ?? 0);
                $crop = trim((string)($row['LULC'] ?? ''));

                if ($year <= 0 || $mon < 1 || $mon > 12 || !isset($selectedSet[$sub]) || $crop === '') {
                    continue;
                }

                $key = "{$year}|{$mon}|{$sub}|{$crop}";

                if (!isset($monthly[$key])) {
                    $monthly[$key] = [
                        'year' => $year,
                        'mon' => $mon,
                        'sub' => $sub,
                        'crop' => $crop,
                        'count' => 0,
                        'no3l_sum' => 0.0,
                        'syld_sum' => 0.0,
                        'yld_sum' => 0.0,
                        'irr_sum' => 0.0,
                        'biom_c_sum' => 0.0,
                        'nue_n_sum' => 0.0,
                        'nue_n_count' => 0,
                        'nue_p_sum' => 0.0,
                        'nue_p_count' => 0,
                        'erosion_flag_sum' => 0.0,
                    ];
                }

                $monthly[$key]['count']++;

                $no3l = self::toFloat($row['NO3LKG_HA'] ?? '');
                $syld = self::toFloat($row['SYLDT_HA'] ?? '');
                $yld  = self::toFloat($row['YLDT_HA'] ?? '');
                $irr  = self::toFloat($row['IRRMM'] ?? '');
                $biom = self::toFloat($row['BIOMT_HA'] ?? '');

                $monthly[$key]['no3l_sum'] += $no3l;
                $monthly[$key]['syld_sum'] += $syld;
                $monthly[$key]['yld_sum']  += $yld;
                $monthly[$key]['irr_sum']  += $irr;
                $monthly[$key]['biom_c_sum'] += ($biom * SwatIndicatorConfig::DEFAULTS['crop_carbon_fraction']);
                $monthly[$key]['erosion_flag_sum'] += ($syld > SwatIndicatorConfig::DEFAULTS['soil_erosion_area_threshold_syld_t_ha']) ? 1.0 : 0.0;

                $nDen =
                    self::toFloat($row['N_APPKG_HA'] ?? '') +
                    self::toFloat($row['N_AUTOKG_HA'] ?? '') +
                    self::toFloat($row['NGRZKG_HA'] ?? '') +
                    self::toFloat($row['NCFRTKG_HA'] ?? '');

                if ($nDen > 0.0) {
                    $monthly[$key]['nue_n_sum'] += ($yld / $nDen) * 100.0;
                    $monthly[$key]['nue_n_count']++;
                }

                $pDen =
                    self::toFloat($row['P_APPKG_HA'] ?? '') +
                    self::toFloat($row['P_AUTOKG_HA'] ?? '') +
                    self::toFloat($row['PGRZKG_HA'] ?? '') +
                    self::toFloat($row['PCFRTKG_HA'] ?? '');

                if ($pDen > 0.0) {
                    $monthly[$key]['nue_p_sum'] += ($yld / $pDen) * 100.0;
                    $monthly[$key]['nue_p_count']++;
                }
            }
        } else {
            $fh = fopen($path, 'rb');
            if (!$fh) {
                throw new RuntimeException("Cannot open output.hru: {$path}");
            }

            $baseYear = (int)$cioMeta['printed_begin_year'];
            $monthIndex = 0;
            $firstSequenceKey = null;
            $seenAtLeastOneRow = false;

            while (($line = fgets($fh)) !== false) {
                $row = SwatRawImportHelper::parseHruDataLine($line);
                if ($row === null) {
                    continue;
                }

                [$year, $mon, $monthIndex, $firstSequenceKey, $seenAtLeastOneRow] =
                    SwatRawImportHelper::deriveHruYearMonthPublic(
                        $row,
                        $baseYear,
                        $monthIndex,
                        $firstSequenceKey,
                        $seenAtLeastOneRow
                    );

                $sub = (int)$row['SUB'];
                if (!isset($selectedSet[$sub])) {
                    continue;
                }

                $crop = (string)$row['LULC'];
                $key = "{$year}|{$mon}|{$sub}|{$crop}";

                if (!isset($monthly[$key])) {
                    $monthly[$key] = [
                        'year' => $year,
                        'mon' => $mon,
                        'sub' => $sub,
                        'crop' => $crop,
                        'count' => 0,
                        'no3l_sum' => 0.0,
                        'syld_sum' => 0.0,
                        'yld_sum' => 0.0,
                        'irr_sum' => 0.0,
                        'biom_c_sum' => 0.0,
                        'nue_n_sum' => 0.0,
                        'nue_n_count' => 0,
                        'nue_p_sum' => 0.0,
                        'nue_p_count' => 0,
                        'erosion_flag_sum' => 0.0,
                    ];
                }

                $monthly[$key]['count']++;

                $no3l = self::toFloat($row['NO3Lkg_ha']);
                $syld = self::toFloat($row['SYLDt_ha']);
                $yld  = self::toFloat($row['YLDt_ha']);
                $irr  = self::toFloat($row['IRRmm']);
                $biom = self::toFloat($row['BIOMt_ha']);

                $monthly[$key]['no3l_sum'] += $no3l;
                $monthly[$key]['syld_sum'] += $syld;
                $monthly[$key]['yld_sum']  += $yld;
                $monthly[$key]['irr_sum']  += $irr;
                $monthly[$key]['biom_c_sum'] += ($biom * SwatIndicatorConfig::DEFAULTS['crop_carbon_fraction']);
                $monthly[$key]['erosion_flag_sum'] += ($syld > SwatIndicatorConfig::DEFAULTS['soil_erosion_area_threshold_syld_t_ha']) ? 1.0 : 0.0;

                $nDen =
                    self::toFloat($row['N_APPkg_ha']) +
                    self::toFloat($row['N_AUTOkg_ha']) +
                    self::toFloat($row['NGRZkg_ha']) +
                    self::toFloat($row['NCFRTkg_ha']);

                if ($nDen > 0.0) {
                    $monthly[$key]['nue_n_sum'] += ($yld / $nDen) * 100.0;
                    $monthly[$key]['nue_n_count']++;
                }

                $pDen =
                    self::toFloat($row['P_APPkg_ha']) +
                    self::toFloat($row['P_AUTOkg_ha']) +
                    self::toFloat($row['PGRZkg_ha']) +
                    self::toFloat($row['PCFRTkg_ha']);

                if ($pDen > 0.0) {
                    $monthly[$key]['nue_p_sum'] += ($yld / $pDen) * 100.0;
                    $monthly[$key]['nue_p_count']++;
                }
            }

            fclose($fh);
        }

        foreach ($monthly as &$m) {
            $count = max(1, (int)$m['count']);
            $m['gw_no3_kg_ha'] = $m['no3l_sum'] / $count;
            $m['soil_erosion_t_ha'] = $m['syld_sum'] / $count;
            $m['crop_yield_t_ha'] = $m['yld_sum'] / $count;
            $m['irr_mm'] = $m['irr_sum'] / $count;
            $m['crop_c_seq_t_ha'] = $m['biom_c_sum'] / $count;
            $m['soil_erosion_area'] = (($m['erosion_flag_sum'] / $count) > 0.5) ? 1.0 : 0.0;
            $m['nue_n_pct'] = $m['nue_n_count'] > 0 ? ($m['nue_n_sum'] / $m['nue_n_count']) : null;
            $m['nue_p_pct'] = $m['nue_p_count'] > 0 ? ($m['nue_p_sum'] / $m['nue_p_count']) : null;
        }
        unset($m);

        return $monthly;
    }

    private static function buildHruYearlyIndicators(array $monthly): array
    {
        $yearly = [];

        foreach ($monthly as $m) {
            $base = "{$m['year']}|{$m['sub']}|{$m['crop']}";
            self::pushYearlyAvg($yearly, 'gw_no3_kg_ha', $base, $m['year'], $m['sub'], $m['crop'], $m['gw_no3_kg_ha']);
            self::pushYearlySum($yearly, 'soil_erosion_t_ha', $base, $m['year'], $m['sub'], $m['crop'], $m['soil_erosion_t_ha']);
            self::pushYearlyMax($yearly, 'crop_yield_t_ha', $base, $m['year'], $m['sub'], $m['crop'], $m['crop_yield_t_ha']);
            self::pushYearlyAvg($yearly, 'nue_n_pct', $base, $m['year'], $m['sub'], $m['crop'], $m['nue_n_pct']);
            self::pushYearlyAvg($yearly, 'nue_p_pct', $base, $m['year'], $m['sub'], $m['crop'], $m['nue_p_pct']);
            self::pushYearlySum($yearly, 'irr_mm', $base, $m['year'], $m['sub'], $m['crop'], $m['irr_mm']);
            self::pushYearlyMax($yearly, 'crop_c_seq_t_ha', $base, $m['year'], $m['sub'], $m['crop'], $m['crop_c_seq_t_ha']);
            self::pushYearlyModeBool($yearly, 'soil_erosion_area', $base, $m['year'], $m['sub'], $m['crop'], $m['soil_erosion_area']);
        }

        return self::finalizeYearlyBuckets($yearly);
    }

    private static function collectRchMonthly(string $path, array $cioMeta, array $selectedSet, string $sourceType): array
    {
        $monthly = [];

        if ($sourceType === 'csv') {
            foreach (SwatRawImportHelper::csvRowsAssocPublic($path) as $row) {
                $year = (int)($row['YEAR'] ?? 0);
                $mon  = (int)($row['MON'] ?? 0);
                $sub  = (int)($row['SUB'] ?? 0);

                if ($year <= 0 || $mon < 1 || $mon > 12 || !isset($selectedSet[$sub])) {
                    continue;
                }

                $key = "{$year}|{$mon}|{$sub}";
                if (!isset($monthly[$key])) {
                    $monthly[$key] = [
                        'year' => $year,
                        'mon' => $mon,
                        'sub' => $sub,
                        'count' => 0,
                        'sw_no3_sum' => 0.0,
                        'sw_no3_count' => 0,
                    ];
                }

                $flow = self::toFloat($row['FLOW_OUTCMS'] ?? '');
                $no3  = self::toFloat($row['NO3_OUTKG'] ?? '');

                if ($flow > 0.0) {
                    $monthly[$key]['sw_no3_sum'] += ($no3 / ($flow * SwatIndicatorConfig::DEFAULTS['rch_flow_seconds_per_month_equiv'])) * 1000.0;
                    $monthly[$key]['sw_no3_count']++;
                }

                $monthly[$key]['count']++;
            }
        } else {
            $fh = fopen($path, 'rb');
            if (!$fh) {
                throw new RuntimeException("Cannot open output.rch: {$path}");
            }

            $year = (int)$cioMeta['printed_begin_year'];
            $prevMon = null;

            while (($line = fgets($fh)) !== false) {
                $row = SwatRawImportHelper::parseRchDataLine($line);
                if ($row === null) {
                    continue;
                }

                $mon = (int)$row['MON'];
                if ($prevMon !== null && $mon < $prevMon) {
                    $year++;
                }
                $prevMon = $mon;

                $sub = (int)$row['SUB'];
                if (!isset($selectedSet[$sub])) {
                    continue;
                }

                $key = "{$year}|{$mon}|{$sub}";
                if (!isset($monthly[$key])) {
                    $monthly[$key] = [
                        'year' => $year,
                        'mon' => $mon,
                        'sub' => $sub,
                        'count' => 0,
                        'sw_no3_sum' => 0.0,
                        'sw_no3_count' => 0,
                    ];
                }

                $flow = self::toFloat($row['FLOW_OUTcms']);
                $no3  = self::toFloat($row['NO3_OUTkg']);

                if ($flow > 0.0) {
                    $monthly[$key]['sw_no3_sum'] += ($no3 / ($flow * SwatIndicatorConfig::DEFAULTS['rch_flow_seconds_per_month_equiv'])) * 1000.0;
                    $monthly[$key]['sw_no3_count']++;
                }

                $monthly[$key]['count']++;
            }

            fclose($fh);
        }

        foreach ($monthly as &$m) {
            $m['sw_no3_kg_m3'] = $m['sw_no3_count'] > 0
                ? ($m['sw_no3_sum'] / $m['sw_no3_count'])
                : null;
        }
        unset($m);

        return $monthly;
    }

    private static function buildRchYearlyIndicators(array $monthly): array
    {
        $yearly = [];

        foreach ($monthly as $m) {
            $base = "{$m['year']}|{$m['sub']}";
            self::pushYearlyAvg($yearly, 'sw_no3_kg_m3', $base, $m['year'], $m['sub'], '', $m['sw_no3_kg_m3']);
        }

        return self::finalizeYearlyBuckets($yearly);
    }

    private static function collectSnuMonthly(string $snuPath, string $hruPath, array $cioMeta, array $selectedSet, string $sourceType): array
    {
        $gisToSub = self::buildHruGisToSubMap($hruPath, $selectedSet, $sourceType);
        $monthly = [];

        if ($sourceType === 'csv') {
            foreach (SwatRawImportHelper::csvRowsAssocPublic($snuPath) as $row) {
                $year = (int)($row['YEAR'] ?? 0);
                $day  = (int)($row['DAY'] ?? 0);
                $gis  = (int)($row['HRUGIS'] ?? 0);

                if ($year <= 0 || $day <= 0 || $gis <= 0) {
                    continue;
                }

                $sub = $gisToSub[$gis] ?? (int)floor($gis / 10000);
                if (!isset($selectedSet[$sub])) {
                    continue;
                }

                $date = new DateTimeImmutable(sprintf('%04d-01-01', $year));
                $date = $date->modify('+' . ($day - 1) . ' days');

                $monthStart = $date->format('Y-m-01');
                $monthEnd = $date->format('Y-m-t');

                if ($date->format('Y-m-d') !== $monthEnd) {
                    continue;
                }

                $key = "{$year}|{$monthStart}|{$sub}";
                if (!isset($monthly[$key])) {
                    $monthly[$key] = [
                        'year' => $year,
                        'sub' => $sub,
                        'month_start' => $monthStart,
                        'count' => 0,
                        'soil_org_c_sum' => 0.0,
                        'soil_fert_soc_sum' => 0.0,
                        'soil_fert_n_sum' => 0.0,
                        'soil_fert_sol_p_sum' => 0.0,
                        'soil_fert_org_p_sum' => 0.0,
                    ];
                }

                $monthly[$key]['count']++;
                $monthly[$key]['soil_org_c_sum'] += self::toFloat($row['ORG_N'] ?? '') * SwatIndicatorConfig::DEFAULTS['soil_org_n_to_carbon_factor'];
                $monthly[$key]['soil_fert_soc_sum'] += self::toFloat($row['NO3'] ?? '');
                $monthly[$key]['soil_fert_n_sum'] += self::toFloat($row['ORG_N'] ?? '');
                $monthly[$key]['soil_fert_sol_p_sum'] += self::toFloat($row['SOL_P'] ?? '');
                $monthly[$key]['soil_fert_org_p_sum'] += self::toFloat($row['ORG_P'] ?? '');
            }
        } else {
            $fh = fopen($snuPath, 'rb');
            if (!$fh) {
                throw new RuntimeException("Cannot open output.snu: {$snuPath}");
            }

            $year = (int)$cioMeta['printed_begin_year'];
            $prevDay = null;

            while (($line = fgets($fh)) !== false) {
                $row = SwatRawImportHelper::parseSnuDataLine($line);
                if ($row === null) {
                    continue;
                }

                $day = (int)$row['DAY'];
                if ($prevDay !== null && $day < $prevDay) {
                    $year++;
                }
                $prevDay = $day;

                $gis = (int)$row['HRUGIS'];
                $sub = $gisToSub[$gis] ?? (int)floor($gis / 10000);

                if (!isset($selectedSet[$sub])) {
                    continue;
                }

                $date = new DateTimeImmutable(sprintf('%04d-01-01', $year));
                $date = $date->modify('+' . ($day - 1) . ' days');

                $monthStart = $date->format('Y-m-01');
                $monthEnd = $date->format('Y-m-t');

                if ($date->format('Y-m-d') !== $monthEnd) {
                    continue;
                }

                $key = "{$year}|{$monthStart}|{$sub}";
                if (!isset($monthly[$key])) {
                    $monthly[$key] = [
                        'year' => $year,
                        'sub' => $sub,
                        'month_start' => $monthStart,
                        'count' => 0,
                        'soil_org_c_sum' => 0.0,
                        'soil_fert_soc_sum' => 0.0,
                        'soil_fert_n_sum' => 0.0,
                        'soil_fert_sol_p_sum' => 0.0,
                        'soil_fert_org_p_sum' => 0.0,
                    ];
                }

                $monthly[$key]['count']++;
                $monthly[$key]['soil_org_c_sum'] += self::toFloat($row['ORG_N']) * SwatIndicatorConfig::DEFAULTS['soil_org_n_to_carbon_factor'];
                $monthly[$key]['soil_fert_soc_sum'] += self::toFloat($row['NO3']);
                $monthly[$key]['soil_fert_n_sum'] += self::toFloat($row['ORG_N']);
                $monthly[$key]['soil_fert_sol_p_sum'] += self::toFloat($row['SOL_P']);
                $monthly[$key]['soil_fert_org_p_sum'] += self::toFloat($row['ORG_P']);
            }

            fclose($fh);
        }

        foreach ($monthly as &$m) {
            $count = max(1, (int)$m['count']);
            $m['soil_org_c_kg_ha'] = $m['soil_org_c_sum'] / $count;
            $m['soil_fert_soc_kg_ha'] = $m['soil_fert_soc_sum'] / $count;
            $m['soil_fert_n_kg_ha'] = $m['soil_fert_n_sum'] / $count;
            $m['soil_fert_sol_p_kg_ha'] = $m['soil_fert_sol_p_sum'] / $count;
            $m['soil_fert_org_p_kg_ha'] = $m['soil_fert_org_p_sum'] / $count;
        }
        unset($m);

        return $monthly;
    }

    private static function buildSnuYearlyIndicators(array $monthly): array
    {
        $yearly = [];

        foreach ($monthly as $m) {
            $base = "{$m['year']}|{$m['sub']}";
            self::pushYearlyAvg($yearly, 'soil_org_c_kg_ha', $base, $m['year'], $m['sub'], '', $m['soil_org_c_kg_ha']);
            self::pushYearlyAvg($yearly, 'soil_fert_soc_kg_ha', $base, $m['year'], $m['sub'], '', $m['soil_fert_soc_kg_ha']);
            self::pushYearlyAvg($yearly, 'soil_fert_n_kg_ha', $base, $m['year'], $m['sub'], '', $m['soil_fert_n_kg_ha']);
            self::pushYearlyAvg($yearly, 'soil_fert_sol_p_kg_ha', $base, $m['year'], $m['sub'], '', $m['soil_fert_sol_p_kg_ha']);
            self::pushYearlyAvg($yearly, 'soil_fert_org_p_kg_ha', $base, $m['year'], $m['sub'], '', $m['soil_fert_org_p_kg_ha']);
        }

        return self::finalizeYearlyBuckets($yearly);
    }

    private static function buildHruGisToSubMap(string $hruPath, array $selectedSet, string $sourceType): array
    {
        $map = [];

        if ($sourceType === 'csv') {
            foreach (SwatRawImportHelper::csvRowsAssocPublic($hruPath) as $row) {
                $sub = (int)($row['SUB'] ?? 0);
                if (!isset($selectedSet[$sub])) {
                    continue;
                }

                $gis = (int)($row['HRUGIS'] ?? 0);
                if ($gis > 0 && !isset($map[$gis])) {
                    $map[$gis] = $sub;
                }
            }

            return $map;
        }

        $fh = fopen($hruPath, 'rb');
        if (!$fh) {
            throw new RuntimeException("Cannot open output.hru: {$hruPath}");
        }

        while (($line = fgets($fh)) !== false) {
            $row = SwatRawImportHelper::parseHruDataLine($line);
            if ($row === null) {
                continue;
            }

            $sub = (int)$row['SUB'];
            if (!isset($selectedSet[$sub])) {
                continue;
            }

            $gis = (int)$row['HRUGIS'];
            if ($gis > 0 && !isset($map[$gis])) {
                $map[$gis] = $sub;
            }
        }

        fclose($fh);
        return $map;
    }

    private static function pushYearlyAvg(array &$yearly, string $indicator, string $base, int $year, int $sub, string $crop, ?float $value): void
    {
        if ($value === null) return;
        $k = "{$indicator}|{$base}";
        if (!isset($yearly[$k])) {
            $yearly[$k] = ['indicator_code' => $indicator, 'year' => $year, 'sub' => $sub, 'crop' => $crop, 'sum' => 0.0, 'count' => 0, 'mode_1' => 0, 'mode_0' => 0, 'max' => null];
        }
        $yearly[$k]['sum'] += $value;
        $yearly[$k]['count']++;
    }

    private static function pushYearlySum(array &$yearly, string $indicator, string $base, int $year, int $sub, string $crop, ?float $value): void
    {
        if ($value === null) return;
        $k = "{$indicator}|{$base}";
        if (!isset($yearly[$k])) {
            $yearly[$k] = ['indicator_code' => $indicator, 'year' => $year, 'sub' => $sub, 'crop' => $crop, 'sum' => 0.0, 'count' => 0, 'mode_1' => 0, 'mode_0' => 0, 'max' => null, '_mode' => 'sum'];
        }
        $yearly[$k]['sum'] += $value;
    }

    private static function pushYearlyMax(array &$yearly, string $indicator, string $base, int $year, int $sub, string $crop, ?float $value): void
    {
        if ($value === null) return;
        $k = "{$indicator}|{$base}";
        if (!isset($yearly[$k])) {
            $yearly[$k] = ['indicator_code' => $indicator, 'year' => $year, 'sub' => $sub, 'crop' => $crop, 'sum' => 0.0, 'count' => 0, 'mode_1' => 0, 'mode_0' => 0, 'max' => $value, '_mode' => 'max'];
            return;
        }
        if ($yearly[$k]['max'] === null || $value > $yearly[$k]['max']) {
            $yearly[$k]['max'] = $value;
        }
    }

    private static function pushYearlyModeBool(array &$yearly, string $indicator, string $base, int $year, int $sub, string $crop, ?float $value): void
    {
        if ($value === null) return;
        $k = "{$indicator}|{$base}";
        if (!isset($yearly[$k])) {
            $yearly[$k] = ['indicator_code' => $indicator, 'year' => $year, 'sub' => $sub, 'crop' => $crop, 'sum' => 0.0, 'count' => 0, 'mode_1' => 0, 'mode_0' => 0, 'max' => null, '_mode' => 'mode_bool'];
        }
        if ((int)round($value) === 1) {
            $yearly[$k]['mode_1']++;
        } else {
            $yearly[$k]['mode_0']++;
        }
    }

    private static function finalizeYearlyBuckets(array $yearly): array
    {
        $rows = [];

        foreach ($yearly as $bucket) {
            $mode = $bucket['_mode'] ?? 'avg';

            if ($mode === 'sum') {
                $value = $bucket['sum'];
            } elseif ($mode === 'max') {
                $value = $bucket['max'];
            } elseif ($mode === 'mode_bool') {
                $value = ($bucket['mode_1'] > $bucket['mode_0']) ? 1.0 : 0.0;
            } else {
                $value = $bucket['count'] > 0 ? ($bucket['sum'] / $bucket['count']) : null;
            }

            $rows[] = [
                'indicator_code' => $bucket['indicator_code'],
                'year' => (int)$bucket['year'],
                'sub' => (int)$bucket['sub'],
                'crop' => (string)$bucket['crop'],
                'value' => $value,
            ];
        }

        return $rows;
    }

    private static function toFloat(?string $value): float
    {
        $value = trim((string)$value);
        if ($value === '') {
            return 0.0;
        }

        // 12,34 -> 12.34
        if (preg_match('/^-?\d+,\d+(?:e[+-]?\d+)?$/i', $value)) {
            $value = str_replace(',', '.', $value);
        }
        // 1.234,56 -> 1234.56
        elseif (preg_match('/^-?\d{1,3}(?:\.\d{3})*,\d+(?:e[+-]?\d+)?$/i', $value)) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        return (float)$value;
    }
}