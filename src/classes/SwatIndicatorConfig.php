<?php
// src/classes/SwatIndicatorConfig.php
declare(strict_types=1);

final class SwatIndicatorConfig
{
    public const DEFAULTS = [
        'soil_erosion_area_threshold_syld_t_ha' => 10.0,   // classified soil erosion threshold
        'crop_carbon_fraction'                  => 0.5,    // carbon fraction of biomass
        'soil_org_n_to_carbon_factor'           => 14.0,   // org_n * 14
        'rch_flow_seconds_per_month_equiv'      => 43800.0 // constant used in RCH concentration formula
    ];

    public static function get(array $overrides, string $key): float
    {
        if (array_key_exists($key, $overrides)) {
            return (float)$overrides[$key];
        }
        return (float)(self::DEFAULTS[$key] ?? 0.0);
    }
}