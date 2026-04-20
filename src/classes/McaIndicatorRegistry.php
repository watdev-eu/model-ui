<?php
declare(strict_types=1);

require_once __DIR__ . '/SwatIndicatorRegistry.php';

final class McaIndicatorRegistry
{
    public const MCA_MAP = [
        'bcr' => [
            'sector' => 'Socioeconomic',
            'name' => 'Benefit-cost ratio of BMP',
            'unit' => 'ratio',
            'description' => 'Discounted benefits / discounted costs.',
            'source' => 'mca',
            'grain' => 'sub_crop',
        ],
        'price_cost_ratio' => [
            'sector' => 'Socioeconomic',
            'name' => 'Price-Cost Ratio',
            'unit' => 'ratio',
            'description' => 'Price-cost ratio.',
            'source' => 'mca',
            'grain' => 'sub_crop',
        ],
        'cost_saving_usd' => [
            'sector' => 'Socioeconomic',
            'name' => 'Cost saving (US$) as a result of BMP adoption',
            'unit' => 'USD',
            'description' => 'Cost saving due to BMP adoption.',
            'source' => 'mca',
            'grain' => 'sub_crop',
        ],
        'net_farm_income_usd_ha' => [
            'sector' => 'Socioeconomic',
            'name' => 'Total household Net farm income',
            'unit' => 'USD/ha',
            'description' => 'Total household net farm income.',
            'source' => 'mca',
            'grain' => 'sub_crop',
        ],
        'income_increase_pct' => [
            'sector' => 'Socioeconomic',
            'name' => '% increase in net farm income',
            'unit' => '%',
            'description' => '(after - before) / before.',
            'source' => 'mca',
            'grain' => 'sub_crop',
        ],
        'labour_use' => [
            'sector' => 'Socioeconomic',
            'name' => 'Labour use',
            'unit' => 'hours/ha',
            'description' => 'Labour required per hectare.',
            'source' => 'mca',
            'grain' => 'sub_crop',
        ],
        'water_rights_access' => [
            'sector' => 'Socioeconomic',
            'name' => 'No. of community members with access to water rights or secure water resource allocations',
            'unit' => 'count',
            'description' => 'Access to water rights / secure allocations.',
            'source' => 'mca',
            'grain' => 'sub',
        ],
        'water_use_intensity' => [
            'sector' => 'Socioeconomic',
            'name' => 'Intensity of water use by agriculture',
            'unit' => 'm3/ha',
            'description' => 'Irrigation water use per hectare.',
            'source' => 'mca',
            'grain' => 'sub_crop',
        ],
        'water_tech_eff' => [
            'sector' => 'Socioeconomic',
            'name' => 'Technical efficiency (mc) in water use',
            'unit' => 'kg/m3',
            'description' => 'Yield / water use.',
            'source' => 'mca',
            'grain' => 'sub_crop',
        ],
        'water_econ_eff' => [
            'sector' => 'Socioeconomic',
            'name' => 'Economic efficiency ($) in water use',
            'unit' => 'USD/m3',
            'description' => 'Economic value / water use.',
            'source' => 'mca',
            'grain' => 'sub_crop',
        ],
        'carbon_sequestration' => [
            'sector' => 'Socioeconomic',
            'name' => 'Carbon sequestration',
            'unit' => 'tC/ha',
            'description' => 'Carbon sequestration.',
            'source' => 'mca',
            'grain' => 'sub_crop',
        ],
        'fertiliser_use_eff_n' => [
            'sector' => 'Socioeconomic',
            'name' => 'Fertilizer use efficiency (nitrogen)',
            'unit' => 'kg/kg',
            'description' => 'Nitrogen fertilizer use efficiency.',
            'source' => 'mca',
            'grain' => 'sub_crop',
        ],
        'fertiliser_use_eff_p' => [
            'sector' => 'Socioeconomic',
            'name' => 'Fertilizer use efficiency (phosphorus)',
            'unit' => 'kg/kg',
            'description' => 'Phosphorus fertilizer use efficiency.',
            'source' => 'mca',
            'grain' => 'sub_crop',
        ],
    ];

    public static function meta(string $code): array
    {
        if (isset(self::MCA_MAP[$code])) {
            return array_merge(['code' => $code], self::MCA_MAP[$code]);
        }

        $sw = SwatIndicatorRegistry::meta($code);

        return [
            'code' => $code,
            'sector' => $sw['sector'] ?? 'Other',
            'name' => $sw['name'] ?? $code,
            'unit' => $sw['unit'] ?? null,
            'description' => $sw['description'] ?? '',
            'source' => 'swat',
            'grain' => $sw['grain'] ?? 'sub',
        ];
    }

    public static function listForCodes(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $code = (string)$code;
            try {
                $out[] = self::meta($code);
            } catch (\Throwable $e) {
                // ignore unknown code
            }
        }
        return $out;
    }
}