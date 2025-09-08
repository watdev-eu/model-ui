---
title: "Request a new model run"
sidebar: true
---

Provide the relevant properties to start a new model run.

<form>

<div class="form-group">
<label for="md-name">Model run identifier</label>
<input type="text" class="form-control" id="md-name" placeholder="label for the modelrun">
</div>

<div class="form-group">
<label for="model-area">Select modelling area</label>
<select class="form-control" id="model-area">
<option>Egypt</option>
<option>Ethiopia</option>
<option>Kenya</option>
<option>Sudan</option>
</select>
</div>

<div class="form-group">
<label for="model-region">Select HRU</label>
<select class="form-control" id="model-region">
<option>UX345</option>
<option>IY783</option>
<option>SD453</option>
<option>RE432</option>
</select>
</div>

<div class="form-group">
<label for="model-region">Select Model</label>
<select class="form-control" id="model-mdl">
<option>SWAT-Modflow</option>
<option>DSSAT</option>
</select>
</div>

<div class="form-group">
<label for="period-from">Period</label>
<div class="row">

<div class="col"><input class="datepicker form-control" data-provide="datepicker" placeholder="start" id="period-from" data-date-format="mm/dd/yyyy"></div>

<div class="col"><input class="datepicker form-control" data-provide="datepicker" placeholder="end" id="period-to" data-date-format="mm/dd/yyyy"></div>
</div>
</div>

<div class="form-group">
<label for="model-ts">Time step</label>
<select class="form-control" id="model-ts">
<option>2hr</option>
<option>8hr</option>
<option>24hr</option>
<option>48hr</option>
<option>96hr</option>
</select>
</div>

<div class="form-group">
<label for="model-lm">
Land management</label>
<select class="form-control" id="model-lm">
<option>Low</option>
<option>Medium</option>
<option>High</option>
</select>
</div>

<div class="form-group">
<label for="model-sc">Scenario</label>
<select class="form-control" id="model-sc">
<option>Fertility</option>
<option>Erosion</option>
<option>Drought</option>
<option>Pests/Deseases</option>
</select>
</div>

<div class="form-group">
<label>Interventions (BMP)</label><br/>
<div class="form-check form-check-inline">
<input class="form-check-input" type="checkbox" id="inlineCheckbox1" value="option1">
<label class="form-check-label" for="inlineCheckbox1">Drip irrigation</label>
</div>
<div class="form-check form-check-inline">
<input class="form-check-input" type="checkbox" id="inlineCheckbox2" value="option2">
<label class="form-check-label" for="inlineCheckbox2">Gully irrigation</label>
</div>
<div class="form-check form-check-inline">
<input class="form-check-input" type="checkbox" id="inlineCheckbox3" value="option3">
<label class="form-check-label" for="inlineCheckbox3">Ridges</label>
</div>
</div>


<button onclick="go()" class="btn btn-primary">Next</button>

</form>

<div id="app"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>
<script src="
https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.10.0/dist/js/bootstrap-datepicker.min.js
"></script>
<link href="
https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.10.0/dist/css/bootstrap-datepicker3.min.css
" rel="stylesheet">


<script src='js/model.js'></script>
