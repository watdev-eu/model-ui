<?php
declare(strict_types=1);

final class McaValueResolver
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getDefaultVariableSetId(int $studyAreaId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM mca_variable_sets
            WHERE study_area_id = :sa
              AND is_default = TRUE
              AND user_id IS NULL
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute([':sa' => $studyAreaId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    public function getBaselineRunId(int $studyAreaId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM swat_runs
            WHERE study_area = :sa AND is_baseline = TRUE
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute([':sa' => $studyAreaId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    /**
     * Resolve a GLOBAL (non-crop) value.
     * Precedence: run override -> study-area default -> null
     */
    public function resolveGlobalValue(
        int $variableSetId,
        string $key,
        ?int $runId = null
    ): array {
        $var = $this->getVariableByKey($key);
        if (!$var) return ['data_type' => null, 'value_num' => null, 'value_text' => null, 'value_bool' => null];

        $vid = (int)$var['id'];

        // 1) run override
        if ($runId) {
            $stmt = $this->pdo->prepare("
                SELECT value_num, value_text, value_bool
                FROM mca_variable_values_run
                WHERE variable_set_id = :vs AND run_id = :run AND variable_id = :vid
                LIMIT 1
            ");
            $stmt->execute([':vs' => $variableSetId, ':run' => $runId, ':vid' => $vid]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) return ['data_type' => $var['data_type']] + $row;
        }

        // 2) study-area default
        $stmt = $this->pdo->prepare("
            SELECT value_num, value_text, value_bool
            FROM mca_variable_values
            WHERE variable_set_id = :vs AND variable_id = :vid
            LIMIT 1
        ");
        $stmt->execute([':vs' => $variableSetId, ':vid' => $vid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) return ['data_type' => $var['data_type']] + $row;

        return ['data_type' => $var['data_type'], 'value_num' => null, 'value_text' => null, 'value_bool' => null];
    }

    /**
     * Resolve a CROP value.
     * Precedence:
     *   1) crop+run override (for this run)
     *   2) crop default (study-area)
     * Special-case: prod_cost_ref_usd_per_t uses baseline run if $baselineRunId is given.
     */
    public function resolveCropValue(
        int $variableSetId,
        string $cropCode,
        string $key,
        ?int $runId = null,
        ?int $baselineRunId = null
    ): array {
        $var = $this->getVariableByKey($key);
        if (!$var) return ['data_type' => null, 'value_num' => null, 'value_text' => null, 'value_bool' => null];

        $vid = (int)$var['id'];

        // baseline-only behaviour for REF cost
        if ($key === 'prod_cost_ref_usd_per_t' && $baselineRunId) {
            $stmt = $this->pdo->prepare("
                SELECT value_num, value_text, value_bool
                FROM mca_variable_values_crop_run
                WHERE variable_set_id = :vs AND run_id = :run AND crop_code = :crop AND variable_id = :vid
                LIMIT 1
            ");
            $stmt->execute([':vs' => $variableSetId, ':run' => $baselineRunId, ':crop' => $cropCode, ':vid' => $vid]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return ['data_type' => $var['data_type']] + ($row ?: ['value_num'=>null,'value_text'=>null,'value_bool'=>null]);
        }

        // 1) crop+run override for run-specific keys (e.g. BMP costs)
        if ($runId) {
            $stmt = $this->pdo->prepare("
                SELECT value_num, value_text, value_bool
                FROM mca_variable_values_crop_run
                WHERE variable_set_id = :vs AND run_id = :run AND crop_code = :crop AND variable_id = :vid
                LIMIT 1
            ");
            $stmt->execute([':vs' => $variableSetId, ':run' => $runId, ':crop' => $cropCode, ':vid' => $vid]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) return ['data_type' => $var['data_type']] + $row;
        }

        // 2) crop default
        $stmt = $this->pdo->prepare("
            SELECT value_num, value_text, value_bool
            FROM mca_variable_values_crop
            WHERE variable_set_id = :vs AND crop_code = :crop AND variable_id = :vid
            LIMIT 1
        ");
        $stmt->execute([':vs' => $variableSetId, ':crop' => $cropCode, ':vid' => $vid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) return ['data_type' => $var['data_type']] + $row;

        return ['data_type' => $var['data_type'], 'value_num' => null, 'value_text' => null, 'value_bool' => null];
    }

    private function getVariableByKey(string $key): ?array
    {
        static $cache = [];
        if (array_key_exists($key, $cache)) return $cache[$key];

        $stmt = $this->pdo->prepare("SELECT id, key, data_type FROM mca_variables WHERE key = :k LIMIT 1");
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        $cache[$key] = $row;
        return $row;
    }
}