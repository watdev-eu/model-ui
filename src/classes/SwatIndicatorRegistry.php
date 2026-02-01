<?php
// src/classes/SwatIndicatorRegistry.php
declare(strict_types=1);

final class SwatIndicatorRegistry
{
    /**
     * source: 'hru' | 'snu' | 'rch'
     * grain : 'sub' | 'sub_crop'
     * yearly_agg:
     *  - hru: 'avg_months' | 'sum_months' | 'max_month' | 'mode_bool'
     *  - snu: 'avg_months'
     *  - rch: 'avg_months'
     */
    public const MAP = [

        // ---------------- Groundwater ----------------
        'gw_no3_kg_ha' => [
            'sector'    => 'Groundwater',
            'name'      => 'Nitrate in groundwater',
            'unit'      => 'kg/ha',
            'source'    => 'hru',
            'grain'     => 'sub_crop',
            'hru'       => [
                'type'       => 'col',
                'col'        => 'no3l_kg_ha',
                'monthly_agg'=> 'avg',       // across HRUs within (month,sub,crop)
                'yearly_agg' => 'avg_months' // across months
            ],
        ],

        // ---------------- Soil ----------------
        'soil_erosion_area' => [
            'sector'    => 'Soil',
            'name'      => 'Area with soil erosion (classified)',
            'unit'      => 'bool',
            'source'    => 'hru',
            'grain'     => 'sub_crop',
            'hru'       => [
                'type'          => 'bool_threshold',
                'col'           => 'syld_t_ha',
                'threshold_key' => 'soil_erosion_area_threshold_syld_t_ha',
                // monthly: (AVG(flag_int) > 0.5)  (ties => false)
                // yearly : mode across months (ties => false)
                'yearly_agg'    => 'mode_bool',
            ],
        ],

        'soil_erosion_t_ha' => [
            'sector'    => 'Soil',
            'name'      => 'Soil erosion',
            'unit'      => 't/ha',
            'source'    => 'hru',
            'grain'     => 'sub_crop',
            'hru'       => [
                'type'        => 'col',
                'col'         => 'syld_t_ha',
                'monthly_agg' => 'avg',
                'yearly_agg'  => 'sum_months',
            ],
        ],

        'soil_org_c_kg_ha' => [
            'sector' => 'Soil',
            'name'   => 'Soil organic carbon',
            'unit'   => 'kg/ha',
            'source' => 'snu',
            'grain'  => 'sub',
            'snu'    => [
                'type'          => 'expr_factor',
                'col'           => 'org_n',
                'factor_key'    => 'soil_org_n_to_carbon_factor',
                'yearly_agg'    => 'avg_months',
            ],
        ],

        'soil_fert_soc_kg_ha' => [
            'sector' => 'Soil',
            'name'   => 'Soil fertility SOC',
            'unit'   => 'kg/ha',
            'source' => 'snu',
            'grain'  => 'sub',
            'snu'    => [
                'type'       => 'col',
                'col'        => 'no3',
                'yearly_agg' => 'avg_months',
            ],
        ],

        'soil_fert_n_kg_ha' => [
            'sector' => 'Soil',
            'name'   => 'Soil fertility N',
            'unit'   => 'kg/ha',
            'source' => 'snu',
            'grain'  => 'sub',
            'snu'    => [
                'type'       => 'col',
                'col'        => 'org_n',
                'yearly_agg' => 'avg_months',
            ],
        ],

        'soil_fert_sol_p_kg_ha' => [
            'sector' => 'Soil',
            'name'   => 'Soil fertility soluble P',
            'unit'   => 'kg/ha',
            'source' => 'snu',
            'grain'  => 'sub',
            'snu'    => [
                'type'       => 'col',
                'col'        => 'sol_p',
                'yearly_agg' => 'avg_months',
            ],
        ],

        'soil_fert_org_p_kg_ha' => [
            'sector' => 'Soil',
            'name'   => 'Soil fertility organic P',
            'unit'   => 'kg/ha',
            'source' => 'snu',
            'grain'  => 'sub',
            'snu'    => [
                'type'       => 'col',
                'col'        => 'org_p',
                'yearly_agg' => 'avg_months',
            ],
        ],

        // ---------------- Crop ----------------
        'crop_yield_t_ha' => [
            'sector' => 'Crop',
            'name'   => 'Crop yield per farm',
            'unit'   => 't/ha',
            'source' => 'hru',
            'grain'  => 'sub_crop',
            'hru'    => [
                'type'        => 'col',
                'col'         => 'yld_t_ha',
                'monthly_agg' => 'avg',
                'yearly_agg'  => 'max_month',
            ],
        ],

        'nue_n_pct' => [
            'sector' => 'Crop',
            'name'   => 'Nutrient (N) use efficiency',
            'unit'   => '%',
            'source' => 'hru',
            'grain'  => 'sub_crop',
            'hru'    => [
                'type'       => 'expr_nue_n',
                'yearly_agg' => 'avg_months',
            ],
        ],

        'nue_p_pct' => [
            'sector' => 'Crop',
            'name'   => 'Nutrient (P) use efficiency',
            'unit'   => '%',
            'source' => 'hru',
            'grain'  => 'sub_crop',
            'hru'    => [
                'type'       => 'expr_nue_p',
                'yearly_agg' => 'avg_months',
            ],
        ],

        'irr_mm' => [
            'sector' => 'Crop',
            'name'   => 'Irrigation water use',
            'unit'   => 'mm',
            'source' => 'hru',
            'grain'  => 'sub_crop',
            'hru'    => [
                'type'        => 'col',
                'col'         => 'irr_mm',
                'monthly_agg' => 'avg',
                'yearly_agg'  => 'sum_months',
            ],
        ],

        'crop_c_seq_t_ha' => [
            'sector' => 'Crop',
            'name'   => 'Carbon sequestration in crop',
            'unit'   => 't/ha',
            'source' => 'hru',
            'grain'  => 'sub_crop',
            'hru'    => [
                'type'       => 'expr_biom_frac',
                'factor_key' => 'crop_carbon_fraction',
                'yearly_agg' => 'max_month',
            ],
        ],

        // ---------------- Surface water ----------------
        'sw_no3_kg_m3' => [
            'sector' => 'Surface water',
            'name'   => 'Nitrate content in surface water',
            'unit'   => 'kg/m3',
            'source' => 'rch',
            'grain'  => 'sub',
            'rch'    => [
                'type'       => 'expr_no3_conc',
                'yearly_agg' => 'avg_months',
            ],
        ],
    ];

    public static function get(string $code): array
    {
        if (!isset(self::MAP[$code])) {
            throw new InvalidArgumentException("Unknown indicator: {$code}");
        }
        return self::MAP[$code];
    }

    public static function meta(string $code): array
    {
        $d = self::get($code);
        return [
            'code'   => $code,
            'sector' => $d['sector'],
            'name'   => $d['name'],
            'unit'   => $d['unit'],
            'source' => $d['source'],
            'grain'  => $d['grain'],
        ];
    }

    public static function list(): array
    {
        // returns meta for all indicators
        $out = [];
        foreach (array_keys(self::MAP) as $code) {
            $out[] = self::meta($code);
        }
        return $out;
    }

    public static function listBySector(): array
    {
        $out = [];
        foreach (array_keys(self::MAP) as $code) {
            $m = self::meta($code);
            $out[$m['sector']][] = $m;
        }
        ksort($out);
        return $out;
    }
}