<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ML training</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #0b0f14; --panel: #131922; --panel-2: #0f141b; --line: #232b36; --line-2: #1a212b;
            --text: #e6edf3; --muted: #8b98a8; --faint: #5d6b7c;
            --good: #3fb950; --bad: #f85149; --warn: #d29922; --info: #58a6ff; --work: #bc8cff;
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px clamp(14px, 3vw, 32px) 48px; font: 13px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: var(--bg); color: var(--text); }
        h2 { font-size: 11px; margin: 0 0 10px; font-weight: 600; letter-spacing: .09em; text-transform: uppercase; color: var(--muted); }
        h2 small { text-transform: none; letter-spacing: 0; font-weight: 400; color: var(--faint); margin-left: 6px; }
        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 14px 16px; }
        .stack { display: grid; gap: 14px; }
        .cols { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(min(100%, 380px), 1fr)); align-items: start; }
        [hidden] { display: none !important; }
        a { color: var(--info); }

        header { display: flex; flex-wrap: wrap; gap: 10px 18px; align-items: center; margin-bottom: 14px; }
        .title { font-size: 15px; font-weight: 700; }
        .pill { display: inline-flex; align-items: center; gap: 8px; padding: 3px 11px; border-radius: 999px; border: 1px solid var(--line); background: var(--panel); font-size: 12px; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--faint); }
        .pill.running .dot { background: var(--good); animation: blink 1.6s infinite; }
        .pill.alarm { border-color: #5a2a2a; color: #ff9b94; } .pill.alarm .dot { background: var(--bad); animation: blink .8s infinite; }
        .pill.done .dot { background: var(--info); }
        .pill.paused .dot { background: var(--warn); }
        @keyframes blink { 50% { opacity: .3; } }
        .when { margin-left: auto; color: var(--muted); font-size: 12px; }
        .big { font-size: 22px; font-weight: 700; line-height: 1.1; }
        .sub { color: var(--muted); font-size: 12px; }

        .banner { border-radius: 10px; padding: 10px 14px; margin-bottom: 14px; border: 1px solid #5a2a2a; background: #1c1214; color: #ff9b94; }
        .banner.warn { border-color: #5a4a1a; background: #1c1810; color: #e3b341; }
        .banner b { display: block; margin-bottom: 2px; }
        .banner div { color: inherit; opacity: .85; font-size: 12px; overflow-wrap: anywhere; }

        .steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; margin-bottom: 14px; }
        .step { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 8px 10px; border-top: 3px solid var(--line); }
        .step.done { border-top-color: var(--good); } .step.running { border-top-color: var(--info); } .step.failed { border-top-color: var(--bad); }
        .step .n { color: var(--faint); font-size: 11px; } .step .nm { font-weight: 600; }
        .step.running .nm { color: var(--info); } .step.pending .nm { color: var(--muted); }
        .step .sm { color: var(--muted); font-size: 11px; margin-top: 2px; overflow-wrap: anywhere; }

        .bar { height: 7px; background: var(--line); border-radius: 999px; overflow: hidden; }
        .bar > i { display: block; height: 100%; background: var(--info); transition: width .5s; }
        .bar.good > i { background: var(--good); } .bar.bad > i { background: var(--bad); } .bar.warn > i { background: var(--warn); }
        .bar.gate { position: relative; overflow: visible; }
        .bar.gate::after { content: ""; position: absolute; top: -3px; bottom: -3px; width: 2px; left: var(--need); background: var(--text); opacity: .7; }

        .gates { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px; }
        .gate { background: var(--panel-2); border: 1px solid var(--line-2); border-radius: 8px; padding: 10px 12px; }
        .gate .nm { color: var(--muted); font-size: 11px; display: flex; justify-content: space-between; }
        .gate .v { font-size: 22px; font-weight: 700; margin: 2px 0 6px; }
        .gate .v small { color: var(--faint); font-size: 12px; font-weight: 400; }
        .gate .ex { color: var(--faint); font-size: 11px; margin-top: 6px; }
        .st-pass { color: var(--good); } .st-fail { color: var(--bad); } .st-pending { color: var(--faint); }

        .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .kpi { background: var(--panel-2); border: 1px solid var(--line-2); border-radius: 8px; padding: 8px 10px; }
        .kpi b { display: block; font-size: 20px; line-height: 1.15; } .kpi span { color: var(--muted); font-size: 11px; }

        .tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(112px, 1fr)); gap: 6px; }
        .tile { background: var(--panel-2); border: 1px solid var(--line-2); border-radius: 6px; padding: 5px 8px; font-size: 11px; }
        .tile .r { display: flex; justify-content: space-between; color: var(--muted); }
        .tile .r b { color: var(--text); }
        .tile.done { border-color: #1f4a2a; } .tile.bad { border-color: #5a2a2a; background: #1c1214; }
        .tile.wait { opacity: .45; } .tile .bar { height: 4px; margin: 4px 0; }

        .chart { width: 100%; height: 170px; display: block; }
        .legend { display: flex; gap: 14px; flex-wrap: wrap; color: var(--muted); font-size: 11px; margin-top: 4px; }
        .legend i { display: inline-block; width: 10px; height: 3px; margin-right: 5px; vertical-align: middle; }

        .meter { margin-bottom: 11px; }
        .meter .r { display: flex; justify-content: space-between; margin-bottom: 3px; } .meter .r span { color: var(--muted); }
        .spark { width: 100%; height: 34px; display: block; margin-top: 3px; }

        ul.feed { list-style: none; margin: 0; padding: 0; max-height: 330px; overflow: auto; }
        ul.feed li { display: grid; grid-template-columns: 60px 1fr; gap: 8px; padding: 3px 0; border-bottom: 1px solid var(--line-2); }
        ul.feed .t { color: var(--faint); } .feed .good { color: var(--good); } .feed .bad { color: var(--bad); } .feed .info { color: var(--text); }
        table { width: 100%; border-collapse: collapse; } td, th { padding: 4px 6px; border-bottom: 1px solid var(--line-2); text-align: left; }
        th { color: var(--faint); font-size: 11px; font-weight: 600; } td.num { text-align: right; }
    </style>
</head>
<body>
<header>
    <span class="title">ML training</span>
    <span class="pill" id="overall"><span class="dot"></span><span id="overallText">connecting…</span></span>
    <span id="now" class="sub"></span>
    <span class="when"><a href="{{ route('ai.harness.index') }}">build harness</a> · <span id="at">—</span></span>
</header>

<div id="stale" class="banner warn" hidden><b>Status collector is not running</b><div>Start it: <code>python3 Modules/AI/rl/scripts/rl_status.py</code></div></div>
<div id="alarms"></div>
<div class="steps" id="steps"></div>

<div class="stack">
    <section class="panel"><h2>Gates <small>pass/fail against the thresholds in the skill</small></h2><div class="gates" id="gates"></div></section>

    <div class="cols">
        <section class="panel" id="genPanel" hidden>
            <h2>Data generation <small>32 universes × 30 days, 24 accounts, epsilon 0.1</small></h2>
            <div class="kpis" id="genKpis"></div>
            <div class="tiles" id="tiles"></div>
        </section>
        <section class="panel" id="validPanel" hidden>
            <h2>Data validation <small>blocking checks must pass before the GPU trains</small></h2>
            <div class="kpis" id="validKpis"></div>
            <ul class="feed" id="validList" style="max-height:none"></ul>
            <div id="strategy"></div>
        </section>
        <section class="panel" id="trainPanel" hidden>
            <h2>GPU training <small>behaviour cloning</small></h2>
            <div class="kpis" id="trainKpis"></div>
            <svg class="chart" id="trainChart" viewBox="0 0 400 170" preserveAspectRatio="none"></svg>
            <div class="legend"><span><i style="background:var(--info)"></i>val top-1 (3+ legal)</span><span><i style="background:var(--work)"></i>val MRR</span><span><i style="background:var(--warn)"></i>loss</span><span><i style="background:var(--text);opacity:.5"></i>gate</span></div>
            <div id="trainFinal"></div>
        </section>
        <section class="panel" id="loopPanel" hidden>
            <h2>Closed loop <small>model vs planner, twin universes</small></h2>
            <div class="kpis" id="loopKpis"></div>
            <div class="tiles" id="loopTiles"></div>
            <div id="loopReport"></div>
        </section>
        <section class="panel">
            <h2>Machine</h2>
            <div id="machine"></div>
        </section>
        <section class="panel">
            <h2>Events</h2>
            <ul class="feed" id="feed"></ul>
        </section>
    </div>
</div>

<script>
    const url = @json(route('ai.harness.rl.poll'));
    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const num = n => (n ?? 0).toLocaleString('en-US');
    const pct = (a, b) => b ? Math.max(0, Math.min(100, a / b * 100)) : 0;
    const dur = s => s == null ? '—' : s < 90 ? Math.round(s) + 's' : s < 5400 ? Math.round(s / 60) + ' min' : (s / 3600).toFixed(1) + ' h';
    const f3 = v => v == null ? '—' : Number(v).toFixed(3);
    const bar = (value, tone = '') => `<div class="bar ${tone}"><i style="width:${value}%"></i></div>`;

    function line(points, xs, ys, color, w = 400, h = 170) {
        if (points.length < 2) return '';
        const [x0, x1] = xs, [y0, y1] = ys;
        const d = points.map(([x, y], i) => (i ? 'L' : 'M') + ((x - x0) / (x1 - x0 || 1) * w).toFixed(1) + ' ' + (h - (y - y0) / (y1 - y0 || 1) * h).toFixed(1)).join(' ');
        return `<path d="${d}" fill="none" stroke="${color}" stroke-width="2" vector-effect="non-scaling-stroke"/>`;
    }
    function spark(series, idx, max, color) {
        const pts = series.map((r, i) => [i, r[idx]]);
        return `<svg class="spark" viewBox="0 0 400 34" preserveAspectRatio="none">${line(pts, [0, Math.max(pts.length - 1, 1)], [0, max], color, 400, 34)}</svg>`;
    }

    function renderHeader(d) {
        const o = d.overall, cur = d.current;
        $('overall').className = 'pill ' + o;
        $('overallText').textContent = o === 'paused' ? 'paused' : o === 'alarm' ? 'ALARM' : o === 'done' ? 'complete' : o === 'running' ? 'running' : 'idle';
        const g = d.generation, eta = g.eta_seconds;
        $('now').textContent = cur ? `Step ${cur.n}: ${cur.name}` + (cur.n === 4 && eta ? ` · ETA ${dur(eta)}` : '') : '';
        $('at').textContent = 'updated ' + dur(d.age) + ' ago';
        $('stale').hidden = d.age <= d.stale_after;
        $('alarms').innerHTML = d.alarms.map(a => `<div class="banner"><b>${esc(a.where)}: ${esc(a.what)}</b><div>${esc(a.detail)}</div></div>`).join('');
    }

    function renderSteps(d) {
        $('steps').innerHTML = d.steps.map(s => `<div class="step ${s.state}"><div class="n">STEP ${s.n} · ${s.state}</div><div class="nm">${esc(s.name)}</div><div class="sm">${esc(s.summary)}</div></div>`).join('');
    }

    function renderGates(d) {
        $('gates').innerHTML = d.gates.map(g => {
            const isCount = g.need > 1;
            const val = g.value == null ? '—' : isCount ? num(g.value) : (g.name.includes('closed') ? (g.value * 100).toFixed(1) + '%' : f3(g.value));
            const need = isCount ? num(g.need) : g.name.includes('closed') ? '±' + (g.need * 100) + '%' : g.need;
            const fill = g.name.includes('closed') ? (g.value == null ? 0 : 100 - pct(Math.abs(g.value), g.need * 2)) : pct(g.value ?? 0, g.need * (isCount ? 1 : 1));
            const tone = g.state === 'pass' ? 'good' : g.state === 'fail' ? 'bad' : '';
            return `<div class="gate"><div class="nm"><span>${esc(g.name)}</span><span class="st-${g.state}">${g.state}</span></div><div class="v">${val} <small>/ ${need}</small></div>`
                + (g.name.includes('closed') ? '' : `<div class="bar gate ${tone}" style="--need:${isCount ? 100 : g.need * 100}%"><i style="width:${fill}%"></i></div>`)
                + `<div class="ex">${esc(g.what)}${g.extra ? ' · ' + esc(g.extra) : ''}</div></div>`;
        }).join('');
    }

    function renderGeneration(d) {
        const g = d.generation, us = g.universes;
        $('genPanel').hidden = us.length === 0;
        if (!us.length) return;
        const points = us.reduce((a, u) => a + u.multi, 0), done = us.filter(u => u.done).length, errors = us.reduce((a, u) => a + u.errors, 0);
        const active = us.filter(u => !u.done).length;
        $('genKpis').innerHTML = [
            [num(points), 'choice points (2+ legal)'], [done + ' / ' + g.target, 'universes finished'], [active, 'running now'],
            [pct(g.done_days, g.total_days).toFixed(0) + '%', 'sim days played'], [g.eta_seconds ? dur(g.eta_seconds) : '—', 'ETA'], [errors, 'sim errors'],
        ].map(([v, l], i) => `<div class="kpi"><b ${i === 5 && errors ? 'style="color:var(--bad)"' : ''}>${v}</b><span>${l}</span></div>`).join('')
            + `<div style="grid-column:1/-1">${bar(pct(g.done_days, g.total_days))}</div>`;
        const shown = [...us];
        for (let n = us.length + 1; n <= g.target; n++) shown.push({ n, wait: true });
        $('tiles').innerHTML = shown.map(u => u.wait
            ? `<div class="tile wait"><div class="r"><b>#${u.n}</b><span>queued</span></div>${bar(0)}</div>`
            : `<div class="tile ${u.errors ? 'bad' : u.done ? 'done' : ''}"><div class="r"><b>#${u.n}</b><span>${u.partial ? 'cut d' + u.day.toFixed(1) : u.done ? 'done' : 'day ' + u.day.toFixed(1)}</span></div>${bar(pct(u.day, u.days), u.errors ? 'bad' : u.done ? 'good' : '')}<div class="r"><span>${num(u.points)} pts</span><span>${u.errors ? u.errors + ' err' : ''}</span></div></div>`).join('');
    }

    function renderValidation(d) {
        const v = d.validation;
        $('validPanel').hidden = !v;
        if (!v) return;
        $('validKpis').innerHTML = [[v.verdict.toUpperCase(), 'verdict'], [num(v.rows), 'rows checked'], [v.blocking.length, 'blocking'], [v.warnings.length, 'warnings'],
            [v.off_teacher_share == null ? '—' : (v.off_teacher_share * 100).toFixed(1) + '%', 'chosen ≠ teacher (epsilon)'], [v.first_legal_baseline_3plus == null ? '—' : (v.first_legal_baseline_3plus * 100).toFixed(0) + '%', 'trivial baseline, 3+ legal']]
            .map(([x, l], i) => `<div class="kpi"><b ${i === 0 ? `class="st-${v.verdict}"` : ''}>${x}</b><span>${l}</span></div>`).join('');
        const rows = [...v.blocking.map(b => ['bad', b]), ...v.warnings.map(b => ['warn', b])];
        renderStrategy(v);
        $('validList').innerHTML = rows.length ? rows.map(([k, b]) => `<li><span class="t ${k === 'bad' ? 'bad' : ''}" style="color:var(--${k === 'bad' ? 'bad' : 'warn'})">${k === 'bad' ? 'BLOCK' : 'warn'}</span><span>${esc(b.check)} (${num(b.count)})</span></li>`).join('')
            : '<li><span class="t"></span><span class="good">all checks clean</span></li>';
    }

    function renderStrategy(v) {
        const s = v.strategy;
        if (!s) { $('strategy').innerHTML = ''; return; }
        const days = Object.entries(s.growth);
        const picks = Object.entries(s.no_value_picks ?? {}).map(([k, n]) => `${esc(k)} ${n}`).join(' · ');
        $('strategy').innerHTML = '<h2 style="margin-top:14px">Strategy sanity <small>does the play look like a player, not just legal</small></h2>'
            + (days.length ? '<table><tr><th>day</th><th class="num">p10</th><th class="num">median</th><th class="num">p90</th></tr>'
                + days.map(([k, g]) => `<tr><td>${k}</td><td class="num">${num(Math.round(g.p10))}</td><td class="num">${num(Math.round(g.median))}</td><td class="num">${num(Math.round(g.p90))}</td></tr>`).join('') + '</table>' : '')
            + `<table style="margin-top:6px"><tr><td>accounts stalled (&lt;1.5× day 3→9)</td><td class="num">${s.stalled_share == null ? '—' : (s.stalled_share * 100).toFixed(1) + '%'} of ${s.accounts_judged}</td></tr>`
            + `<tr><td>no-value pick while a payback&lt;2h mine was affordable</td><td class="num">${s.no_value_pick_share == null ? '—' : (s.no_value_pick_share * 100).toFixed(1) + '%'} of ${num(s.cheap_mine_available)}</td></tr>`
            + (picks ? `<tr><td>which objects</td><td class="num">${picks}</td></tr>` : '') + '</table>'
            + '<div class="sub" style="margin-top:6px">Account value by day. Review sheet: storage/rl/review.md (40 decisions to judge by hand).</div>';
    }

    function renderTraining(d) {
        const t = d.training, ep = t.epochs;
        $('trainPanel').hidden = !(ep.length || t.header.length);
        if (!ep.length && !t.header.length) return;
        const last = ep[ep.length - 1] ?? {};
        $('trainKpis').innerHTML = [[`${ep.length} / ${t.total}`, 'epochs'], [f3(last.val_top1_3plus), 'top-1 (3+ legal)'], [f3(last.val_mrr), 'MRR'], [f3(last.loss), 'loss'], [last.seconds ? last.seconds + ' s' : '—', 'per epoch'],
            [d.machine.gpu.util != null ? d.machine.gpu.util + '%' : '—', 'GPU util']]
            .map(([v, l]) => `<div class="kpi"><b>${v}</b><span>${l}</span></div>`).join('') + `<div style="grid-column:1/-1">${bar(pct(ep.length, t.total))}</div>`;
        const xs = [1, Math.max(t.total, 2)], ys = [0.5, 1];
        const maxLoss = Math.max(...ep.map(e => e.loss), 0.001);
        const gateLine = v => `<line x1="0" x2="400" y1="${170 - (v - 0.5) / 0.5 * 170}" y2="${170 - (v - 0.5) / 0.5 * 170}" stroke="var(--text)" stroke-opacity=".35" stroke-dasharray="4 4" vector-effect="non-scaling-stroke"/>`;
        $('trainChart').innerHTML = gateLine(0.9) + gateLine(0.93)
            + line(ep.map(e => [e.epoch, e.val_top1_3plus ?? e.val_top1]), xs, ys, 'var(--info)')
            + line(ep.map(e => [e.epoch, e.val_mrr]), xs, ys, 'var(--work)')
            + line(ep.map(e => [e.epoch, 0.5 + e.loss / maxLoss * 0.5]), xs, ys, 'var(--warn)');
        const m = t.metrics;
        $('trainFinal').innerHTML = m ? `<table style="margin-top:10px"><tr><td>parameters</td><td class="num">${num(m.parameters)}</td></tr><tr><td>val top-1 · baseline random</td><td class="num">${f3(m.top1)} · ${f3(m.baseline)}</td></tr><tr><td>"first legal row" baseline (3+ legal)</td><td class="num">${f3(m.baseline_first)}</td></tr><tr><td><b>top-1 where teacher ≠ first legal</b> (${m.hard_share == null ? '—' : (m.hard_share * 100).toFixed(1) + '% of rows'})</td><td class="num"><b>${f3(m.top1_hard)}</b></td></tr>`
            + Object.entries(m.weakest).map(([k, v]) => `<tr><td>${esc(k)}</td><td class="num">${esc(typeof v === 'number' ? f3(v) : JSON.stringify(v))}</td></tr>`).join('') + '</table>' : '';
    }

    function renderLoop(d) {
        const l = d.closed_loop, ps = l.pairs;
        $('loopPanel').hidden = ps.length === 0;
        if (!ps.length) return;
        const finished = ps.filter(p => p.teacher?.done && p.policy?.done).length;
        const fell = ps.reduce((a, p) => a + (p.policy?.fell_back || 0), 0), errors = ps.reduce((a, p) => a + (p.teacher?.errors || 0) + (p.policy?.errors || 0), 0);
        $('loopKpis').innerHTML = [[finished + ' / ' + l.target, 'pairs finished'], [fell, 'planner fallbacks (need 0)'], [errors, 'sim errors']].map(([v, k]) => `<div class="kpi"><b>${v}</b><span>${k}</span></div>`).join('');
        $('loopTiles').innerHTML = ps.map(p => {
            const a = p.teacher ?? {}, b = p.policy ?? {}, day = Math.max(a.day ?? 0, b.day ?? 0), days = a.days ?? 30;
            return `<div class="tile ${(a.errors || b.errors) ? 'bad' : a.done && b.done ? 'done' : ''}"><div class="r"><b>#${p.n}</b><span>${a.done && b.done ? 'done' : 'day ' + day.toFixed(1)}</span></div>${bar(pct((a.done ? days : a.day ?? 0) + (b.done ? days : b.day ?? 0), days * 2), a.done && b.done ? 'good' : '')}</div>`;
        }).join('');
        const arch = l.report?.by_archetype ?? {};
        $('loopReport').innerHTML = l.report ? '<table style="margin-top:10px"><tr><th>archetype</th><th class="num">mean ΔV</th><th class="num">n</th><th class="num">better</th></tr>'
            + Object.entries(arch).map(([k, v]) => `<tr><td>${esc(k)}</td><td class="num">${(v.mean * 100).toFixed(1)}%</td><td class="num">${v.n}</td><td class="num">${(v.share_better * 100).toFixed(0)}%</td></tr>`).join('') + '</table>' : '';
    }

    function renderMachine(d) {
        const m = d.machine, g = m.gpu, s = d.series;
        const row = (name, text, value, tone, series) => `<div class="meter"><div class="r"><b>${name}</b><span>${text}</span></div>${bar(value, tone)}${series}</div>`;
        $('machine').innerHTML =
            row('CPU load', `${m.load.toFixed(1)} / ${m.cpus} cores`, pct(m.load, m.cpus), m.load > m.cpus ? 'warn' : '', spark(s, 1, m.cpus, 'var(--info)'))
            + row('RAM', `${(m.mem_used_mb / 1024).toFixed(1)} / ${(m.mem_total_mb / 1024).toFixed(0)} GB`, pct(m.mem_used_mb, m.mem_total_mb), pct(m.mem_used_mb, m.mem_total_mb) > 88 ? 'bad' : '', spark(s, 3, m.mem_total_mb, 'var(--work)'))
            + (g.name ? row('GPU util', `${g.util}% · ${esc(g.name)}`, g.util, 'good', spark(s, 2, 100, 'var(--good)'))
                + row('GPU memory', `${(g.mem_used / 1024).toFixed(1)} / ${(g.mem_total / 1024).toFixed(0)} GB · ${g.temp}°C · ${Math.round(g.power)} W`, pct(g.mem_used, g.mem_total), '', spark(s, 4, g.mem_total, 'var(--warn)')) : '<div class="sub">GPU not readable</div>')
            + `<div class="sub">disk free ${m.disk_free_gb} GB</div>`;
    }

    function render(d) {
        if (d.missing) { $('overallText').textContent = 'no status yet'; $('stale').hidden = false; return; }
        renderHeader(d); renderSteps(d); renderGates(d); renderGeneration(d); renderValidation(d); renderTraining(d); renderLoop(d); renderMachine(d);
        $('feed').innerHTML = d.events.slice(0, 40).map(e => `<li><span class="t">${esc(e.t)}</span><span class="${e.kind}">${esc(e.text)}</span></li>`).join('');
    }

    async function tick() {
        try { render(await (await fetch(url, { cache: 'no-store' })).json()); }
        catch (e) { $('overallText').textContent = 'page cannot reach the app'; }
    }
    tick(); setInterval(tick, 3000);
</script>
</body>
</html>
