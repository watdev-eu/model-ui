<?php
// classes/DashboardDatasetKey.php

declare(strict_types=1);

final class DashboardDatasetKey
{
    public static function parse(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            throw new InvalidArgumentException('Empty dataset key.');
        }

        if (preg_match('/^\d+$/', $raw)) {
            return [
                'type' => 'run',
                'id'   => (int)$raw,
            ];
        }

        if (preg_match('/^custom:(\d+)$/', $raw, $m)) {
            return [
                'type' => 'custom',
                'id'   => (int)$m[1],
            ];
        }

        throw new InvalidArgumentException('Invalid dataset key.');
    }

    public static function makeRunKey(int $runId): string
    {
        return (string)$runId;
    }

    public static function makeCustomScenarioKey(int $scenarioId): string
    {
        return 'custom:' . $scenarioId;
    }
}