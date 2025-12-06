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
    {
        id: 'soil_fertility',
        sector: 'Soil',
        name: 'Soil fertility index',
        description: 'Composite fertility indicator based on NO3, ORG_N, SOL_P, ORG_P, SOL_RSD.',
        requiresCrop: true,
        unit: 'index (0–1)',
        calc: ({ snuAnnual, areaHa, sub, crop, year }) => {
            const rows = snuAnnual.data.filter(r =>
                r.SUB === +sub && r.LULC === crop && r.YEAR === year
            );
            if (!rows.length) return NaN;

            let wsum = 0, vsum = 0;

            for (const r of rows) {
                const w = areaHa(r);

                // Normalize each variable to 0–1 (placeholder scaling)
                const n_no3   = Math.min(1, r.NO3_mean    / 50);
                const n_orgn  = Math.min(1, r.ORG_N_mean  / 50);
                const n_solp  = Math.min(1, r.SOL_P_mean  / 50);
                const n_orgp  = Math.min(1, r.ORG_P_mean  / 50);
                const n_rsd   = Math.min(1, r.SOL_RSD_mean / 500);

                const fert = (n_no3 + n_orgn + n_solp + n_orgp + n_rsd) / 5;

                vsum += fert * w;
                wsum += w;
            }

            return wsum ? vsum / wsum : NaN;
        },
        needsSnu: ['NO3', 'ORG_N', 'SOL_P', 'ORG_P', 'SOL_RSD']
    },


    // ---------- CROP ----------
    {
        id: 'crop_yield',
        sector: 'Crop',
        name: 'Crop yield',
        description: 'Annual crop yield (area-weighted). Uses the maximum monthly YLD per year (dry matter).',
        requiresCrop: true,
        unit: 't/ha / yr',
        calc: ({ hruAnnual, areaHa, sub, crop, year }) => {
            const rows = hruAnnual.bySubCropYear(sub, crop, year);
            if (!rows.length) return NaN;
            let wsum = 0, vsum = 0;
            for (const r of rows) {
                const w = areaHa(r);
                const v = r.YLDt_ha_max ?? NaN;   // max monthly yield for this HRU×year
                if (Number.isFinite(w) && Number.isFinite(v)) {
                    wsum += w;
                    vsum += v * w;
                }
            }
            return wsum ? vsum / wsum : NaN;
        },
        needs: ['YLDt_ha'],
    },
    {
        id: 'n_use_eff_uptake',
        sector: 'Crop',
        name: 'N use efficiency (uptake)',
        description: 'Crop yield divided by plant N uptake (YLD / NUP), aggregated per subbasin and crop.',
        requiresCrop: true,
        unit: 't yield / kg N',
        calc: ({ hruAnnual, areaHa, sub, crop, year }) => {
            const rows = hruAnnual.bySubCropYear(sub, crop, year);
            if (!rows.length) return NaN;
            let totYield = 0; // t
            let totN = 0;     // kg
            for (const r of rows) {
                const w = areaHa(r); // ha
                const y = r.YLDt_ha_max ?? NaN;     // t/ha
                const n = r.NUP_kg_ha_sum ?? NaN;   // kg/ha
                if (!Number.isFinite(w) || !Number.isFinite(y) || !Number.isFinite(n)) continue;
                totYield += y * w; // t
                totN     += n * w; // kg
            }
            return totN ? totYield / totN : NaN;
        },
        needs: ['YLDt_ha', 'NUP_kg_ha'],
    },
    {
        id: 'p_use_eff_uptake',
        sector: 'Crop',
        name: 'P use efficiency (uptake)',
        description: 'Crop yield divided by plant P uptake (YLD / PUP), aggregated per subbasin and crop.',
        requiresCrop: true,
        unit: 't yield / kg P',
        calc: ({ hruAnnual, areaHa, sub, crop, year }) => {
            const rows = hruAnnual.bySubCropYear(sub, crop, year);
            if (!rows.length) return NaN;
            let totYield = 0;
            let totP = 0;
            for (const r of rows) {
                const w = areaHa(r);
                const y = r.YLDt_ha_max ?? NaN;
                const p = r.PUPkg_ha_sum ?? NaN;
                if (!Number.isFinite(w) || !Number.isFinite(y) || !Number.isFinite(p)) continue;
                totYield += y * w;
                totP     += p * w;
            }
            return totP ? totYield / totP : NaN;
        },
        needs: ['YLDt_ha', 'PUPkg_ha'],
    },
    {
        id: 'n_use_eff_fert',
        sector: 'Crop',
        name: 'N use efficiency (fertilizer)',
        description: 'Crop yield divided by total N applied (N_APP + N_AUTO + NGRZ + CFERTN).',
        requiresCrop: true,
        unit: 't yield / kg N',
        calc: ({ hruAnnual, areaHa, sub, crop, year }) => {
            const rows = hruAnnual.bySubCropYear(sub, crop, year);
            if (!rows.length) return NaN;
            let totYield = 0;
            let totN = 0;
            for (const r of rows) {
                const w = areaHa(r);
                const y = r.YLDt_ha_max ?? NaN;
                const nApp  = r.N_APPkg_ha_sum  ?? NaN;
                const nAuto = r.N_AUTOkg_ha_sum ?? NaN;
                const nGraz = r.NGRZkg_ha_sum   ?? NaN;
                const nCfrt = r.NCFRTkg_ha_sum  ?? NaN;
                if (!Number.isFinite(w) || !Number.isFinite(y)) continue;

                const nTot = [nApp, nAuto, nGraz, nCfrt]
                    .filter(Number.isFinite)
                    .reduce((a, b) => a + b, 0);

                if (!Number.isFinite(nTot) || nTot <= 0) continue;

                totYield += y * w;
                totN     += nTot * w;
            }
            return totN ? totYield / totN : NaN;
        },
        needs: ['YLDt_ha', 'N_APPkg_ha', 'N_AUTOkg_ha', 'NGRZkg_ha', 'NCFRTkg_ha'],
    },
    {
        id: 'p_use_eff_fert',
        sector: 'Crop',
        name: 'P use efficiency (fertilizer)',
        description: 'Crop yield divided by total P applied (P_APP + P_AUTO + PGRAZ + CFERTP).',
        requiresCrop: true,
        unit: 't yield / kg P',
        calc: ({ hruAnnual, areaHa, sub, crop, year }) => {
            const rows = hruAnnual.bySubCropYear(sub, crop, year);
            if (!rows.length) return NaN;
            let totYield = 0;
            let totP = 0;
            for (const r of rows) {
                const w = areaHa(r);
                const y = r.YLDt_ha_max ?? NaN;
                const pApp  = r.P_APPkg_ha_sum  ?? NaN;
                const pAuto = r.P_AUTOkg_ha_sum ?? NaN;
                const pGraz = r.PGRZkg_ha_sum   ?? NaN;
                const pCfrt = r.PCFRTkg_ha_sum  ?? NaN;
                if (!Number.isFinite(w) || !Number.isFinite(y)) continue;

                const pTot = [pApp, pAuto, pGraz, pCfrt]
                    .filter(Number.isFinite)
                    .reduce((a, b) => a + b, 0);

                if (!Number.isFinite(pTot) || pTot <= 0) continue;

                totYield += y * w;
                totP     += pTot * w;
            }
            return totP ? totYield / totP : NaN;
        },
        needs: ['YLDt_ha', 'P_APPkg_ha', 'P_AUTOkg_ha', 'PGRZkg_ha', 'PCFRTkg_ha'],
    },

    // ---------- PLACEHOLDERS / FUTURE (kept visible as disabled with help text) ----------
    {
        id: 'gw_nitrate_leaching',
        sector: 'Groundwater',
        name: 'Nitrate leaching to groundwater',
        description: 'Annual nitrate leaching from soil profile (area-weighted), from NO3L.HRU.',
        requiresCrop: true,
        unit: 'kg N / ha / yr',
        calc: ({ hruAnnual, areaHa, sub, crop, year }) => {
            const rows = hruAnnual.bySubCropYear(sub, crop, year);
            if (!rows.length) return NaN;
            let wsum = 0, vsum = 0;
            for (const r of rows) {
                const w = areaHa(r);
                const v = r.NO3Lkg_ha_sum ?? NaN; // annual sum of monthly NO3Lkg_ha
                if (Number.isFinite(w) && Number.isFinite(v)) {
                    wsum += w;
                    vsum += v * w;
                }
            }
            return wsum ? vsum / wsum : NaN;
        },
        needs: ['NO3Lkg_ha'],
    },
    {
        id: 'surface_nitrate_conc',
        sector: 'Surface water',
        name: 'Nitrate conc. in surface water (proxy)',
        description: 'Load-weighted annual mean nitrate concentration, from NO3_OUT / FLOW_OUT (relative units).',
        requiresCrop: false,
        unit: 'relative',
        // Uses RCH outputs
        calc: ({ rchAnnual, sub, year }) => {
            const rows = rchAnnual.bySubYear(sub, year);
            if (!rows.length) return NaN;
            let loadSum = 0, flowSum = 0;
            for (const r of rows) {
                const load = r.NO3_OUTkg_sum ?? NaN;
                const flow = r.FLOW_OUTcms_sum ?? NaN;
                if (!Number.isFinite(load) || !Number.isFinite(flow) || flow <= 0) continue;
                loadSum += load;
                flowSum += flow;
            }
            return flowSum ? loadSum / flowSum : NaN;
        },
        needsRch: ['NO3_OUTkg', 'FLOW_OUTcms'],
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
        id: 'soc',
        sector: 'Soil',
        name: 'Soil organic carbon (SOC)',
        description: 'SOC ≈ 14 × ORG_N (annual mean organic N from SNU).',
        requiresCrop: true,
        unit: 't C/ha',
        calc: ({ snuAnnual, areaHa, sub, crop, year }) => {
            const rows = snuAnnual.data.filter(r =>
                r.SUB === +sub && r.LULC === crop && r.YEAR === year
            );
            if (!rows.length) return NaN;
            let wsum = 0, vsum = 0;
            for (const r of rows) {
                const w = areaHa(r);
                const soc = 14 * r.ORG_N_mean / 1000; // t C/ha
                if (!Number.isFinite(w) || !Number.isFinite(soc)) continue;
                wsum += w;
                vsum += soc * w;
            }
            return wsum ? vsum / wsum : NaN;
        },
        needsSnu: ['ORG_N']
    },
    {
        id: 'carbon_sequestration',
        sector: 'Atmosphere',
        name: 'Carbon sequestration in crop',
        description: 'From biomass (BIOM.HRU); uses the maximum monthly biomass per year. Carbon ≈ 50% of biomass.',
        requiresCrop: true,
        unit: 't C/ha / yr',
        calc: ({ hruAnnual, areaHa, sub, crop, year }) => {
            const rows = hruAnnual.bySubCropYear(sub, crop, year);
            if (!rows.length) return NaN;
            let wsum = 0, vsum = 0;
            for (const r of rows) {
                const w = areaHa(r);
                const biomMax = r.BIOMt_ha_max ?? NaN;  // max monthly biomass for this HRU×year
                const carbon = Number.isFinite(biomMax) ? 0.5 * biomMax : NaN;
                if (Number.isFinite(w) && Number.isFinite(carbon)) {
                    wsum += w;
                    vsum += carbon * w;
                }
            }
            return wsum ? vsum / wsum : NaN;
        },
        needs: ['BIOMt_ha'],
    },
];