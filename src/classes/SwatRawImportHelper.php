<?php
// classes/SwatRawImportHelper.php

declare(strict_types=1);

final class SwatRawImportHelper
{
    public static function saveUploadedFile(array $file, string $targetPath): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed');
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Invalid uploaded file');
        }

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new RuntimeException('Failed to move uploaded file');
        }
    }

    public static function parseCioMetadata(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("file.cio not found: {$path}");
        }

        $meta = [
            'simulation_years'      => null,
            'begin_year'            => null,
            'begin_julian_day'      => null,
            'end_julian_day'        => null,
            'skip_years'            => 0,
            'icalen'                => null,
            'printed_begin_year'    => null,
            'printed_begin_date'    => null,
        ];

        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new RuntimeException("Cannot open file.cio: {$path}");
        }

        while (($line = fgets($fh)) !== false) {
            if (preg_match('/^\s*(-?\d+)\s+\|\s+NBYR\s*:/i', $line, $m)) {
                $meta['simulation_years'] = (int)$m[1];
            } elseif (preg_match('/^\s*(-?\d+)\s+\|\s+IYR\s*:/i', $line, $m)) {
                $meta['begin_year'] = (int)$m[1];
            } elseif (preg_match('/^\s*(-?\d+)\s+\|\s+IDAF\s*:/i', $line, $m)) {
                $meta['begin_julian_day'] = (int)$m[1];
            } elseif (preg_match('/^\s*(-?\d+)\s+\|\s+IDAL\s*:/i', $line, $m)) {
                $meta['end_julian_day'] = (int)$m[1];
            } elseif (preg_match('/^\s*(-?\d+)\s+\|\s+NYSKIP\s*:/i', $line, $m)) {
                $meta['skip_years'] = (int)$m[1];
            } elseif (preg_match('/^\s*(-?\d+)\s+\|\s+ICALEN\s*:/i', $line, $m)) {
                $meta['icalen'] = (int)$m[1];
            }
        }
        fclose($fh);

        if (!$meta['begin_year']) {
            throw new RuntimeException('Could not determine IYR from file.cio.');
        }

        $printedBeginYear = (int)$meta['begin_year'] + (int)$meta['skip_years'];
        $meta['printed_begin_year'] = $printedBeginYear;

        $startDoy = max(1, (int)($meta['begin_julian_day'] ?? 1));
        $dt = new DateTimeImmutable(sprintf('%04d-01-01', $printedBeginYear));
        $meta['printed_begin_date'] = $dt->modify('+' . ($startDoy - 1) . ' days')->format('Y-m-d');

        return $meta;
    }

    public static function inspectRawSet(string $cioPath, ?string $hruPath, ?string $rchPath, ?string $snuPath): array
    {
        $cio = self::parseCioMetadata($cioPath);

        $inspections = [];
        $allCropCodes = [];
        $allSubbasins = [];
        $periodStart = null;
        $periodEnd = null;

        if ($hruPath && is_file($hruPath)) {
            $info = self::inspectHru($hruPath, $cio);
            $inspections['hru'] = $info;
            foreach ($info['crop_codes'] as $code) {
                $allCropCodes[$code] = true;
            }
            foreach ($info['detected_subbasins'] as $sub) {
                $allSubbasins[$sub] = true;
            }
            $periodStart = self::minDate($periodStart, $info['period_start_guess']);
            $periodEnd   = self::maxDate($periodEnd, $info['period_end_guess']);
        }

        if ($rchPath && is_file($rchPath)) {
            $info = self::inspectRch($rchPath, $cio);
            $inspections['rch'] = $info;
            foreach ($info['detected_subbasins'] as $sub) {
                $allSubbasins[$sub] = true;
            }
            $periodStart = self::minDate($periodStart, $info['period_start_guess']);
            $periodEnd   = self::maxDate($periodEnd, $info['period_end_guess']);
        }

        if ($snuPath && is_file($snuPath)) {
            $info = self::inspectSnu($snuPath, $cio);
            $inspections['snu'] = $info;
            $periodStart = self::minDate($periodStart, $info['period_start_guess']);
            $periodEnd   = self::maxDate($periodEnd, $info['period_end_guess']);
        }

        return [
            'cio' => $cio,
            'inspections' => $inspections,
            'all_crop_codes' => array_values(array_keys($allCropCodes)),
            'all_subbasins' => array_map('intval', array_values(array_keys($allSubbasins))),
            'period_start_guess' => $periodStart,
            'period_end_guess' => $periodEnd,
        ];
    }

    public static function inspectHru(string $path, array $cio): array
    {
        $previewRows = [];
        $cropCodes = [];
        $subbasins = [];
        $rowCount = 0;

        $baseYear = (int)$cio['printed_begin_year'];
        $monthIndex = 0;
        $firstSequenceKey = null;
        $seenAtLeastOneRow = false;

        $minDate = null;
        $maxDate = null;

        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new RuntimeException("Cannot open output.hru: {$path}");
        }

        while (($line = fgets($fh)) !== false) {
            $row = self::parseHruLine($line);
            if ($row === null) {
                continue;
            }

            [$year, $mon, $monthIndex, $firstSequenceKey, $seenAtLeastOneRow] =
                self::deriveHruYearMonth(
                    $row,
                    $baseYear,
                    $monthIndex,
                    $firstSequenceKey,
                    $seenAtLeastOneRow
                );

            $row['YEAR'] = $year;
            $row['MON']  = $mon;

            $rowCount++;
            $cropCodes[strtoupper((string)$row['LULC'])] = true;
            $subbasins[(int)$row['SUB']] = true;

            $date = sprintf('%04d-%02d-01', $year, $mon);
            $minDate = self::minDate($minDate, $date);
            $maxDate = self::maxDate($maxDate, $date);

            if (count($previewRows) < 5) {
                $previewRows[] = [
                    $row['LULC'],
                    $row['HRU'],
                    $row['HRUGIS'],
                    $row['SUB'],
                    $row['YEAR'],
                    $row['MON'],
                    $row['AREAkm2'],
                    $row['IRRmm'],
                    $row['SA_IRRmm'],
                    $row['DA_IRRmm'],
                    $row['YLDt_ha'],
                    $row['BIOMt_ha'],
                    $row['SYLDt_ha'],
                ];
            }
        }

        fclose($fh);

        $header = [
            'LULC','HRU','HRUGIS','SUB','YEAR','MON',
            'AREAkm2','IRRmm','SA_IRRmm','DA_IRRmm','YLDt_ha','BIOMt_ha','SYLDt_ha'
        ];

        return [
            'type' => 'hru',
            'header' => $header,
            'preview_rows' => $previewRows,
            'preview_html' => self::buildPreviewTable($header, $previewRows),
            'row_count' => $rowCount,
            'crop_codes' => array_values(array_keys($cropCodes)),
            'detected_subbasins' => array_map('intval', array_values(array_keys($subbasins))),
            'period_start_guess' => $minDate,
            'period_end_guess' => $maxDate,
            'ok' => $rowCount > 0,
        ];
    }

    public static function inspectRch(string $path, array $cio): array
    {
        $previewRows = [];
        $subbasins = [];
        $rowCount = 0;

        $year = (int)$cio['printed_begin_year'];
        $prevMon = null;
        $minDate = null;
        $maxDate = null;

        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new RuntimeException("Cannot open output.rch: {$path}");
        }

        while (($line = fgets($fh)) !== false) {
            $row = self::parseRchLine($line);
            if ($row === null) {
                continue;
            }

            $mon = (int)$row['MON'];

            if ($prevMon !== null && $mon < $prevMon) {
                $year++;
            }
            $prevMon = $mon;
            $row['YEAR'] = $year;

            $rowCount++;
            $subbasins[(int)$row['SUB']] = true;

            $date = sprintf('%04d-%02d-01', $year, $mon);
            $minDate = self::minDate($minDate, $date);
            $maxDate = self::maxDate($maxDate, $date);

            if (count($previewRows) < 5) {
                $previewRows[] = [
                    $row['SUB'], $row['YEAR'], $row['MON'], $row['AREAkm2'],
                    $row['FLOW_OUTcms'], $row['NO3_OUTkg'], $row['SED_OUTtons']
                ];
            }
        }

        fclose($fh);

        $header = ['SUB','YEAR','MON','AREAkm2','FLOW_OUTcms','NO3_OUTkg','SED_OUTtons'];

        return [
            'type' => 'rch',
            'header' => $header,
            'preview_rows' => $previewRows,
            'preview_html' => self::buildPreviewTable($header, $previewRows),
            'row_count' => $rowCount,
            'crop_codes' => [],
            'detected_subbasins' => array_map('intval', array_values(array_keys($subbasins))),
            'period_start_guess' => $minDate,
            'period_end_guess' => $maxDate,
            'ok' => $rowCount > 0,
        ];
    }

    public static function inspectSnu(string $path, array $cio): array
    {
        $previewRows = [];
        $rowCount = 0;

        $year = (int)$cio['printed_begin_year'];
        $prevDay = null;
        $minDate = null;
        $maxDate = null;

        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new RuntimeException("Cannot open output.snu: {$path}");
        }

        while (($line = fgets($fh)) !== false) {
            $row = self::parseSnuLine($line);
            if ($row === null) {
                continue;
            }

            $day = (int)$row['DAY'];
            if ($prevDay !== null && $day < $prevDay) {
                $year++;
            }
            $prevDay = $day;
            $row['YEAR'] = $year;

            $rowCount++;

            $date = self::dateFromYearDoy($year, $day);
            $minDate = self::minDate($minDate, $date);
            $maxDate = self::maxDate($maxDate, $date);

            if (count($previewRows) < 5) {
                $previewRows[] = [
                    $row['YEAR'], $row['DAY'], $row['HRUGIS'], $row['SOL_RSD'],
                    $row['SOL_P'], $row['NO3'], $row['ORG_N'], $row['ORG_P'], $row['CN']
                ];
            }
        }

        fclose($fh);

        $header = ['YEAR','DAY','HRUGIS','SOL_RSD','SOL_P','NO3','ORG_N','ORG_P','CN'];

        return [
            'type' => 'snu',
            'header' => $header,
            'preview_rows' => $previewRows,
            'preview_html' => self::buildPreviewTable($header, $previewRows),
            'row_count' => $rowCount,
            'crop_codes' => [],
            'detected_subbasins' => [],
            'period_start_guess' => $minDate,
            'period_end_guess' => $maxDate,
            'ok' => $rowCount > 0,
        ];
    }

    public static function buildPreviewTable(array $header, array $rows): string
    {
        $html = '<table class="table table-sm table-bordered table-hover mb-0"><thead><tr>';
        foreach ($header as $cell) {
            $html .= '<th>' . htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    private static function parseHruLine(string $line): ?array
    {
        $line = rtrim($line, "\r\n");
        $trim = trim($line);
        if ($trim === '' || self::isLikelyHruHeaderLine($line)) {
            return null;
        }

        $spec = [
            'LULC'        => [1, 4],
            'HRU'         => [7, 9],
            'HRUGIS'      => [11, 19],
            'SUB'         => [22, 24],
            'MGT'         => [27, 29],
            'AREAkm2'     => [34, 44],
            'PRECIPmm'    => [47, 54],
            'SNOWFALLmm'  => [56, 64],
            'SNOWMELTmm'  => [66, 74],
            'IRRmm'       => [77, 84],
            'PETmm'       => [87, 94],
            'ETmm'        => [97, 104],
            'SW_INITmm'   => [106, 114],
            'SW_ENDmm'    => [117, 124],
            'PERCmm'      => [127, 134],
            'GW_RCHGmm'   => [136, 144],
            'DA_RCHGmm'   => [146, 154],
            'REVAPmm'     => [157, 164],
            'SA_IRRmm'    => [167, 174],
            'DA_IRRmm'    => [177, 184],
            'SA_STmm'     => [187, 194],
            'DA_STmm'     => [197, 204],
            'SURQ_GENmm'  => [207, 214],
            'SURQ_CNTmm'  => [216, 224],
            'TLOSS_mm'    => [228, 234],
            'LATQ_mm'     => [236, 244],
            'GW_Qmm'      => [247, 254],
            'WYLD_Qmm'    => [257, 264],
            'DAILYCN'     => [267, 274],
            'TMP_AVdgC'   => [276, 284],
            'TMP_MXdgC'   => [286, 294],
            'TMP_MNdgC'   => [296, 304],
            'SOL_TMPdgC'  => [307, 314],
            'SOLARmj_m2'  => [317, 324],
            'SYLDt_ha'    => [327, 334],
            'USLEt_ha'    => [337, 344],
            'N_APPkg_ha'  => [347, 354],
            'P_APPkg_ha'  => [357, 364],
            'N_AUTOkg_ha' => [367, 374],
            'P_AUTOkg_ha' => [377, 384],
            'NGRZkg_ha'   => [386, 394],
            'PGRZkg_ha'   => [396, 404],
            'NCFRTkg_ha'  => [407, 414],
            'PCFRTkg_ha'  => [417, 424],
            'NRAINkg_ha'  => [427, 434],
            'NFIXkg_ha'   => [436, 444],
            'F_MNkg_ha'   => [446, 454],
            'A_MNkg_ha'   => [456, 464],
            'A_SNkg_ha'   => [466, 474],
            'F_MPkg_aha'  => [476, 484],
            'AO_LPkg_ha'  => [487, 494],
            'L_APkg_ha'   => [496, 504],
            'A_SPkg_ha'   => [506, 514],
            'DNITkg_ha'   => [516, 524],
            'NUP_kg_ha'   => [527, 534],
            'PUPkg_ha'    => [537, 544],
            'ORGNkg_ha'   => [546, 554],
            'ORGPkg_ha'   => [556, 564],
            'SEDPkg_h'    => [566, 574],
            'NSURQkg_ha'  => [576, 584],
            'NLATQkg_ha'  => [586, 594],
            'NO3Lkg_ha'   => [596, 604],
            'NO3GWkg_ha'  => [606, 614],
            'SOLPkg_ha'   => [616, 624],
            'P_GWkg_ha'   => [626, 634],
            'W_STRS'      => [637, 644],
            'TMP_STRS'    => [647, 654],
            'N_STRS'      => [657, 664],
            'P_STRS'      => [667, 674],
            'BIOMt_ha'    => [677, 684],
            'LAI'         => [687, 694],
            'YLDt_ha'     => [697, 704],
            'BACTPct'     => [706, 715],
            'BACTLPct'    => [717, 726],
            'WATB_CLI'    => [728, 736],
            'WATB_SOL'    => [738, 746],
            'SNOmm'       => [748, 756],
            'CMUPkg_ha'   => [758, 766],
            'CMTOTkg_ha'  => [768, 776],
            'QTILEmm'     => [778, 786],
            'TNO3kg_ha'   => [788, 796],
            'LNO3kg_ha'   => [798, 806],
            'GW_Q_Dmm'    => [808, 816],
            'LATQCNTmm'   => [818, 826],
            'TVAPkg_ha'   => [828, 836],
        ];

        $row = self::parseFixedWidthLine($line, $spec);
        if ($row === null) {
            return null;
        }

        if (!preg_match('/^[A-Z0-9_]{2,16}$/', (string)$row['LULC'])) {
            return null;
        }
        if (!ctype_digit((string)$row['HRU']) || !ctype_digit((string)$row['HRUGIS']) || !ctype_digit((string)$row['SUB'])) {
            return null;
        }

        return $row;
    }

    private static function parseRchLine(string $line): ?array
    {
        $line = rtrim($line, "\r\n");
        $trim = trim($line);
        if ($trim === '' || self::isLikelyRchHeaderLine($line)) {
            return null;
        }

        if (stripos(ltrim($line), 'REACH') !== 0) {
            return null;
        }

        $spec = [
            'SUB'           => [8, 11],
            'GIS'           => [14, 20],
            'MON'           => [23, 26],
            'AREAkm2'       => [29, 38],
            'FLOW_INcms'    => [41, 50],
            'FLOW_OUTcms'   => [53, 62],
            'EVAPcms'       => [65, 74],
            'TLOSScms'      => [77, 86],
            'SED_INtons'    => [89, 98],
            'SED_OUTtons'   => [101, 110],
            'SEDCONCmg_kg'  => [113, 122],
            'ORGN_INkg'     => [125, 134],
            'ORGN_OUTkg'    => [137, 146],
            'ORGP_INkg'     => [149, 158],
            'ORGP_OUTkg'    => [161, 170],
            'NO3_INkg'      => [173, 182],
            'NO3_OUTkg'     => [185, 194],
            'NH4_INkg'      => [197, 206],
            'NH4_OUTkg'     => [209, 218],
            'NO2_INkg'      => [221, 230],
            'NO2_OUTkg'     => [233, 242],
            'MINP_INkg'     => [245, 254],
            'MINP_OUTkg'    => [257, 266],
            'CHLA_INkg'     => [269, 278],
            'CHLA_OUTkg'    => [281, 290],
            'CBOD_INkg'     => [293, 302],
            'CBOD_OUTkg'    => [305, 314],
            'DISOX_INkg'    => [317, 326],
            'DISOX_OUTkg'   => [329, 338],
            'SOLPST_INmg'   => [341, 350],
            'SOLPST_OUTmg'  => [353, 362],
            'SORPST_INmg'   => [365, 374],
            'SORPST_OUTmg'  => [377, 386],
            'REACTPTmg'     => [389, 398],
            'VOLPSTmg'      => [401, 410],
            'SETTLPST_mg'   => [413, 422],
            'RESUSP_PSTmg'  => [425, 434],
            'DIFUSEPSTmg'   => [437, 446],
            'REACHBEDPSTmg' => [449, 458],
            'BURYPSTmg'     => [461, 470],
            'BED_PSTmg'     => [473, 482],
            'BACTP_OUTct'   => [485, 494],
            'BACTLP_OUTct'  => [497, 506],
            'CMETAL1kg'     => [509, 518],
            'CMETAL2kg'     => [521, 530],
            'CMETAL3kg'     => [533, 542],
            'TOT_Nkg'       => [545, 554],
            'TOT_Pkg'       => [557, 566],
            'NO3CONCmg_l'   => [569, 578],
            'WTMPdegc'      => [581, 590],
        ];

        $row = self::parseFixedWidthLine($line, $spec);
        if ($row === null) {
            return null;
        }

        if (!ctype_digit((string)$row['SUB']) || !ctype_digit((string)$row['GIS']) || !ctype_digit((string)$row['MON'])) {
            return null;
        }

        $mon = (int)$row['MON'];
        if ($mon < 1 || $mon > 12) {
            return null;
        }

        unset($row['GIS']);
        return $row;
    }

    private static function parseSnuLine(string $line): ?array
    {
        $line = rtrim($line, "\r\n");
        $trim = trim($line);
        if ($trim === '' || self::isLikelySnuHeaderLine($line)) {
            return null;
        }

        if (stripos(ltrim($line), 'SNU') !== 0) {
            return null;
        }

        $spec = [
            'DAY'    => [6, 10],
            'HRUGIS' => [12, 20],
            'SOL_RSD'=> [22, 31],
            'SOL_P'  => [33, 41],
            'NO3'    => [43, 51],
            'ORG_N'  => [53, 61],
            'ORG_P'  => [63, 71],
            'CN'     => [73, 81],
        ];

        $row = self::parseFixedWidthLine($line, $spec);
        if ($row === null) {
            return null;
        }

        if (!ctype_digit((string)$row['DAY']) || !ctype_digit((string)$row['HRUGIS'])) {
            return null;
        }

        return $row;
    }

    private static function parseFixedWidthLine(string $line, array $spec): ?array
    {
        $row = [];
        foreach ($spec as $field => [$start, $end]) {
            $row[$field] = self::sliceFixed($line, $start, $end);
        }
        return $row;
    }

    private static function sliceFixed(string $line, int $start, int $end): string
    {
        $length = $end - $start + 1;
        if ($length <= 0) {
            return '';
        }

        $value = substr($line, $start - 1, $length);
        if ($value === false) {
            return '';
        }

        return self::normalizeFixedToken($value);
    }

    private static function normalizeFixedToken(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // ".00000E+00" -> "0.00000E+00"
        if (preg_match('/^\.(\d+(?:[Ee][+-]?\d+)?)$/', $value, $m)) {
            return '0.' . $m[1];
        }

        // "-.123E+02" -> "-0.123E+02"
        if (preg_match('/^([+-])\.(\d+(?:[Ee][+-]?\d+)?)$/', $value, $m)) {
            return $m[1] . '0.' . $m[2];
        }

        return $value;
    }

    private static function isLikelyHruHeaderLine(string $line): bool
    {
        return stripos($line, 'LULC') !== false && stripos($line, 'HRU') !== false;
    }

    private static function isLikelyRchHeaderLine(string $line): bool
    {
        return stripos($line, 'AREAkm2') !== false && stripos($line, 'FLOW_IN') !== false;
    }

    private static function isLikelySnuHeaderLine(string $line): bool
    {
        return stripos($line, 'GIS') !== false && stripos($line, 'SOL_RSD') !== false;
    }

    private static function deriveHruYearMonth(
        array $row,
        int $baseYear,
        int $monthIndex,
        ?string $firstSequenceKey,
        bool $seenAtLeastOneRow
    ): array {
        $sequenceKey = implode('|', [
            (string)$row['HRUGIS'],
            (string)$row['HRU'],
            (string)$row['SUB'],
            (string)$row['LULC'],
        ]);

        if ($firstSequenceKey === null) {
            $firstSequenceKey = $sequenceKey;
        } elseif ($seenAtLeastOneRow && $sequenceKey === $firstSequenceKey) {
            $monthIndex++;
        }

        $seenAtLeastOneRow = true;

        $mon  = ($monthIndex % 12) + 1;
        $year = $baseYear + intdiv($monthIndex, 12);

        return [$year, $mon, $monthIndex, $firstSequenceKey, $seenAtLeastOneRow];
    }

    private static function dateFromYearDoy(int $year, int $day): string
    {
        $day = max(1, $day);
        $dt = new DateTimeImmutable(sprintf('%04d-01-01', $year));
        return $dt->modify('+' . ($day - 1) . ' days')->format('Y-m-d');
    }

    private static function minDate(?string $a, ?string $b): ?string
    {
        if ($a === null) return $b;
        if ($b === null) return $a;
        return $a <= $b ? $a : $b;
    }

    private static function maxDate(?string $a, ?string $b): ?string
    {
        if ($a === null) return $b;
        if ($b === null) return $a;
        return $a >= $b ? $a : $b;
    }

    public static function parseHruDataLine(string $line): ?array
    {
        return self::parseHruLine($line);
    }

    public static function parseRchDataLine(string $line): ?array
    {
        return self::parseRchLine($line);
    }

    public static function parseSnuDataLine(string $line): ?array
    {
        return self::parseSnuLine($line);
    }

    public static function deriveHruYearMonthPublic(
        array $row,
        int $baseYear,
        int $monthIndex,
        ?string $firstSequenceKey,
        bool $seenAtLeastOneRow
    ): array {
        return self::deriveHruYearMonth(
            $row,
            $baseYear,
            $monthIndex,
            $firstSequenceKey,
            $seenAtLeastOneRow
        );
    }
}