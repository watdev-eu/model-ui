<?php
$pageTitle = 'Home';
$pageButtons = [];
require_once 'includes/layout.php';
?>
    <div class="card mb-3">
        <div class="card-body">
            <h1 class="title">Model introduction</h1>
            <p>This websites hosts an interface to interact with a model which predicts agricultural scenario’s in 4 regions in the east of Africa.</p>
            <ul>
                <li><a href="model.php" data-original-href="https://watdev-eu.github.io/model-ui/model.html">Request a model run</a></li>
                <li><a href="https://watdev-eu.github.io/model-ui/analyse.html" data-original-href="https://watdev-eu.github.io/model-ui/analyse.html">Analyse existing runs</a></li>
            </ul>
            <hr>
            <p>​The <a href="https://capacity4dev.europa.eu/projects/desira/info/watdev_en" data-original-href="https://capacity4dev.europa.eu/projects/desira/info/watdev_en">WATDEV project</a> is maintained with the financial support of the European Union. Its contents are the sole responsibility of the authors and do not necessarily reflect the views of the European Union.</p>
        </div>
    </div>

    <!-- Study areas map card -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
                <h2 class="h5 mb-0">Study areas</h2>
                <div class="btn-group" role="group" aria-label="Focus study area">
                    <button type="button" class="btn btn-outline-primary" data-country="Egypt">Egypt</button>
                    <button type="button" class="btn btn-outline-primary" data-country="Ethiopia">Ethiopia</button>
                    <button type="button" class="btn btn-outline-primary" data-country="Kenya">Kenya</button>
                    <button type="button" class="btn btn-outline-primary" data-country="Sudan">Sudan</button>
                </div>
            </div>

            <!-- Map container -->
            <div id="studyMap" style="width:100%; height:420px; border-radius:.5rem; overflow:hidden;"></div>
        </div>
    </div>

    <!-- OpenLayers assets (loaded here for convenience; move to layout head if you prefer) -->
    <!-- Keep the CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ol@9.2.4/ol.css">

    <script type="module">
        import Map from 'https://esm.sh/ol@9.2.4/Map';
        import View from 'https://esm.sh/ol@9.2.4/View';
        import TileLayer from 'https://esm.sh/ol@9.2.4/layer/Tile';
        import OSM from 'https://esm.sh/ol@9.2.4/source/OSM';
        import { fromLonLat } from 'https://esm.sh/ol@9.2.4/proj';
        import VectorLayer from 'https://esm.sh/ol@9.2.4/layer/Vector';
        import VectorSource from 'https://esm.sh/ol@9.2.4/source/Vector';
        import GeoJSON from 'https://esm.sh/ol@9.2.4/format/GeoJSON';
        import Style from 'https://esm.sh/ol@9.2.4/style/Style';
        import Stroke from 'https://esm.sh/ol@9.2.4/style/Stroke';
        import Fill from 'https://esm.sh/ol@9.2.4/style/Fill';

        // Base map
        const map = new Map({
            target: 'studyMap',
            layers: [ new TileLayer({ source: new OSM() }) ],
            view: new View({ center: fromLonLat([34, 14]), zoom: 4 })
        });
        window.studyMap = map; // optional

        // Vector layer for country outline
        const outlineSource = new VectorSource();
        const outlineLayer = new VectorLayer({
            source: outlineSource,
            style: new Style({
                stroke: new Stroke({ color: '#0d6efd', width: 3 }),
                fill: new Fill({ color: 'rgba(0,0,0,0)' }) // transparent fill
            })
        });
        map.addLayer(outlineLayer);

        // Fetch world countries GeoJSON once (cached by CDN)
        const WORLD_URL = 'https://cdn.jsdelivr.net/gh/johan/world.geo.json@master/countries.geo.json';
        const fmt = new GeoJSON();
        const proj = map.getView().getProjection();

        const countriesByName = {};
        fetch(WORLD_URL)
            .then(r => r.json())
            .then(gj => {
                const features = fmt.readFeatures(gj, { dataProjection: 'EPSG:4326', featureProjection: proj });
                for (const f of features) countriesByName[f.get('name')] = f;
                enableButtons();                 // activate UI after data loads
                selectCountry('Egypt');          // optional: start focused
            })
            .catch(err => console.error('Country data load failed:', err));

        const colors = { Egypt:'#e91e63', Ethiopia:'#3f51b5', Kenya:'#009688', Sudan:'#ff9800' };

        function selectCountry(name) {
            const f = countriesByName[name];
            if (!f) return;
            outlineSource.clear();
            outlineLayer.setStyle(new Style({
                stroke: new Stroke({ color: colors[name] || '#0d6efd', width: 3 }),
                fill: new Fill({ color: 'rgba(0,0,0,0)' })
            }));
            outlineSource.addFeature(f.clone());
            map.getView().fit(outlineSource.getExtent(), { padding:[40,40,40,40], duration: 500, maxZoom: 7 });

            // button active state
            document.querySelectorAll('[data-country]').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.country === name);
            });
        }

        function enableButtons() {
            document.querySelectorAll('[data-country]').forEach(btn => {
                btn.disabled = false;
                btn.addEventListener('click', () => selectCountry(btn.dataset.country));
            });
        }
    </script>

<?php include 'includes/footer.php'; ?>