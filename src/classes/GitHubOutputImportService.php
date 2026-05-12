<?php
// classes/GitHubOutputImportService.php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/CropRepository.php';
require_once __DIR__ . '/SwatRawImportHelper.php';

final class GitHubOutputImportService
{
    private const MAX_ZIP_BYTES = 1200000000; // 1200 MB

    public static function listBranches(array $input): array
    {
        [$owner, $repo] = self::parseRepoUrl(self::repoUrlFromInput($input));
        $token = trim((string)($input['github_token'] ?? ''));

        $url = "https://api.github.com/repos/{$owner}/{$repo}/branches?per_page=100";
        $data = self::githubJson($url, $token);

        $branches = [];
        foreach ($data as $row) {
            if (!empty($row['name'])) {
                $branches[] = (string)$row['name'];
            }
        }

        sort($branches);

        return [
            'ok' => true,
            'branches' => $branches,
            'default_branch' => in_array('output', $branches, true) ? 'output' : ($branches[0] ?? ''),
        ];
    }

    public static function listScenarios(array $input): array
    {
        [$owner, $repo] = self::parseRepoUrl(self::repoUrlFromInput($input));
        $ref = self::cleanRef((string)($input['github_ref'] ?? 'output'));
        $token = trim((string)($input['github_token'] ?? ''));

        $url = "https://api.github.com/repos/{$owner}/{$repo}/contents?ref=" . rawurlencode($ref);
        $data = self::githubJson($url, $token);

        if (!is_array($data)) {
            throw new RuntimeException('Could not read repository root.');
        }

        $scenarios = [];

        foreach ($data as $item) {
            if (($item['type'] ?? '') !== 'dir') {
                continue;
            }

            $name = (string)($item['name'] ?? '');
            $path = (string)($item['path'] ?? '');

            if ($name === '' || $path === '') {
                continue;
            }

            if (self::scenarioHasOutputZip($owner, $repo, $ref, $path, $token)) {
                $scenarios[] = [
                    'name' => $name,
                    'path' => $path,
                ];
            }
        }

        usort($scenarios, static fn($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'ok' => true,
            'scenarios' => $scenarios,
        ];
    }

    public static function inspectScenario(array $input): array
    {
        [$owner, $repo] = self::parseRepoUrl(self::repoUrlFromInput($input));
        $ref = self::cleanRef((string)($input['github_ref'] ?? 'output'));
        $scenarioPath = self::cleanScenarioPath((string)($input['scenario_path'] ?? ''));
        $token = trim((string)($input['github_token'] ?? ''));

        if ($scenarioPath === '') {
            throw new RuntimeException('Please choose a scenario.');
        }

        $tokenId = bin2hex(random_bytes(16));
        $baseDir = rtrim(UPLOAD_DIR, '/\\') . '/import_sessions/' . $tokenId;

        self::ensureDirectory($baseDir);

        $zipPath = $baseDir . '/output.zip';
        self::downloadOutputZip($owner, $repo, $ref, $scenarioPath, $token, $zipPath);

        self::extractZipSafely($zipPath, $baseDir);

        $cioPath = self::findExtractedFile($baseDir, ['file.cio', 'file.ico']);
        $hruPath = self::findExtractedFile($baseDir, ['output.hru']);
        $rchPath = self::findExtractedFile($baseDir, ['output.rch']);
        $snuPath = self::findExtractedFile($baseDir, ['output.snu']);

        if (!$cioPath) {
            throw new RuntimeException('output.zip must contain file.cio.');
        }
        if (!$hruPath) {
            throw new RuntimeException('output.zip must contain output.hru.');
        }
        if (!$snuPath) {
            throw new RuntimeException('output.zip must contain output.snu.');
        }

        $finalPaths = [
            'cio' => $baseDir . '/file.cio',
            'hru' => $baseDir . '/output.hru',
            'rch' => $baseDir . '/output.rch',
            'snu' => $baseDir . '/output.snu',
        ];

        self::copyToCanonicalPath($cioPath, $finalPaths['cio']);
        self::copyToCanonicalPath($hruPath, $finalPaths['hru']);
        self::copyToCanonicalPath($snuPath, $finalPaths['snu']);

        if ($rchPath) {
            self::copyToCanonicalPath($rchPath, $finalPaths['rch']);
        } else {
            $finalPaths['rch'] = null;
        }

        $inspect = SwatRawImportHelper::inspectRawSet(
            $finalPaths['cio'],
            $finalPaths['hru'],
            $finalPaths['rch'],
            $finalPaths['snu'],
            'original'
        );

        self::assertInspectionSucceeded($inspect);

        $unknownCrops = self::detectUnknownCrops($inspect['all_crop_codes'] ?? []);

        $meta = [
            'created_at' => date(DATE_ATOM),
            'token' => $tokenId,
            'source_type' => 'original',
            'source_origin' => 'github',
            'github' => [
                'repo' => "{$owner}/{$repo}",
                'ref' => $ref,
                'scenario_path' => $scenarioPath,
                'zip_path' => "{$scenarioPath}/output.zip",
            ],
            'cio' => $inspect['cio'],
            'files' => [
                'cio' => 'file.cio',
                'hru' => 'output.hru',
                'rch' => $finalPaths['rch'] ? 'output.rch' : null,
                'snu' => 'output.snu',
            ],
            'all_crop_codes' => $inspect['all_crop_codes'],
            'all_subbasins' => $inspect['all_subbasins'],
            'period_start_guess' => $inspect['period_start_guess'],
            'period_end_guess' => $inspect['period_end_guess'],
        ];

        $metaJson = json_encode($meta, JSON_PRETTY_PRINT);
        if ($metaJson === false) {
            throw new RuntimeException('Failed to encode import session metadata.');
        }

        if (file_put_contents($baseDir . '/meta.json', $metaJson) === false) {
            throw new RuntimeException('Failed to save import session metadata.');
        }

        return [
            'ok' => true,
            'import_token' => $tokenId,
            'source_type' => 'original',
            'source_origin' => 'github',
            'cio' => $inspect['cio'],
            'inspections' => $inspect['inspections'],
            'unknown_crops' => $unknownCrops,
            'detected_subbasins' => $inspect['all_subbasins'],
            'period_start_guess' => $inspect['period_start_guess'],
            'period_end_guess' => $inspect['period_end_guess'],
        ];
    }

    private static function repoUrlFromInput(array $input): string
    {
        return trim((string)($input['repo_url'] ?? $input['github_repo_url'] ?? ''));
    }

    private static function parseRepoUrl(string $url): array
    {
        $url = trim($url);

        if (!preg_match('~^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?(?:[?#].*)?$~i', $url, $m)) {
            throw new RuntimeException('Please enter a valid GitHub repository URL, for example https://github.com/org/repo.');
        }

        return [$m[1], $m[2]];
    }

    private static function cleanRef(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            $ref = 'output';
        }

        if (!preg_match('/^[A-Za-z0-9._\/-]{1,200}$/', $ref) || str_contains($ref, '..')) {
            throw new RuntimeException('Invalid GitHub branch, tag, or ref.');
        }

        return $ref;
    }

