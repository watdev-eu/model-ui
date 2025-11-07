// assets/js/egypt/indicators.js
// Indicator registry + calculator hooks for subbasin-level choropleth and series.
//
// IMPORTANT: All results produced here are **annual** values aggregated to subbasins.
// - For per-crop indicators, we compute per SUB–CROP–YEAR.
// - For subbasin-only indicators, we compute per SUB–YEAR.

export const INDICATORS = [
    // ---------- GROUNDWATER ----------
    {
        id: 'gw_irr',
        sector: 'Groundwater',
        name: 'Irrigation water use',
        description: 'Annual irrigation water use (area-weighted) from IRRmm.',
        requiresCrop: true,
        unit: 'mm / yr',
        // uses HRU monthly IRRmm -> sum to annual per HRU -> area-weighted mean within SUB×CROP
        calc: ({ hruAnnual, areaHa, sub, crop, year }) => {
            const rows = hruAnnual.bySubCropYear(sub, crop, year);
            if (!rows.length) return NaN;
            let wsum = 0, vsum = 0;
            for (const r of rows) {
                const w = areaHa(r);            // ha
                const v = r.IRRmm_sum ?? NaN;   // annual sum of monthly IRRmm
                if (Number.isFinite(w) && Number.isFinite(v)) { wsum += w; vsum += v * w; }
            }
            return wsum ? vsum / wsum : NaN;
        },
        needs: ['IRRmm'],
    },

    // ---------- SOIL ----------
    {
        id: 'soil_erosion_area_gt10',
        sector: 'Soil',
        name: 'Area with soil erosion > 10 t/ha',
        description: 'Sum of HRU area where annual sediment yield (SYLD) exceeds 10 t/ha.',
        requiresCrop: false,
        unit: 'ha',
        calc: ({ hruAnnual, areaHa, sub, year }) => {
            const rows = hruAnnual.bySubYear(sub, year); // across all crops
            let sumHa = 0;
            for (const r of rows) {
                const v = r.SYLDt_ha_sum ?? NaN;
                if (Number.isFinite(v) && v > 10) sumHa += areaHa(r);
            }
            return sumHa || 0;
        },
        needs: ['SYLDt_ha'],
    },
    {
        id: 'soil_erosion_syld',
        sector: 'Soil',
        name: 'Erosion (sediment yield)',
        description: 'Annual sediment yield (SYLD) area-weighted by crop within subbasin.',
        requiresCrop: true,
        unit: 't/ha / yr',
        calc: ({ hruAnnual, areaHa, sub, crop, year }) => {
            const rows = hruAnnual.bySubCropYear(sub, crop, year);
            if (!rows.length) return NaN;
            let wsum = 0, vsum = 0;
            for (const r of rows) {
                const w = areaHa(r);
                const v = r.SYLDt_ha_sum ?? NaN;
                if (Number.isFinite(w) && Number.isFinite(v)) { wsum += w; vsum += v * w; }
            }
            return wsum ? vsum / wsum : NaN;
        },
        needs: ['SYLDt_ha'],
    },

    // ---------- CROP ----------
    {
        id: 'crop_yield',
        sector: 'Crop',
        name: 'Crop yield',
        description: 'Annual crop yield (area-weighted). Note: dry matter.',
        requiresCrop: true,
        unit: 't/ha / yr',
        // If YLDt_ha is unavailable in CSV, this indicator will be disabled by the dashboard.
        calc: ({ hruAnnual, areaHa, sub, crop, year }) => {
            const rows = hruAnnual.bySubCropYear(sub, crop, year);
            if (!rows.length) return NaN;
            let wsum = 0, vsum = 0;
            for (const r of rows) {
                const w = areaHa(r);
                const v = r.YLDt_ha_sum ?? NaN;   // will exist only if CSV has YLDt_ha
                if (Number.isFinite(w) && Number.isFinite(v)) { wsum += w; vsum += v * w; }
            }
            return wsum ? vsum / wsum : NaN;
        },
        needs: ['YLDt_ha'], // will be checked; hidden if missing
    },

    // ---------- PLACEHOLDERS / FUTURE (kept visible as disabled with help text) ----------
    {
        id: 'gw_nitrite_proxy',
        sector: 'Groundwater',
        name: 'Nitrite in groundwater (proxy)',
        description: 'Not implemented yet. Could proxy from nitrate leaching (e.g., LNO3kg/ha).',
        requiresCrop: true,
        unit: '—',
        calc: () => NaN,
        needs: [], // intentionally none; dashboard will mark as (not implemented)
        disabled: true
    },
    {
        id: 'surface_nitrate_conc',
        sector: 'Surface water',
        name: 'Nitrate conc. in surface water',
        description: 'Needs RCH outputs (NO3_OUT / FLOW_OUT). Not in current CSV.',
        requiresCrop: false,
        unit: 'mg/L',
        calc: () => NaN,
        needs: [],
        disabled: true
    },
    {
        id: 'flood_frequency',
        sector: 'Surface water',
        name: 'Flood frequency',
        description: 'Requires streamflow time series and a threshold. Not in current CSV.',
        requiresCrop: false,
        unit: 'days/yr',
        calc: () => NaN,
        needs: [],
        disabled: true
    },
    {
        id: 'soc_from_orgn',
        sector: 'Soil',
        name: 'Soil organic carbon (from ORG_N.SNU)',
        description: 'Needs SNU files; compute SOC ≈ 14×ORG_N (C:N=14:1). Not in current CSV.',
        requiresCrop: true,
        unit: 't C/ha',
        calc: () => NaN,
        needs: [],
        disabled: true
    },
    {
        id: 'carbon_sequestration',
        sector: 'Atmosphere',
        name: 'Carbon sequestration in crop',
        description: 'From biomass (BIOM.HRU), Carbon ≈ 50% of Biomass. Enable if BIOM present.',
        requiresCrop: true,
        unit: 't C/ha / yr',
        calc: ({ hruAnnual, areaHa, sub, crop, year }) => {
            const rows = hruAnnual.bySubCropYear(sub, crop, year);
            let wsum = 0, vsum = 0;
            for (const r of rows) {
                const w = areaHa(r);
                const biom = r.BIOMt_ha_sum ?? NaN; // if CSV exposes biomass as t/ha per month
                const carbon = Number.isFinite(biom) ? 0.5 * biom : NaN;
                if (Number.isFinite(w) && Number.isFinite(carbon)) { wsum += w; vsum += carbon * w; }
            }
            return wsum ? vsum / wsum : NaN;
        },
        needs: ['BIOMt_ha'], // will only enable if present
    },
];