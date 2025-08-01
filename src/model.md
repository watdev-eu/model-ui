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

<button onclick="go()" class="btn btn-primary">Next</button>

</form>




<div id="app"></div>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js" integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src='js/model.js'></script>