<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI harness</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #0b0f14; --panel: #131922; --panel-2: #0f141b; --line: #232b36; --line-2: #1a212b;
            --text: #e6edf3; --muted: #8b98a8; --faint: #5d6b7c;
            --good: #3fb950; --bad: #f85149; --warn: #d29922; --info: #58a6ff; --work: #bc8cff;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 20px clamp(14px, 3vw, 32px) 48px;
            font: 13px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            background: var(--bg); color: var(--text);
        }
        h2 { font-size: 11px; margin: 0 0 10px; font-weight: 600; letter-spacing: .09em; text-transform: uppercase; color: var(--muted); }
        h2 small { text-transform: none; letter-spacing: 0; font-weight: 400; color: var(--faint); margin-left: 6px; }
        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 14px 16px; }
        .stack { display: grid; gap: 14px; }
        .cols { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr)); align-items: start; }
        [hidden] { display: none !important; }

        /* header */
        header { display: flex; flex-wrap: wrap; gap: 10px 20px; align-items: center; margin-bottom: 14px; }
        .title { font-size: 15px; font-weight: 700; }
        .state { display: inline-flex; align-items: center; gap: 8px; padding: 3px 11px; border-radius: 999px; border: 1px solid var(--line);
            background: var(--panel); font-size: 12px; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--faint); }
        .state.live .dot { background: var(--good); animation: blink 1.6s infinite; }
        .state.parked .dot { background: var(--warn); }
        .state.stopped .dot { background: var(--bad); }
        @keyframes blink { 50% { opacity: .3; } }
        .when { color: var(--muted); font-size: 12px; margin-left: auto; }

        /* north star */
        .star-head { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; margin-bottom: 10px; }
        .star-num { font-size: 26px; font-weight: 700; line-height: 1; }
        .star-num span { color: var(--faint); font-weight: 400; font-size: 16px; }
        .bar { flex: 1 1 140px; height: 6px; background: var(--line); border-radius: 999px; overflow: hidden; min-width: 100px; }
        .bar > i { display: block; height: 100%; background: var(--good); transition: width .4s; }
        .chips { display: flex; flex-wrap: wrap; gap: 6px; }
        .chip { padding: 3px 9px; border-radius: 6px; font-size: 12px; border: 1px solid var(--line); background: var(--panel-2); cursor: default; }
        .chip.fail { border-color: #5a2a2a; color: #ff9b94; background: #1c1214; }
        .chip.pass { color: var(--good); border-color: #1f4a2a; background: #0e1a13; }
        .story { display: grid; grid-template-columns: 1fr auto; gap: 2px 12px; padding: 8px 0; border-top: 1px solid var(--line); }
        .story:first-child { border-top: 0; }
        .story .what { font-weight: 600; }
        .story .what::before { content: "✗ "; color: var(--bad); }
        .story .rows-of { color: var(--muted); font-size: 12px; white-space: nowrap; }
        .story details { grid-column: 1 / -1; color: var(--muted); font-size: 12px; }
        .story details summary { cursor: pointer; }
        .story details p { margin: 4px 0 0; white-space: pre-wrap; word-break: break-word; }
        .passing-stories { margin-top: 8px; color: var(--muted); font-size: 12px; }
        .passing-stories li { margin: 2px 0; }
        .passing-stories li::before { content: "✓ "; color: var(--good); }
        .passing-stories ul { list-style: none; padding: 0; margin: 6px 0 0; }
        .chip b { font-weight: 600; }
        .chip em { font-style: normal; color: var(--muted); margin-left: 4px; }

        /* counters */
        .counters { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; }
        .counter { background: var(--panel-2); border: 1px solid var(--line-2); border-radius: 8px; padding: 10px 12px; }
        .counter .n { font-size: 24px; font-weight: 700; line-height: 1.1; }
        .counter .l { font-size: 11px; color: var(--muted); margin-top: 2px; }
        .counter.good .n { color: var(--good); } .counter.warn .n { color: var(--warn); } .counter.bad .n { color: var(--bad); }
        .counter.zero .n { color: var(--faint); }

        /* rows table */
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 11px; font-weight: 600; color: var(--faint); padding: 4px 8px; border-bottom: 1px solid var(--line); white-space: nowrap; }
        td { padding: 6px 8px; border-bottom: 1px solid var(--line-2); vertical-align: top; }
        tr:last-child td { border-bottom: 0; }
        td.code { color: var(--info); white-space: nowrap; }
        td.num { text-align: right; white-space: nowrap; color: var(--muted); }
        td.why { color: var(--muted); max-width: 520px; overflow-wrap: anywhere; }
        td.ttl { color: var(--text); }
        .pill { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 11px; border: 1px solid var(--line); white-space: nowrap; }
        .pill.writing { color: var(--work); border-color: #4a3a6e; }
        .pill.delivered { color: var(--good); border-color: #1f4a2a; }
        .pill.retrying { color: var(--info); border-color: #1f3a5a; }
        .pill.cooling { color: var(--warn); border-color: #5a4a1a; }
        .pill.stuck { color: var(--bad); border-color: #5a2a2a; }
        .pri { font-size: 11px; color: var(--faint); margin-right: 6px; }
        .pri.P0 { color: var(--bad); } .pri.P1 { color: var(--warn); }
        .scroll { overflow-x: auto; }
        .empty { color: var(--faint); padding: 6px 0; }
        .note { color: var(--muted); font-size: 12px; margin-top: 8px; }

        /* writer + cohort */
        .kv { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 8px 14px; margin-bottom: 8px; }
        .kv div b { display: block; font-size: 18px; }
        .kv div span { color: var(--muted); font-size: 11px; }
        ul.plain { list-style: none; margin: 0; padding: 0; }
        ul.plain li { display: flex; gap: 10px; padding: 3px 0; border-bottom: 1px solid var(--line-2); }
        ul.plain li:last-child { border-bottom: 0; }
        ul.plain .t { color: var(--faint); }
        ul.plain .r { margin-left: auto; color: var(--muted); }
        .ok { color: var(--good); } .no { color: var(--bad); } .wn { color: var(--warn); }

        /* log */
        .bar-tools { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 8px; }
        .bar-tools .grow { flex: 1 1 auto; }
        button, select, input[type=search] {
            background: var(--panel-2); color: var(--text); border: 1px solid var(--line); border-radius: 6px; padding: 4px 10px; font: inherit; cursor: pointer;
        }
        input[type=search] { min-width: 200px; cursor: text; }
        button.on { border-color: var(--info); color: var(--info); }
        button:focus-visible, select:focus-visible, input:focus-visible, summary:focus-visible { outline: 2px solid var(--info); outline-offset: 2px; }
        #log { height: min(62vh, 640px); overflow: auto; background: var(--panel-2); border: 1px solid var(--line-2); border-radius: 8px; padding: 6px 0; }
        .ln { display: grid; grid-template-columns: 62px 1fr; gap: 10px; padding: 1px 12px; white-space: pre-wrap; overflow-wrap: anywhere; color: #aab6c4; }
        .ln:hover { background: #151c26; }
        .ln .t { color: var(--faint); user-select: none; }
        .ln.stage { color: var(--text); font-weight: 600; background: #111a25; margin-top: 4px; }
        .ln.pass { color: var(--good); } .ln.fail { color: #ff8b84; } .ln.work { color: var(--work); }
        .ln.plain { color: var(--muted); }

        /* ledger */
        details > summary { cursor: pointer; list-style: none; display: flex; align-items: center; gap: 10px; }
        details > summary::-webkit-details-marker { display: none; }
        details > summary::before { content: "▸"; color: var(--faint); }
        details[open] > summary::before { content: "▾"; }
        details > summary h2 { margin: 0; }
        .kanban { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; max-height: 60vh; overflow: auto; margin-top: 10px; }
        .column { background: var(--panel-2); border: 1px solid var(--line-2); border-radius: 8px; padding: 8px; }
        .kcard { background: var(--panel); border: 1px solid var(--line-2); border-left: 3px solid var(--line); border-radius: 6px; padding: 6px 8px; margin-top: 6px; }
        .kcard.ready { border-left-color: var(--good); }
        .kcard .c { color: var(--info); margin-right: 6px; }
        .kcard .w { color: var(--warn); font-size: 11px; }
        .ledger { max-height: 60vh; overflow: auto; margin-top: 10px; }
        .ledger th { position: sticky; top: 0; background: var(--panel); cursor: pointer; }
    </style>
</head>
<body>
<header>
    <div class="title">AI harness</div>
    <div id="state" class="state"><span class="dot"></span><span id="stateText">connecting…</span></div>
    <div id="nextTry" class="state" hidden></div>
    <div class="when"><span id="at">—</span> · UTC log</div>
</header>

<div class="stack">
    <!-- 1. The goal. Everything else on the page exists to move this number. -->
    <section class="panel" aria-label="North star">
        <div class="star-head">
            <div class="star-num"><b id="starN">—</b><span> / <span id="starT">15</span> aspects of a player's day</span></div>
            <div class="bar" aria-hidden="true"><i id="starBar" style="width:0"></i></div>
            <div class="note" id="starAt" style="margin:0"></div>
        </div>
        <div class="chips" id="chips"></div>
    </section>

    <!-- 1b. The behaviour board: what an account does today, story by story, seconds old. -->
    <section class="panel" id="storiesPanel" aria-label="Behaviour board" hidden>
        <h2>Behaviour board <small id="storiesMeta"></small></h2>
        <div id="storiesFailing"></div>
        <details class="passing-stories" id="storiesPassing"><summary></summary><ul></ul></details>
    </section>

    <!-- 2. What the harness has produced. Zero values go dim; stuck only draws the eye when it is not zero. -->
    <section class="counters" aria-label="Output">
        <div class="counter" id="cProven"><div class="n">—</div><div class="l">proven (the proof passed)</div></div>
        <div class="counter" id="cDelivered"><div class="n">—</div><div class="l">delivered, awaiting live proof</div></div>
        <div class="counter" id="cReady"><div class="n">—</div><div class="l">ready to attempt now</div></div>
        <div class="counter" id="cCooling"><div class="n">—</div><div class="l">cooling after 3 failed attempts</div></div>
        <div class="counter" id="cStuck" hidden><div class="n">—</div><div class="l">stuck: same failure twice</div></div>
    </section>

    <!-- 3. The working surface: every row the harness is on, and what happened to it. -->
    <section class="panel" aria-label="Rows in play">
        <h2>Rows in play <small id="rowsMeta"></small></h2>
        <div class="scroll"><table>
            <thead><tr><th>row</th><th>state</th><th>tries</th><th>last</th><th>what happened</th></tr></thead>
            <tbody id="rows"></tbody>
        </table></div>
        <div class="empty" id="rowsEmpty" hidden>No row is being worked, delivered or cooling. The harness is waiting for work that moves a failing aspect.</div>
        <div class="note" id="waitingNote" hidden></div>
    </section>

    <div class="cols">
        <section class="panel" aria-label="Writer">
            <h2>Writer <small>last hour</small></h2>
            <div class="kv" id="writerKv"></div>
            <ul class="plain" id="writerRecent"></ul>
            <div class="empty" id="writerEmpty" hidden>No model call in the last hour.</div>
        </section>

        <section class="panel" aria-label="Cohort">
            <h2>Grand cohort <small id="cohortAt"></small></h2>
            <div id="cohortBody"></div>
        </section>

        <section class="panel" id="nowPanel" aria-label="Running now" hidden>
            <h2>Running now</h2>
            <ul class="plain" id="now"></ul>
        </section>

        <section class="panel" id="claimsPanel" aria-label="Claims" hidden>
            <h2>File claims <small>a claim held for long blocks other writers</small></h2>
            <ul class="plain" id="claims"></ul>
        </section>
    </div>

    <!-- 4. The output: the last hour, persisted by scripts/harness-log.py. -->
    <section class="panel" aria-label="Harness output">
        <h2>Harness output <small id="logMeta"></small></h2>
        <div class="bar-tools">
            <button type="button" data-kind="signal" class="on" title="stages, attempts, passes and failures">signal</button>
            <button type="button" data-kind="fail" title="failures, refusals, errors">failures <span id="nFail"></span></button>
            <button type="button" data-kind="work" title="writer attempts">attempts <span id="nWork"></span></button>
            <button type="button" data-kind="pass" title="passes and deliveries">passes <span id="nPass"></span></button>
            <button type="button" data-kind="all" title="every line, including test output">all <span id="nAll"></span></button>
            <input id="logSearch" type="search" placeholder="filter lines…" autocomplete="off" aria-label="Filter log lines">
            <span class="grow"></span>
            <select id="logMinutes" aria-label="Time window">
                <option value="15">15 min</option><option value="60" selected>1 hour</option><option value="180">3 hours</option>
            </select>
            <button type="button" id="follow" class="on" aria-pressed="true">following</button>
        </div>
        <div id="log" role="log" aria-live="off" tabindex="0"></div>
    </section>

    <!-- 5. The backlog, closed by default: it is reference, not news. -->
    <section class="panel" aria-label="Task ledger">
        <details id="ledgerBox">
            <summary><h2>Task ledger <small id="ledgerMeta">open rows only</small></h2></summary>
            <div class="bar-tools" style="margin-top:10px">
                <input id="ledgerSearch" type="search" placeholder="search code, title, notes, file…" autocomplete="off" aria-label="Search the ledger">
                <label><input type="checkbox" id="ledgerAll"> include done and deferred</label>
                <span class="grow"></span>
                <button id="tabKanban" type="button" class="on">board</button>
                <button id="tabTable" type="button">table</button>
            </div>
            <div class="kanban" id="kanban"></div>
            <div class="ledger scroll" id="ledgerWrap" hidden></div>
            <div class="note">Read-only: a row's status belongs to the ledger, not to this page.</div>
        </details>
    </section>
</div>

<script>
    const urls = { poll: @json(route('ai.harness.poll')), log: @json(route('ai.harness.log')), tasks: @json(route('ai.harness.tasks')) };
    const $ = id => document.getElementById(id);
    const esc = text => String(text ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    const plural = (n, one, many) => n + ' ' + (n === 1 ? one : many);
    const human = seconds => {
        if (seconds === null || seconds === undefined) return '—';
        if (seconds < 90) return Math.round(seconds) + 's';
        if (seconds < 5400) return Math.round(seconds / 60) + 'm';
        return (seconds / 3600).toFixed(1) + 'h';
    };
    const counter = (id, value, tone, hideAtZero = false) => {
        const element = $(id);
        element.querySelector('.n').textContent = value;
        element.className = 'counter ' + (value === 0 ? 'zero' : tone);
        element.hidden = hideAtZero && value === 0;
    };

    function renderState(data) {
        const harness = data.harness ?? {};
        const phase = harness.phase ?? '';
        const parked = /park|peak/i.test(phase);
        const el = $('state');
        el.className = 'state ' + (harness.active ? (parked ? 'parked' : 'live') : 'stopped');
        $('stateText').textContent = harness.active
            ? (phase || 'working') + (harness.detail ? ' · ' + harness.detail : '') + ' · ' + human(harness.heartbeat ?? harness.idle) + ' ago'
            : (harness.idle === null ? 'no heartbeat yet' : 'stopped · silent for ' + human(harness.idle));

        const next = data.queue?.nextInMinutes;
        const tag = $('nextTry');
        tag.hidden = !(next !== null && next !== undefined && (data.queue.ready ?? 0) === 0);
        tag.textContent = 'next row free in ' + next + ' min';
        $('at').textContent = data.at;
    }

    function renderStar(star) {
        $('starN').textContent = star.passing;
        $('starT').textContent = star.total;
        $('starBar').style.width = (star.total ? star.passing / star.total * 100 : 0) + '%';
        $('starAt').textContent = star.at ? 'scorecard read ' + new Date(star.at).toISOString().slice(11, 16) + ' UTC · ' + star.hours + 'h window' : 'no scorecard yet';
        $('chips').innerHTML = star.aspects.map(a =>
            '<span class="chip ' + (a.pass ? 'pass' : 'fail') + '" title="' + esc(a.player + ' — ' + a.count + ' seen, needs ' + a.floor) + '">'
            + '<b>' + esc(a.name.replace(/_/g, ' ')) + '</b>' + (a.pass ? '' : '<em>' + a.count + '/' + a.floor + '</em>') + '</span>').join('');
    }

    function renderStories(board) {
        $('storiesPanel').hidden = !board || board.total === 0;
        if (!board || board.total === 0) return;
        $('storiesMeta').textContent = board.passing + ' / ' + board.total + ' pass · run ' + board.at + ' UTC (' + board.age + ' min ago, ' + board.seconds + 's)';
        const failing = board.stories.filter(s => !s.pass), passing = board.stories.filter(s => s.pass);
        $('storiesFailing').innerHTML = failing.map(s =>
            '<div class="story"><span class="what">' + esc(s.story) + '</span><span class="rows-of">' + esc(s.rows.join(', ') || 'no row') + '</span>'
            + '<details><summary>' + esc(s.test) + ' — why</summary><p>' + esc(s.why) + '</p></details></div>').join('');
        const more = $('storiesPassing');
        more.hidden = passing.length === 0;
        more.querySelector('summary').textContent = passing.length + ' stories pass';
        more.querySelector('ul').innerHTML = passing.map(s => '<li>' + esc(s.story) + '</li>').join('');
    }

    function renderQueue(queue) {
        counter('cProven', queue.proven, 'good');
        counter('cDelivered', queue.delivered, 'warn');
        counter('cReady', queue.ready, 'good');
        counter('cCooling', queue.cooling, 'warn');
        counter('cStuck', queue.stuck.length, 'bad', true);
        $('cProven').title = queue.closedBlind + ' older rows were closed before proofs existed and are not counted';
        const waiting = queue.waiting ?? [];
        const note = $('waitingNote');
        note.hidden = waiting.length === 0;
        note.textContent = waiting.length ? plural(waiting.length, 'row is', 'rows are') + ' held back because their aspect already passes: '
            + waiting.map(w => w.code).join(', ') : '';
    }

    function renderRows(rows) {
        $('rowsEmpty').hidden = rows.length > 0;
        $('rows').parentElement.hidden = rows.length === 0;
        $('rowsMeta').textContent = rows.length ? plural(rows.length, 'row', 'rows') : '';
        $('rows').innerHTML = rows.map(row => {
            const detail = {
                writing: 'writer is on it now',
                delivered: 'tests and situation pass; waits for the live aspect or invariant',
                stuck: 'same failure twice — needs a human (task.py unstick)',
            }[row.state] ?? ((row.failure || 'failed') + (row.state === 'cooling' ? ' · retries in ' + human(row.coolsIn) : ''));
            return '<tr><td class="code"><span class="pri ' + esc(row.priority) + '">' + esc(row.priority) + '</span>' + esc(row.code) + '</td>'
                + '<td><span class="pill ' + row.state + '">' + row.state + '</span></td>'
                + '<td class="num">' + row.attempts + '</td>'
                + '<td class="num">' + (row.age === null ? '—' : human(row.age) + ' ago') + '</td>'
                + '<td class="why" title="' + esc(row.title) + '">' + esc(detail) + '</td></tr>';
        }).join('');
    }

    function renderWriter(model) {
        $('writerEmpty').hidden = model.calls > 0;
        $('writerKv').hidden = $('writerRecent').hidden = model.calls === 0;
        if (model.calls === 0) return;
        const finished = Math.round((model.calls - model.unfinished) / model.calls * 100);
        const thinking = model.tokens ? Math.round(model.reasoning / model.tokens * 100) : 0;
        $('writerKv').innerHTML =
            '<div><b>' + model.calls + '</b><span>calls</span></div>'
            + '<div><b class="' + (finished < 80 ? 'no' : 'ok') + '">' + finished + '%</b><span>finished cleanly</span></div>'
            + '<div><b>' + Math.round(model.tokens / model.calls / 1000) + 'k</b><span>tokens per call</span></div>'
            + '<div><b class="' + (thinking > 90 ? 'wn' : '') + '">' + thinking + '%</b><span>spent thinking</span></div>';
        $('writerRecent').innerHTML = model.recent.map(call =>
            '<li><span class="t">' + call.at.slice(0, 5) + '</span><span>' + esc(call.code) + '</span>'
            + '<span class="r ' + (call.finish === 'stop' ? '' : 'no') + '">' + (call.finish === 'stop' ? Math.round(call.output / 1000) + 'k tok · ' + Math.round(call.seconds) + 's' : 'cut off (' + esc(call.finish) + ')') + '</span></li>').join('');
    }

    function renderCohort(cohort) {
        $('cohortAt').textContent = cohort.at ? 'read ' + cohort.at : '';
        const body = [];
        if (cohort.violations.length) {
            body.push('<ul class="plain">' + cohort.violations.map(v => '<li><span class="no">' + esc(v.name) + '</span><span class="r">' + plural(v.count, 'account', 'accounts') + '</span></li>').join('') + '</ul>');
        } else if (cohort.at) {
            body.push('<div class="ok">No invariant is violated.</div>');
        }
        if (cohort.saturated.length) body.push('<div class="note">' + esc(cohort.saturated[0]) + '</div>');
        $('cohortBody').innerHTML = body.join('') || '<div class="empty">No cohort read yet.</div>';
    }

    function renderNow(data) {
        const live = (data.workers ?? []).filter(w => w.age < 180 && w.phase !== 'live-verification' && !/^skipped/.test(w.detail));
        const slots = data.modelSlots ?? 0;
        const items = live.map(w => '<li><span class="t">' + human(w.age) + '</span><span>' + esc(w.phase) + '</span><span class="r">' + esc(w.detail) + '</span></li>');
        if (slots) items.unshift('<li><span>' + plural(slots, 'model call', 'model calls') + ' in flight</span></li>');
        $('nowPanel').hidden = items.length === 0;
        $('now').innerHTML = items.join('');

        const claims = (data.claims ?? []).filter(c => c.age > 120);
        $('claimsPanel').hidden = claims.length === 0;
        $('claims').innerHTML = claims.map(c => '<li><span class="t">' + human(c.age) + '</span><span>' + esc(c.key) + '</span></li>').join('');
    }

    function render(data) {
        renderState(data); renderStar(data.northStar); renderStories(data.stories); renderQueue(data.queue); renderRows(data.rows);
        renderWriter(data.model); renderCohort(data.cohort); renderNow(data);
    }

    /* ---- log: the last hour, persisted ---- */
    let logLines = [];
    let logKind = 'signal';
    let logCounts = {};
    let following = true;
    let logTimer = null;

    const KINDS = { signal: ['stage', 'work', 'pass', 'fail'], fail: ['fail'], work: ['work'], pass: ['pass'], all: ['stage', 'work', 'pass', 'fail', 'plain'] };

    function renderLog() {
        const needle = $('logSearch').value.trim().toLowerCase();
        const allowed = KINDS[logKind];
        const shown = logLines.filter(l => allowed.includes(l.kind) && (needle === '' || l.text.toLowerCase().includes(needle)));
        const box = $('log');
        box.innerHTML = shown.length
            ? shown.map(l => '<div class="ln ' + l.kind + '"><span class="t">' + l.t + '</span><span>' + esc(l.text) + '</span></div>').join('')
            : '<div class="empty" style="padding:12px">Nothing matches in this window.</div>';
        if (following) box.scrollTop = box.scrollHeight;

        const total = Object.values(logCounts).reduce((a, b) => a + b, 0);
        $('nFail').textContent = logCounts.fail ?? 0; $('nWork').textContent = logCounts.work ?? 0; $('nPass').textContent = logCounts.pass ?? 0; $('nAll').textContent = total;
    }

    async function loadLog() {
        try {
            const response = await fetch(urls.log + '?minutes=' + $('logMinutes').value + (logKind === 'all' ? '&all=1' : ''), { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(response.status);
            const data = await response.json();
            logLines = data.lines;
            logCounts = data.counts ?? {};
            $('logMeta').textContent = data.since + '–now UTC · ' + data.total + ' lines kept' + (data.truncated ? ' (newest ' + data.lines.length + ' shown)' : '');
            renderLog();
        } catch (error) {
            $('logMeta').textContent = 'log failed (' + error.message + ')';
        }
    }
    const scheduleLog = () => { clearTimeout(logTimer); logTimer = setTimeout(loadLog, 1500); };

    document.querySelectorAll('[data-kind]').forEach(button => button.addEventListener('click', () => {
        logKind = button.dataset.kind;
        document.querySelectorAll('[data-kind]').forEach(b => b.classList.toggle('on', b === button));
        loadLog();
    }));
    $('logSearch').addEventListener('input', renderLog);
    $('logMinutes').addEventListener('change', loadLog);
    $('follow').addEventListener('click', () => {
        following = !following;
        $('follow').classList.toggle('on', following);
        $('follow').setAttribute('aria-pressed', following);
        $('follow').textContent = following ? 'following' : 'paused';
        if (following) $('log').scrollTop = $('log').scrollHeight;
    });
    // Scrolling up pauses following, so reading older output is never yanked away.
    $('log').addEventListener('wheel', event => {
        if (event.deltaY < 0 && following) $('follow').click();
    }, { passive: true });

    /* ---- poll ---- */
    let since = '';
    async function tick() {
        const controller = new AbortController();
        const watchdog = setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch(urls.poll + '?since=' + encodeURIComponent(since), { headers: { Accept: 'application/json' }, signal: controller.signal });
            if (!response.ok) throw new Error(response.status);
            const data = await response.json();
            const changed = data.fingerprint !== since;
            since = data.fingerprint;
            render(data);
            if (changed) scheduleLog();
        } catch (error) {
            since = '';
            $('state').className = 'state stopped';
            $('stateText').textContent = 'page cannot reach the harness feed (' + error.message + ') — retrying';
        } finally {
            clearTimeout(watchdog);
        }
        tick();
    }

    /* ---- ledger: closed by default, read once ---- */
    let ledger = [], view = 'kanban';
    const OPEN = ['in_progress', 'todo', 'blocked'];
    const outstanding = task => (task.deps ?? []).filter(dep => dep.status !== 'done');

    function ledgerRows() {
        const needle = $('ledgerSearch').value.trim().toLowerCase();
        const everything = $('ledgerAll').checked;
        return ledger.filter(task => (everything || OPEN.includes(task.status))
            && (needle === '' || [task.code, task.title, task.notes, task.file_ref, task.gap_ref].some(f => String(f ?? '').toLowerCase().includes(needle))));
    }

    function renderLedger() {
        const rows = ledgerRows();
        const ready = rows.filter(t => t.ready).length;
        $('ledgerMeta').textContent = rows.length + ' rows' + (ready ? ' · ' + ready + ' ready' : '');
        if (view === 'kanban') {
            const statuses = ($('ledgerAll').checked ? [...OPEN, 'deferred', 'done'] : OPEN).filter(s => rows.some(t => t.status === s));
            $('kanban').innerHTML = statuses.map(status => {
                const column = rows.filter(t => t.status === status);
                return '<div class="column"><h2>' + status.replace('_', ' ') + ' · ' + column.length + '</h2>' + column.map(task =>
                    '<div class="kcard ' + (task.ready ? 'ready' : '') + '"><span class="c">' + esc(task.code) + '</span><span class="pri ' + esc(task.priority) + '">' + esc(task.priority) + '</span>'
                    + esc(task.title) + (outstanding(task).length ? '<div class="w">waits on ' + esc(outstanding(task).map(d => d.code).join(', ')) + '</div>' : '') + '</div>').join('') + '</div>';
            }).join('') || '<div class="empty">No rows match.</div>';
            return;
        }
        $('ledgerWrap').innerHTML = '<table><thead><tr><th>code</th><th>status</th><th>pri</th><th>title</th><th>file</th><th>proof</th></tr></thead><tbody>'
            + rows.map(t => '<tr><td class="code">' + esc(t.code) + '</td><td>' + esc(t.status) + '</td><td>' + esc(t.priority) + '</td><td class="ttl">' + esc(t.title)
                + '</td><td class="why">' + esc(t.file_ref ?? '') + '</td><td class="why">' + esc(t.proof ?? '') + '</td></tr>').join('') + '</tbody></table>';
    }

    async function loadLedger() {
        try {
            const response = await fetch(urls.tasks, { headers: { Accept: 'application/json' } });
            ledger = (await response.json()).tasks ?? [];
            renderLedger();
        } catch (error) {
            $('ledgerMeta').textContent = 'ledger failed (' + error.message + ')';
        }
    }
    const showView = next => {
        view = next;
        $('kanban').hidden = next !== 'kanban';
        $('ledgerWrap').hidden = next !== 'table';
        $('tabKanban').classList.toggle('on', next === 'kanban');
        $('tabTable').classList.toggle('on', next === 'table');
        renderLedger();
    };
    $('tabKanban').addEventListener('click', () => showView('kanban'));
    $('tabTable').addEventListener('click', () => showView('table'));
    $('ledgerSearch').addEventListener('input', renderLedger);
    $('ledgerAll').addEventListener('change', renderLedger);
    $('ledgerBox').addEventListener('toggle', () => { if ($('ledgerBox').open && ledger.length === 0) loadLedger(); });

    loadLog();
    tick();
</script>
</body>
</html>
