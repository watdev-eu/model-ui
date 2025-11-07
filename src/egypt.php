<?php
$pageTitle = 'Egypt — Subbasin Dashboard';
$pageButtons = [];
require_once 'includes/layout.php';
?>
    <script src="https://cdn.jsdelivr.net/npm/proj4@2.11.0/dist/proj4.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ol@9.2.4/ol.css">
    <style>
        #map { height: 560px; }
        .legend { background:#fff; padding:8px 10px; border-radius:6px; box-shadow:0 1px 3px rgba(0,0,0,.2); font-size:12px; }
        .legend .scale { display:flex; gap:4px; margin-top:6px; }
        .legend .swatch { width:18px; height:12px; border-radius:2px; }
        .legend .legend-swatch-subbasin { width:18px; height:12px; border:1px solid #555; border-radius:2px; background:#e6e6e6; display:inline-block; }
        .legend .legend-line-river { width:34px; height:0; border-top:3px solid #1f77b4; display:inline-block; box-shadow:0 0 0 2px #fff; border-radius:2px; }
        .legend .scale { display:flex; gap:10px; margin-top:6px; }
        .legend .scale .item { display:flex; flex-direction:column; align-items:center; gap:4px; }
        .legend .tick { font-size:11px; color:#333; opacity:.9; line-height:1; }
        /* Legend sliders */
        .legend .opacity { margin-left:auto; display:flex; align-items:center; gap:6px; }
        .legend .op-slider { width:120px; }
        .legend .form-switch .form-check-input { transform: scale(.9); } /* compact switches */

        .info { position:absolute; right:12px; top:12px; background:#fff; padding:6px 8px; border-radius:6px; box-shadow:0 1px 3px rgba(0,0,0,.2); font-size:12px; z-index:1000; }
        .sticky-col { position: sticky; top: 70px; }
        .mono { font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace; }
        .muted { color:#6c757d; }
    </style>

    <div class="row g-3">
        <!-- LEFT: Map -->
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-body">
                    <h2 class="h5 mb-3">Subbasins</h2>
                    <div id="map" class="position-relative">
                        <div id="mapInfo" class="info">Hover or click a subbasin</div>
                    </div>
                    <div id="mapNote" class="mt-2 text-muted small"></div>

                    <!-- Legend -->
                    <div class="legend mt-2" id="legendBox">
                        <div><strong id="legendTitle">Metric</strong></div>

                        <!-- color swatches + tick labels -->
                        <div class="scale" id="legendScale"></div>

                        <!-- Layer key + toggles -->
                        <hr class="my-2">
                        <div class="small fw-semibold mb-1">Layers</div>
                        <div class="d-flex flex-column gap-1">

                            <!-- Subbasins row -->
                            <div class="d-flex align-items-center gap-2">
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input" type="checkbox" id="toggleSubbasins" checked>
                                </div>
                                <span class="legend-swatch-subbasin" aria-hidden="true"></span>
                                <label class="form-check-label mb-0" for="toggleSubbasins">Subbasins</label>

                                <div class="opacity">
                                    <span class="text-muted small">Opacity</span>
                                    <input type="range" class="form-range op-slider" id="opacitySubbasins" min="0" max="100" step="5" value="100" aria-label="Subbasins opacity">
                                    <span class="mono small" id="opacitySubbasinsVal">100%</span>
                                </div>
                            </div>

                            <!-- Rivers row -->
                            <div class="d-flex align-items-center gap-2">
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input" type="checkbox" id="toggleRivers" checked>
                                </div>
                                <span class="legend-line-river" aria-hidden="true"></span>
                                <label class="form-check-label mb-0" for="toggleRivers">Streams</label>

                                <div class="opacity">
                                    <span class="text-muted small">Opacity</span>
                                    <input type="range" class="form-range op-slider" id="opacityRivers" min="0" max="100" step="5" value="100" aria-label="Rivers opacity">
                                    <span class="mono small" id="opacityRiversVal">100%</span>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: Controls -->
        <div class="col-12 col-lg-4">
            <div class="card sticky-col">
                <div class="card-body">
                    <h2 class="h6 mb-3">Controls</h2>

                    <div class="mb-3">
                        <label class="form-label" for="datasetSelect">Dataset</label>
                        <select id="datasetSelect" class="form-select">
                            <!-- Add more scenarios later; baseline wired below -->
                            <option value="/assets/data/egypt/hruEgyptBaseline.csv" selected>Baseline</option>
                        </select>
                        <div class="form-text">You can add scenarios here later.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="metricSelect">Metric</label>
                        <select id="metricSelect" class="form-select"></select>
                        <div id="indicatorHelp" class="form-text"></div>
                    </div>

                    <div class="mb-3" id="cropGroup" style="display:none">
                        <label class="form-label" for="cropSelect">Crop</label>
                        <select id="cropSelect" class="form-select"></select>
                    </div>

                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="avgAllYears" checked>
                            <label class="form-check-label" for="avgAllYears">Average across all years</label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="yearSlider">Year</label>
                        <input type="range" class="form-range" id="yearSlider" min="0" max="0" step="1" value="0" disabled>
                        <div class="d-flex justify-content-between">
                            <span class="mono small" id="yearMin">—</span>
                            <span class="mono small" id="yearLabel">—</span>
                            <span class="mono small" id="yearMax">—</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="aggMode">Summarize by</label>
                        <select id="aggMode" class="form-select">
                            <option value="crop" selected>Crop within subbasin (default)</option>
                            <option value="sub">Subbasin (all crops)</option>
                        </select>
                        <div class="form-text">Default shows KPI per crop type per subbasin. Switch to average across all crops per subbasin.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- BELOW the first .row -->
    <div class="row mt-3">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h2 class="h6 mb-2">Visualizations</h2>
                    <div class="text-muted small mb-2" id="seriesHint">Click a subbasin to load its time series and crop breakdown.</div>
                    <div class="row g-3">
                        <div class="col-12 col-lg-6"><div id="seriesChart" style="height:360px"></div></div>
                        <div class="col-12 col-lg-6"><div id="cropChart" style="height:360px"></div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- libs -->
    <script src="https://cdn.jsdelivr.net/npm/d3-dsv@3"></script>
    <script src="https://cdn.plot.ly/plotly-2.35.3.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/ol@9.2.4/dist/ol.js"></script>

    <!-- modules -->
    <script type="module">
        import { initSubbasinDashboard } from '/assets/js/egypt/subbasin-dashboard.js';
        import { INDICATORS } from '/assets/js/egypt/indicators.js';

        window.addEventListener('DOMContentLoaded', () => {
            initSubbasinDashboard({
                // UI elements
                els: {
                    dataset: document.getElementById('datasetSelect'),
                    metric: document.getElementById('metricSelect'),
                    indicatorHelp: document.getElementById('indicatorHelp'),
                    cropGroup: document.getElementById('cropGroup'),
                    crop: document.getElementById('cropSelect'),
                    avgAllYears: document.getElementById('avgAllYears'),
                    yearSlider: document.getElementById('yearSlider'),
                    yearMin: document.getElementById('yearMin'),
                    yearMax: document.getElementById('yearMax'),
                    yearLabel: document.getElementById('yearLabel'),
                    mapInfo: document.getElementById('mapInfo'),
                    mapNote: document.getElementById('mapNote'),
                    legendBox: document.getElementById('legendBox'),
                    legendTitle: document.getElementById('legendTitle'),
                    legendScale: document.getElementById('legendScale'),
                    seriesHint: document.getElementById('seriesHint'),
                    seriesChart: document.getElementById('seriesChart'),
                    cropChart: document.getElementById('cropChart'),
                    aggMode: document.getElementById('aggMode'),
                    toggleSubbasins: document.getElementById('toggleSubbasins'),
                    toggleRivers: document.getElementById('toggleRivers'),
                    opacitySubbasins: document.getElementById('opacitySubbasins'),
                    opacitySubbasinsVal: document.getElementById('opacitySubbasinsVal'),
                    opacityRivers: document.getElementById('opacityRivers'),
                    opacityRiversVal: document.getElementById('opacityRiversVal'),
                },
                // data sources
                subbasinGeoUrl: '/assets/data/egypt/egypt-subbasin.geojson',
                riversGeoUrl: '/assets/data/egypt/egypt-riv.geojson',
                indicators: INDICATORS
            });
        });
    </script>

<?php include 'includes/footer.php'; ?>