    private static function cleanScenarioPath(string $path): string
    {
        $path = trim($path, "/ \t\r\n");

        if ($path === '') {
            return '';
        }

        if (!preg_match('/^[A-Za-z0-9._\/ -]{1,300}$/', $path) || str_contains($path, '..')) {
            throw new RuntimeException('Invalid scenario path.');
        }

        return $path;
    }

    private static function githubJson(string $url, string $token): array
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: WATDEV-importer',
            'X-GitHub-Api-Version: 2022-11-28',
        ];

        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 60,
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $body === '') {
            throw new RuntimeException('GitHub request failed: ' . ($err ?: 'empty response'));
        }

        $data = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            $msg = is_array($data) && isset($data['message'])
                ? (string)$data['message']
                : 'GitHub request failed.';
            throw new RuntimeException("GitHub error HTTP {$status}: {$msg}");
        }

        if (!is_array($data)) {
            throw new RuntimeException('GitHub returned invalid JSON.');
        }

        return $data;
    }

    private static function scenarioHasOutputZip(
        string $owner,
        string $repo,
        string $ref,
        string $scenarioPath,
        string $token
    ): bool {
        $path = trim($scenarioPath, '/') . '/output.zip';
        $url = "https://api.github.com/repos/{$owner}/{$repo}/contents/" .
            str_replace('%2F', '/', rawurlencode($path)) .
            '?ref=' . rawurlencode($ref);

        try {
            $data = self::githubJson($url, $token);
            return ($data['type'] ?? '') === 'file' && ($data['name'] ?? '') === 'output.zip';
        } catch (Throwable) {
            return false;
        }
    }

    private static function downloadOutputZip(
        string $owner,
        string $repo,
        string $ref,
        string $scenarioPath,
        string $token,
        string $targetPath
    ): void {
        $path = trim($scenarioPath, '/') . '/output.zip';
        $url = "https://api.github.com/repos/{$owner}/{$repo}/contents/" .
            str_replace('%2F', '/', rawurlencode($path)) .
            '?ref=' . rawurlencode($ref);

        $data = self::githubJson($url, $token);

        if (($data['type'] ?? '') !== 'file') {
            throw new RuntimeException('Selected scenario does not contain output.zip.');
        }

        $size = (int)($data['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('output.zip is empty or could not be read.');
        }

        if ($size > self::MAX_ZIP_BYTES) {
            throw new RuntimeException('output.zip is too large.');
        }

        $downloadUrl = (string)($data['download_url'] ?? '');
        if ($downloadUrl === '') {
            throw new RuntimeException('GitHub did not provide a download URL for output.zip.');
        }

        self::downloadFile($downloadUrl, $token, $targetPath);
    }

    private static function downloadFile(string $url, string $token, string $targetPath): void
    {
        $headers = [
            'Accept: application/octet-stream',
            'User-Agent: WATDEV-importer',
        ];

        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $fh = fopen($targetPath, 'wb');
        if (!$fh) {
            throw new RuntimeException('Could not create temporary output.zip.');
        }

        $bytes = 0;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($resource, $downloadSize, $downloaded) use (&$bytes) {
                $bytes = (int)$downloaded;
                if ($downloaded > self::MAX_ZIP_BYTES) {
                    return 1;
                }
                return 0;
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if (!$ok || $status < 200 || $status >= 300) {
            @unlink($targetPath);
            throw new RuntimeException('Failed to download output.zip from GitHub: ' . ($err ?: "HTTP {$status}"));
        }

        if (!is_file($targetPath) || filesize($targetPath) <= 0) {
            throw new RuntimeException('Downloaded output.zip is empty.');
        }

        if (filesize($targetPath) > self::MAX_ZIP_BYTES || $bytes > self::MAX_ZIP_BYTES) {
            @unlink($targetPath);
            throw new RuntimeException('output.zip is too large.');
        }
    }

    private static function extractZipSafely(string $zipPath, string $baseDir): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open output.zip.');
        }

        $extractDir = $baseDir . '/extracted';
        self::ensureDirectory($extractDir);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            $normalized = str_replace('\\', '/', $name);

            if (
                $normalized === '' ||
                str_starts_with($normalized, '/') ||
                str_contains($normalized, '../') ||
                str_contains($normalized, "\0")
            ) {
                $zip->close();
                throw new RuntimeException('output.zip contains an unsafe file path.');
            }

            $lower = strtolower(basename($normalized));
            if ($lower === '') {
                continue;
            }

            if (!in_array($lower, ['file.cio', 'file.ico', 'output.hru', 'output.rch', 'output.snu'], true)) {
                continue;
            }

            $target = $extractDir . '/' . $lower;
            $stream = $zip->getStream($name);
            if (!$stream) {
                continue;
            }

            $out = fopen($target, 'wb');
            if (!$out) {
                fclose($stream);
                $zip->close();
                throw new RuntimeException('Could not extract file from output.zip.');
            }

            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
        }

        $zip->close();
    }

    private static function findExtractedFile(string $baseDir, array $names): ?string
    {
        foreach ($names as $name) {
            $path = $baseDir . '/extracted/' . strtolower($name);
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private static function copyToCanonicalPath(string $source, string $target): void
    {
        if (realpath($source) === realpath($target)) {
            return;
        }

        if (!copy($source, $target)) {
            throw new RuntimeException('Could not prepare extracted import files.');
        }
    }

    private static function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory: ' . $dir);
        }

        if (!is_writable($dir)) {
            throw new RuntimeException('Directory is not writable: ' . $dir);
        }
    }

    private static function detectUnknownCrops(array $allCropCodes): array
    {
        $knownCrops = [];
        foreach (CropRepository::all() as $row) {
            $knownCrops[strtoupper((string)$row['code'])] = (string)$row['name'];
        }

        $unknown = [];
        foreach ($allCropCodes as $code) {
            $uc = strtoupper((string)$code);
            if (!isset($knownCrops[$uc])) {
                $unknown[] = $uc;
            }
        }

        sort($unknown);
        return $unknown;
    }

    private static function assertInspectionSucceeded(array $inspect): void
    {
        $hruOk = (bool)($inspect['inspections']['hru']['ok'] ?? false);
        $snuOk = (bool)($inspect['inspections']['snu']['ok'] ?? false);

        if (!$hruOk) {
            throw new RuntimeException('The HRU file was found, but no valid HRU rows could be parsed.');
        }

        if (!$snuOk) {
            throw new RuntimeException('The SNU file was found, but no valid SNU rows could be parsed.');
        }
    }
}