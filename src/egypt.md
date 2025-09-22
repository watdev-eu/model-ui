---
title: "Results - Egypt"
sidebar: true
---
<div class="controls" style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:center">
  <label>
    Topic
    <select id="topicSelect"></select>
  </label>
  <label>
    Crop
    <select id="cropSelect"></select>
  </label>
  <label>
    Baseline
    <select id="baselineSelect" title="Scenario used as reference for the Δ toggle"></select>
  </label>
  <label style="margin-left:0.5rem">
    <input type="checkbox" id="deltaToggle">
    Show difference vs baseline
  </label>
</div>

<div id="chart" style="height:460px; margin-top:1rem"></div>
<div id="table" style="margin-top:0.5rem"></div>

<!-- libs -->
<script src="https://cdn.jsdelivr.net/npm/d3-dsv@3"></script>
<script src="https://cdn.plot.ly/plotly-2.35.3.min.js"></script>

<!-- your visualization logic -->
<script type="module" src="js/results.js"></script>
<script>
  window.addEventListener('DOMContentLoaded', () => {
    window.initResults({ dataUrl: 'data/egypt/results.csv' });
  });
</script>