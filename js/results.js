window.initResults = function initResults({ dataUrl }) {
    const els = {
        topic: document.getElementById('topicSelect'),
        crop: document.getElementById('cropSelect'),
        baseline: document.getElementById('baselineSelect'),
        delta: document.getElementById('deltaToggle'),
        chart: document.getElementById('chart'),
        table: document.getElementById('table'),
    };

    let rows = [];
    let scenarios = [];

    fetch(dataUrl)
        .then(r => r.text())
        .then(txt => {
            rows = d3.dsvFormat(',').parse(txt);
            scenarios = Object.keys(rows[0]).filter(k => k !== 'Topic' && k !== 'Crop');

            // Populate Topic select
            const topics = [...new Set(rows.map(r => r.Topic))].sort();
            fillSelect(els.topic, topics);

            // Baseline = first scenario by default
            fillSelect(els.baseline, scenarios);

            // Handlers
            els.topic.addEventListener('change', onTopicChange);
            els.crop.addEventListener('change', draw);
            els.baseline.addEventListener('change', draw);
            els.delta.addEventListener('change', draw);

            // Initial cascade
            onTopicChange();
        })
        .catch(err => {
            console.error('Failed to load data:', err);
            els.chart.innerHTML = '<p style="color:#b00">Failed to load data.</p>';
        });

    function onTopicChange() {
        const topic = els.topic.value;
        const crops = [...new Set(rows.filter(r => r.Topic === topic).map(r => r.Crop))].sort();
        fillSelect(els.crop, crops);
        draw();
    }

    function draw() {
        const topic = els.topic.value;
        const crop = els.crop.value;
        const baseline = els.baseline.value;
        const showDelta = els.delta.checked;

        // find row for (topic, crop)
        const row = rows.find(r => r.Topic === topic && r.Crop === crop);
        if (!row) {
            Plotly.purge(els.chart);
            els.chart.innerHTML = '<p>No data for this selection.</p>';
            els.table.innerHTML = '';
            return;
        }

        // build arrays
        const xs = [];
        const ys = [];
        const bs = parseNum(row[baseline]);

        scenarios.forEach(sc => {
            const v = parseNum(row[sc]);
            if (Number.isFinite(v)) {
                xs.push(sc);
                ys.push(showDelta ? (Number.isFinite(bs) ? v - bs : NaN) : v);
            }
        });

        // filter out any NaNs created by delta calc
        const filtered = xs.map((x, i) => ({ x, y: ys[i] })).filter(d => Number.isFinite(d.y));

        const trace = {
            type: 'bar',
            x: filtered.map(d => d.x),
            y: filtered.map(d => d.y),
            hovertemplate: '%{x}<br>%{y:.2f}<extra></extra>',
        };

        const title = showDelta
            ? `${topic} — ${crop} (Δ vs "${baseline}")`
            : `${topic} — ${crop}`;

        Plotly.newPlot(els.chart, [trace], {
            title,
            margin: { t: 40, r: 10, b: 90, l: 50 },
            xaxis: { tickangle: -20 },
            yaxis: { title: showDelta ? 'Difference (value units)' : 'Value (units)' },
        }, { displayModeBar: false, responsive: true });

        // table of numbers
        els.table.innerHTML = renderTable(row, scenarios, showDelta ? baseline : null);
    }

    function fillSelect(sel, values) {
        sel.innerHTML = values.map(v => `<option value="${escapeAttr(v)}">${escapeHtml(v)}</option>`).join('');
    }

    function renderTable(row, scenarioCols, baselineName) {
        const base = baselineName ? parseNum(row[baselineName]) : undefined;
        const header = `
      <table class="table table-sm" style="width:100%; border-collapse:collapse">
        <thead>
          <tr>
            <th style="text-align:left; border-bottom:1px solid #ddd">Scenario</th>
            <th style="text-align:right; border-bottom:1px solid #ddd">${baselineName ? 'Δ vs baseline' : 'Value'}</th>
          </tr>
        </thead>
        <tbody>
    `;
        const body = scenarioCols.map(sc => {
            const v = parseNum(row[sc]);
            const y = baselineName && Number.isFinite(base) ? v - base : v;
            if (!Number.isFinite(y)) return '';
            return `
        <tr>
          <td style="padding:4px 6px">${escapeHtml(sc)}</td>
          <td style="padding:4px 6px; text-align:right">${fmt(y)}</td>
        </tr>`;
        }).join('');
        const footer = `</tbody></table>`;
        return header + body + footer;
    }

    function parseNum(v) {
        if (v === null || v === undefined) return NaN;
        const n = typeof v === 'number' ? v : parseFloat(String(v).replace(',', '.'));
        return Number.isFinite(n) ? n : NaN;
    }
    const fmt = n => Math.abs(n) >= 1000 ? n.toFixed(0) : n.toFixed(2);
    const escapeHtml = s => String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    const escapeAttr = s => escapeHtml(s).replace(/"/g, '&quot;');
}