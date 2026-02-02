<?php
// src/classes/McaDependencyRegistry.php
declare(strict_types=1);

final class McaDependencyRegistry
{
    /**
     * Map MCA indicator_code -> required SWAT indicator codes.
     * These SWAT codes must exist in SwatIndicatorRegistry.
     *
     * NOTE: this is dependencies only, not formulas.
     */
    private const MAP = [
        // Water
        'water_use_intensity'   => ['irr_mm'],
        'water_tech_eff'        => ['crop_yield_t_ha', 'irr_mm'],
        'water_econ_eff'        => ['crop_yield_t_ha', 'irr_mm'],

        // Carbon
        'carbon_sequestration'  => ['crop_c_seq_t_ha'],

        // Fertiliser use efficiency
        'fertiliser_use_eff_n'  => ['nue_n_pct'],
        'fertiliser_use_eff_p'  => ['nue_p_pct'],

        // Econ indicators use yield
        'bcr'                   => ['crop_yield_t_ha'],
        'price_cost_ratio'      => ['crop_yield_t_ha'],
        'cost_saving_usd'       => ['crop_yield_t_ha'],
        'net_farm_income_usd_ha'=> ['crop_yield_t_ha', 'irr_mm'],
        'income_increase_pct'   => ['crop_yield_t_ha', 'irr_mm'],

        // Labour is computed from factors (no SWAT)
        'labour_use'            => [],

        // Water rights access needs sub-level irrigation; not available yet in current SWAT fetch shape
        'water_rights_access'   => [],
    ];

    /**
     * @param string[] $mcaIndicatorCodes (MCA indicator_code, e.g. "water_use_intensity")
     * @return string[] unique SWAT indicator codes
     */
    public static function requiredSwatIndicatorsForMca(array $mcaIndicatorCodes): array
    {
        $need = [];
        foreach ($mcaIndicatorCodes as $code) {
            $code = (string)$code;
            $deps = self::MAP[$code] ?? [];
            foreach ($deps as $swatCode) {
                $need[$swatCode] = true;
            }
        }
        return array_keys($need);
    }
}