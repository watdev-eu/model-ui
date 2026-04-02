<?php
// classes/ImportCsvHelper.php

declare(strict_types=1);

final class ImportCsvHelper
{
    public const REQUIRED_COLUMNS = [
        'hru' => [
            'LULC', 'HRU', 'HRUGIS', 'SUB', 'YEAR',
            'MON', 'AREAkm2', 'IRRmm', 'SA_IRRmm',
            'DA_IRRmm', 'YLDt_ha', 'BIOMt_ha', 'SYLDt_ha',
            'NUP_kg_ha', 'PUPkg_ha', 'NO3Lkg_ha',
            'N_APPkg_ha', 'P_APPkg_ha', 'N_AUTOkg_ha',
            'P_AUTOkg_ha', 'NGRZkg_ha', 'PGRZkg_ha',
            'NCFRTkg_ha', 'PCFRTkg_ha'
        ],
        'rch' => [
            'SUB', 'YEAR', 'MON', 'AREAkm2',
            'FLOW_OUTcms', 'NO3_OUTkg', 'SED_OUTtons'
        ],
        'snu' => [
            'YEAR', 'DAY', 'HRUGIS', 'SOL_RSD',
            'SOL_P', 'NO3', 'ORG_N', 'ORG_P', 'CN'
        ],
    ];

    public static function normalizeFieldSep(string $v): string
    {
        $v = trim($v);
        if ($v === '\\t') return "\t";
        if (in_array($v, [';', ',', "\t"], true)) return $v;
        return ';';
    }

    public static function decimalSepForFieldSep(string $fieldSep): string
    {
        return $fieldSep === ',' ? '.' : ',';
    }

    public static function makeHeaderIndex(array $header): array
    {
        $idx = [];
        foreach ($header as $i => $name) {
            $name = trim((string)$name);
            if ($i === 0) {
                $name = preg_replace('/^\xEF\xBB\xBF/', '', $name);
            }
            if ($name !== '') {
                $idx[strtoupper($name)] = $i;
            }
        }
        return $idx;
    }

    public static function missingRequired(array $headerIndex, array $required): array
    {
        $missing = [];
        foreach ($required as $col) {
            if (!array_key_exists(strtoupper($col), $headerIndex)) {
                $missing[] = $col;
            }
        }
        return $missing;
    }

    public static function inspect(string $path, string $type, string $fieldSep): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("File not found: $path");
        }

        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new RuntimeException("Cannot open file: $path");
        }

        $header = fgetcsv($fh, 0, $fieldSep);
        if ($header === false) {
            fclose($fh);
            throw new RuntimeException(strtoupper($type) . ' CSV appears empty');
        }

        $header = array_map(static fn($v) => trim((string)$v), $header);
        $idx = self::makeHeaderIndex($header);
        $missing = self::missingRequired($idx, self::REQUIRED_COLUMNS[$type] ?? []);

        $previewRows = [];
        $cropCodes = [];
        $subbasins = [];
        $minYearMonth = null;
        $maxYearMonth = null;
        $rowCount = 0;

        while (($row = fgetcsv($fh, 0, $fieldSep)) !== false) {
            $rowCount++;

            if (count($previewRows) < 5) {
                $previewRows[] = $row;
            }

            if (isset($idx['LULC'], $row[$idx['LULC']])) {
                $code = strtoupper(trim((string)$row[$idx['LULC']]));
                if ($code !== '') {
                    $cropCodes[$code] = true;
                }
            }

            if (isset($idx['SUB'], $row[$idx['SUB']])) {
                $sub = (int)$row[$idx['SUB']];
                if ($sub > 0) {
                    $subbasins[$sub] = true;
                }
            }

            if (isset($idx['YEAR'], $idx['MON']) && isset($row[$idx['YEAR']], $row[$idx['MON']])) {
                $y = (int)$row[$idx['YEAR']];
                $m = (int)$row[$idx['MON']];
                if ($y > 0 && $m >= 1 && $m <= 12) {
                    $key = sprintf('%04d-%02d-01', $y, $m);
                    $minYearMonth = $minYearMonth === null || $key < $minYearMonth ? $key : $minYearMonth;
                    $maxYearMonth = $maxYearMonth === null || $key > $maxYearMonth ? $key : $maxYearMonth;
                }
            }
        }

        fclose($fh);

        return [
            'type' => $type,
            'header' => $header,
            'preview_rows' => $previewRows,
            'missing_required' => $missing,
            'ok' => count($missing) === 0,
            'row_count' => $rowCount,
            'crop_codes' => array_values(array_keys($cropCodes)),
            'detected_subbasins' => array_map('intval', array_values(array_keys($subbasins))),
            'period_start_guess' => $minYearMonth,
            'period_end_guess' => $maxYearMonth,
        ];
    }

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

    public static function normalizeCsvToTemp(string $srcPath, string $delimiter, string $enclosure = '"', string $escape = "\\"): string
    {
        $in = fopen($srcPath, 'rb');
        if (!$in) throw new RuntimeException("Cannot open CSV: $srcPath");

        $tmp = tempnam(sys_get_temp_dir(), 'csvnorm_');
        $out = fopen($tmp, 'wb');
        if (!$out) throw new RuntimeException("Cannot create temp CSV: $tmp");

        $headerLine = fgets($in);
        if ($headerLine === false) throw new RuntimeException("CSV empty: $srcPath");

        $header = str_getcsv(rtrim($headerLine, "\r\n"), $delimiter, $enclosure, $escape);
        $expectedCols = count($header);

        fputcsv($out, $header, $delimiter, $enclosure, $escape);

        $buffer = '';
        while (!feof($in)) {
            $line = fgets($in);
            if ($line === false) break;

            $buffer .= $line;
            $record = str_getcsv(rtrim($buffer, "\r\n"), $delimiter, $enclosure, $escape);

            if (count($record) !== $expectedCols) {
                continue;
            }

            fputcsv($out, $record, $delimiter, $enclosure, $escape);
            $buffer = '';
        }

        if (trim($buffer) !== '') {
            fclose($in);
            fclose($out);
            @unlink($tmp);
            throw new RuntimeException("CSV appears malformed near end (unfinished record).");
        }

        fclose($in);
        fclose($out);

        return $tmp;
    }

    public static function normalizeDecimalIfNeeded(string $srcPath, string $decimalSep, string $fieldSep): string
    {
        if (!is_file($srcPath)) {
            throw new RuntimeException('CSV file not found: ' . $srcPath);
        }
        return $srcPath;
    }

    public static function pgNumericExpr(string $col, string $decimalSep, string $fieldSep): string
    {
        if ($decimalSep === ',' && $fieldSep !== ',') {
            return "NULLIF(REPLACE($col, ',', '.'), '')::double precision";
        }
        return "NULLIF($col, '')::double precision";
    }
}