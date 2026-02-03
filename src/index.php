<?php
$pageTitle = 'Home';
$pageButtons = [];
require_once 'includes/layout.php';
?>
    <div class="card mb-3">
        <div class="card-body">

            <!-- Hero header -->
            <div class="p-3 p-lg-4 rounded-3 bg-light border mb-3">
                <h1 class="title mb-2">WATDEV Toolbox</h1>

                <p class="mb-3 text-muted lead" style="max-width: 70ch;">
                    Explore and analyse modelled agricultural and water-management scenarios across study areas in
                    Kenya, Ethiopia, Sudan, and Egypt.
                </p>

                <!-- Logos: hero-like strip (always below intro) -->
                <div class="d-flex flex-wrap align-items-center gap-3 justify-content-center justify-content-lg-start watdev-logos">
                    <img src="assets/img/watdev.avif"
                         alt="WATDEV"
                         class="logo logo-main"
                         loading="lazy" decoding="async">

                    <img src="assets/img/agenzia.avif"
                         alt="Agenzia Italiana per la Cooperazione allo Sviluppo"
                         class="logo logo-partner"
                         loading="lazy" decoding="async">

                    <img src="assets/img/ciheam.avif"
                         alt="CIHEAM Bari"
                         class="logo logo-partner"
                         loading="lazy" decoding="async">

                    <img src="assets/img/eu_funded.avif"
                         alt="Funded by the European Union"
                         class="logo logo-partner"
                         loading="lazy" decoding="async">
                </div>
            </div>

            <hr class="my-3">

            <!-- What the toolbox does -->
            <div class="row g-3">
                <div class="col-12 col-lg-7">
                    <h2 class="h5">What you can do here</h2>
                    <ul class="mb-0">
                        <li><b>Browse existing model runs</b> and see what scenarios are available per study area.</li>
                        <li><b>Analyse results interactively</b> with maps, indicators, and time-series charts.</li>
                        <li><b>Compare scenarios</b> to explore differences across subbasins and crops (where available).</li>
                    </ul>
                </div>

                <div class="col-12 col-lg-5">
                    <div class="p-3 rounded border bg-light">
                        <div class="d-grid gap-2">
                            <a class="btn btn-primary" href="model.php">
                                <i class="bi bi-collection me-1"></i>
                                Overview of model runs
                            </a>
                            <a class="btn btn-outline-primary" href="results.php">
                                <i class="bi bi-graph-up me-1"></i>
                                Analyse / view results
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <hr class="my-3">

            <!-- Project context -->
            <h2 class="h5">About the WATDEV project</h2>
            <p class="mb-2">
                WATDEV (Climate Smart WATer Management and Sustainable DEVelopment for Food and Agriculture in East Africa)
                is funded by the DeSIRA initiative of the European Union. The project supports research, modelling and
                capacity building to strengthen climate resilience and improve water and agricultural resource management
                in East Africa, with a focus on multi-actor engagement and decision support.
            </p>

            <p class="mb-0 small text-muted">
                The WATDEV project is maintained with the financial support of the European Union. Its contents are the sole
                responsibility of the authors and do not necessarily reflect the views of the European Union.
            </p>

        </div>
    </div>

<?php include 'includes/footer.php'; ?>