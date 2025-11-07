<?php
$pageTitle = 'Model run';
$pageButtons = [];
require_once 'includes/layout.php';
?>
    <div class="card mb-3">
        <div class="card-body">
            <h1 class="title">Current runs</h1>

            <?php
            // Mock data
            $modelRuns = [
                    ['label' => 'Baseline v1.0',              'started_at' => new DateTime('2025-09-18 14:22'), 'started_by' => 'Rik Oudega', 'status' => 'completed'],
                    ['label' => 'Scenario A — high inflow',   'started_at' => new DateTime('2025-09-19 09:05'), 'started_by' => 'Jantiene',   'status' => 'running'],
                    ['label' => 'Stress test — long horizon', 'started_at' => new DateTime('2025-09-17 20:41'), 'started_by' => 'Paul',        'status' => 'failed'],
                    ['label' => 'Calibration set 2024-08',    'started_at' => new DateTime('2025-09-16 08:12'), 'started_by' => 'Rik Oudega', 'status' => 'completed'],
                    ['label' => 'Sensitivity sweep #42',      'started_at' => new DateTime('2025-09-19 08:47'), 'started_by' => 'Guest',      'status' => 'running'],
            ];

            // Sort newest first
            usort($modelRuns, fn($a, $b) => $b['started_at'] <=> $a['started_at']);

            function renderStatusBadge(string $status): string {
                $status = strtolower($status);
                switch ($status) {
                    case 'completed':
                        return '<span class="badge bg-success">Completed</span>';
                    case 'failed':
                        return '<span class="badge bg-danger">Failed</span>';
                    case 'running':
                        return '<span class="badge bg-warning text-dark">
                                    <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                    Running
                                </span>';
                    default:
                        return '<span class="badge bg-secondary">'.htmlspecialchars(ucfirst($status)).'</span>';
                }
            }
            ?>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th scope="col">Run</th>
                        <th scope="col">Started</th>
                        <th scope="col">User</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($modelRuns as $run): ?>
                        <tr>
                            <td class="fw-medium"><?= htmlspecialchars($run['label']) ?></td>
                            <td><?= htmlspecialchars($run['started_at']->format('Y-m-d H:i')) ?></td>
                            <td><?= htmlspecialchars($run['started_by']) ?></td>
                            <td><?= renderStatusBadge($run['status']) ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary" title="Demo only — no action" disabled>
                                    Show details
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h1 class="title">Request a new model run</h1>
            <p>Provide the relevant properties to start a new model run.</p>
            <form>
                <div class="form-group">
                    <p><label for="md-name">Model run identifier</label> <input type="text" class="form-control" id="md-name" placeholder="label for the modelrun"></p>
                </div>
                <div class="form-group">
                    <p><label for="model-area">Select modelling area</label> <select class="form-control" id="model-area"> <option>Egypt</option> <option>Ethiopia</option> <option>Kenya</option> <option>Sudan</option> </select></p>
                </div>
                <div class="form-group">
                    <p><label for="model-region">Select HRU</label> <select class="form-control" id="model-region"> <option>UX345</option> <option>IY783</option> <option>SD453</option> <option>RE432</option> </select></p>
                </div>
                <div class="form-group">
                    <p><label for="model-region">Select Model</label> <select class="form-control" id="model-mdl"> <option>SWAT-Modflow</option> <option>DSSAT</option> </select></p>
                </div>
                <div class="form-group">
                    <label for="period-from">Period</label>
                    <div class="row">
                        <div class="col">
                            <input class="datepicker form-control" data-provide="datepicker" placeholder="start" id="period-from" data-date-format="mm/dd/yyyy">
                        </div>
                        <div class="col">
                            <input class="datepicker form-control" data-provide="datepicker" placeholder="end" id="period-to" data-date-format="mm/dd/yyyy">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <p><label for="model-ts">Time step</label> <select class="form-control" id="model-ts"> <option>2hr</option> <option>8hr</option> <option>24hr</option> <option>48hr</option> <option>96hr</option> </select></p>
                </div>
                <div class="form-group">
                    <p><label for="model-lm"> Land management</label> <select class="form-control" id="model-lm"> <option>Low</option> <option>Medium</option> <option>High</option> </select></p>
                </div>
                <div class="form-group">
                    <p><label for="model-sc">Scenario</label> <select class="form-control" id="model-sc"> <option>Fertility</option> <option>Erosion</option> <option>Drought</option> <option>Pests/Deseases</option> </select></p>
                </div>
                <div class="form-group">
                    <label>Interventions (BMP)</label><br>
                    <div class="form-check form-check-inline">
                        <p><input class="form-check-input" type="checkbox" id="inlineCheckbox1" value="option1"> <label class="form-check-label" for="inlineCheckbox1">Drip irrigation</label></p>
                    </div>
                    <div class="form-check form-check-inline">
                        <p><input class="form-check-input" type="checkbox" id="inlineCheckbox2" value="option2"> <label class="form-check-label" for="inlineCheckbox2">Gully irrigation</label></p>
                    </div>
                    <div class="form-check form-check-inline">
                        <p><input class="form-check-input" type="checkbox" id="inlineCheckbox3" value="option3"> <label class="form-check-label" for="inlineCheckbox3">Ridges</label></p>
                    </div>
                </div>
                <button onclick="go()" class="btn btn-primary">
                    Next
                </button>
            </form>
            <div id="app">

            </div>
            <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>
            <script src="
https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.10.0/dist/js/bootstrap-datepicker.min.js
"></script>
            <p><link href="
https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.10.0/dist/css/bootstrap-datepicker3.min.css
" rel="stylesheet"></p>
        </div>
    </div>

<?php include 'includes/footer.php'; ?>