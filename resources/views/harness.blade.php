<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI harness — live</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 24px;
            font: 14px/1.5 ui-monospace, SFMono-Regular, Menlo, monospace;
            background: #0d1117; color: #e6edf3;
        }
        h1 { font-size: 16px; margin: 0 0 4px; }
        .sub { color: #8b949e; font-size: 12px; margin-bottom: 20px; }
        .grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
        .card { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 14px; }
        .label { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: #8b949e; }
        .value { font-size: 22px; font-weight: 700; margin: 2px 0 8px; }
        .bar { height: 8px; background: #21262d; border-radius: 999px; overflow: hidden; }
        .bar > div { height: 100%; width: 0; background: #2f81f7; transition: width .3s ease; }
        .bar.ok > div { background: #3fb950; }
        .meta { font-size: 12px; color: #8b949e; margin-top: 6px; }
        .pulse { display: inline-block; width: 9px; height: 9px; border-radius: 50%; background: #3fb950; margin-right: 6px; }
        .pulse.stopped { background: #f85149; }
        .pulse.idle { background: #d29922; }
        @keyframes blink { 50% { opacity: .35; } }
        .pulse.live { animation: blink 1.4s infinite; }
        pre { margin: 0; height: 58vh; overflow: auto; font-size: 12px; line-height: 1.45; color: #c9d1d9; }
        .feed { height: 58vh; overflow: auto; font-size: 12px; }
        .feed div { padding: 4px 0; border-bottom: 1px solid #21262d; }
        .feed .when { color: #8b949e; margin-right: 8px; }
        .feed .code { color: #2f81f7; margin-right: 8px; }
        .feed .what { color: #3fb950; }
        .feed .why { color: #8b949e; display: block; margin-top: 2px; }
        pre .new { color: #3fb950; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        td { padding: 3px 6px; border-bottom: 1px solid #21262d; vertical-align: top; }
        td.status { white-space: nowrap; color: #8b949e; }
        td.phase { color: #3fb950; white-space: nowrap; }
        td.detail { color: #2f81f7; }
        .stamp { font-size: 12px; color: #8b949e; }
    </style>
</head>
<body>
<h1>OGame AI — harness running</h1>
<div class="sub">
    <span id="pulse" class="pulse"></span><span id="pulseText">connecting…</span>
    · snapshot <span id="at">—</span>
    · <span id="stamp">waiting for first poll</span>
</div>

<div class="grid">
    <div class="card">
        <div class="label">Sources → plans</div>
        <div class="value" id="planValue">—</div>
        <div class="bar"><div id="planBar"></div></div>
        <div class="meta" id="planMeta">—</div>
    </div>
    <div class="card">
        <div class="label">Plans → task rows</div>
        <div class="value" id="promoteValue">—</div>
        <div class="bar"><div id="promoteBar"></div></div>
        <div class="meta" id="promoteMeta">—</div>
    </div>
    <div class="card">
        <div class="label">Proven to standard</div>
        <div class="value" id="workValue">—</div>
        <div class="bar ok"><div id="workBar"></div></div>
        <div class="meta" id="workMeta">—</div>
    </div>
    <div class="card">
        <div class="label">Working on now</div>
        <div class="value" id="liveValue">—</div>
        <div class="meta" id="liveMeta">—</div>
        <div class="meta" id="liveRecent">—</div>
    </div>
    <div class="card">
        <div class="label">Shards in flight</div>
        <div class="value" id="workersValue">—</div>
        <table id="workers"><tbody></tbody></table>
        <div class="meta" id="workersMeta">—</div>
    </div>
    <div class="card">
        <div class="label">Claims held</div>
        <div class="value" id="claimsValue">—</div>
        <table id="claims"><tbody></tbody></table>
        <div class="meta" id="claimsMeta">—</div>
    </div>
    <div class="card">
        <div class="label">Tasks in the queue</div>
        <div class="value" id="doneValue">—</div>
        <div class="bar"><div id="doneBar"></div></div>
        <div class="meta" id="doneMeta">—</div>
    </div>
</div>

<div class="grid" style="margin-top:16px">
    <div class="card">
        <div class="label">Harness output <span id="follow" class="stamp"></span></div>
        <pre id="log"></pre>
    </div>
    <div class="card">
        <div class="label">Live task activity</div>
        <div class="feed" id="feed"></div>
    </div>
</div>

<script>
    const poll = @json(route('ai.harness.poll'));
    let since = '';
    let first = true;

    // Null-safe on purpose: an element the script expects and the markup no longer has used to throw
    // inside render(), which aborted the whole page and surfaced only as "poll failed".
    const set = (id, text) => {
        const element = document.getElementById(id);
        if (element) element.textContent = text;
    };
    const bar = (id, percent) => {
        const element = document.getElementById(id);
        if (element) element.style.width = Math.max(0, Math.min(100, percent)) + '%';
    };

    function render(data) {
        const sources = data.sources;
        const tasks = data.tasks;
        const harness = data.harness;

        const planPct = sources.raw ? (sources.proposals / sources.raw) * 100 : 0;
        set('planValue', sources.proposals + ' / ' + sources.raw);
        bar('planBar', planPct);
        set('planMeta', sources.validated + ' validated · ' + sources.raw + ' sources held');

        // The harness never marks tasks done (a human still owns that), so task status can never
        // show its work. Implemented markers are the honest progress signal.
        const implemented = sources.implemented ?? 0;
        const workPct = sources.validated ? (implemented / sources.validated) * 100 : 0;
        set('workValue', implemented + ' / ' + sources.validated);
        bar('workBar', workPct);
        set('workMeta', (sources.validated - implemented) + ' still to prove — a slice needs to be called by\n            runtime code, hold a scenario, and execute on a live universe');

        const donePct = tasks.total ? (tasks.todo / tasks.total) * 100 : 0;
        set('doneValue', tasks.todo + ' todo');
        bar('doneBar', donePct);
        set('doneMeta', tasks.in_progress + ' in progress · ' + tasks.done + ' done · ' + tasks.deferred
            + ' deferred — the harness never marks a task done, so this ledger is not its progress');

        const activity = data.activity ?? {};
        set('liveValue', (activity.provedLastHour ?? 0) + ' proved in the last hour');
        // "attempted now" used to count every task with a failure on record, which is a lifetime
        // number wearing the word "now". Live workers are the honest answer to what is running.
        set('liveMeta', (data.workers ?? []).length + ' worker(s) running now · '
            + (activity.unproved ?? 0) + ' proposals with no implemented marker yet · '
            + (activity.attempts ?? 0) + ' task(s) with a failed attempt on record');
        set('liveRecent', (activity.recent ?? []).map(r => r.at + ' ' + r.code).join('  ·  ') || 'nothing yet');

        const promotePct = sources.validated ? (tasks.promoted / sources.validated) * 100 : 0;
        set('promoteValue', tasks.promoted + ' / ' + sources.validated);
        bar('promoteBar', promotePct);
        set('promoteMeta', 'every validated plan has a task row, so a plan can never strand before the implement stage sees it');

        // Parallel shards, each reporting itself. One shared heartbeat could not show this: every
        // worker wrote the same file, so a six-worker pass looked like one agent doing one thing.
        const workers = data.workers ?? [];
        const escape = text => String(text ?? '').replace(/[<>&]/g, '');
        set('workersValue', workers.length === 1 ? '1 worker' : workers.length + ' workers');
        document.getElementById('workers').innerHTML = workers.length
            ? workers.map(worker => '<tr><td class="status">' + worker.age + 's</td><td class="phase">'
                + escape(worker.phase) + '</td><td class="detail">' + escape(worker.detail) + '</td></tr>').join('')
            : '<tr><td class="status">—</td><td class="detail">nothing in flight</td><td></td></tr>';
        // In-flight calls are shown whatever the worker count says: pacing is what decides spend, and a
        // worker sitting inside a slow call is invisible in the heartbeat list until it answers.
        const inFlight = data.modelSlots ?? 0;
        set('workersMeta', (workers.length
            ? workers.length + ' shard(s) working'
            : 'no worker heartbeat in the last 6 min — the pass is sweeping, verifying or waiting')
            + ' · ' + inFlight + ' model call(s) in flight (the cap paces spend, not the worker count)');

        // A claim is what stops two parallel implementers writing one file. A claim held far longer
        // than a slice takes is a stuck worker, and it stops every other slice that needs that file.
        const claims = data.claims ?? [];
        set('claimsValue', claims.length === 1 ? '1 held' : claims.length + ' held');
        document.getElementById('claims').innerHTML = claims.length
            ? claims.map(claim => '<tr><td class="status">' + claim.age + 's</td><td class="detail">'
                + escape(claim.key) + '</td></tr>').join('')
            : '<tr><td class="status">—</td><td class="detail">nothing claimed, nothing blocked</td></tr>';
        set('claimsMeta', claims.length
            ? 'no other shard may write these until the holder finishes; an abandoned claim ages out after 30 min'
            : 'every shard is free to take the files it needs');

        const pulse = document.getElementById('pulse');
        const phase = harness.phase ?? null;
        const age = harness.heartbeat ?? harness.idle;
        pulse.className = 'pulse ' + (harness.active ? 'live' : 'stopped');
        set('pulseText', harness.active
            ? (phase ? phase + (harness.detail ? ': ' + harness.detail : '') : 'working')
                + ' · heartbeat ' + age + 's'
            : (age === null ? 'no heartbeat yet' : 'idle for ' + age + 's'));

        set('at', data.at);

        const lines = harness.lines ?? [];
        const log = document.getElementById('log');
        // Follow the tail only when the reader is already at the bottom: yanking the pane down while
        // somebody is reading earlier output is worse than being one refresh behind.
        const atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 40;
        log.textContent = lines.length ? lines.join('\n') : '(no output yet)';
        if (atBottom) log.scrollTop = log.scrollHeight;
        set('follow', atBottom ? '· following the tail' : '· scrolled back, not following');

        document.getElementById('feed').innerHTML = (data.feed ?? []).map(row =>
            '<div><span class="when">' + row.at + '</span><span class="code">' + row.code + '</span><span class="what">'
            + row.event + '</span><span class="why">' + row.title.replace(/[<>&]/g, '').slice(0, 120)
            + '</span></div>').join('') || '<div class="why">nothing yet</div>';

        first = false;
    }

    async function tick() {
        // A watchdog, not just a retry loop: a long poll that never settles (proxy timeout, sleeping
        // worker) would otherwise freeze the page on an old snapshot with no hint that it happened.
        const controller = new AbortController();
        const watchdog = setTimeout(() => controller.abort(), 20000);

        try {
            const response = await fetch(poll + '?since=' + encodeURIComponent(since), {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            if (!response.ok) throw new Error(response.status);
            const data = await response.json();
            since = data.fingerprint;
            render(data);
            set('stamp', 'updated ' + data.at + ' · log ' + (data.harness?.log ?? 'none')
                + ' · ' + new Date().toLocaleTimeString());
        } catch (error) {
            // Recover instead of stalling: drop the cursor so the next poll takes a fresh snapshot.
            since = '';
            document.getElementById('pulse').className = 'pulse stopped';
            set('pulseText', 'poll failed (' + error.message + ') — retrying');
        } finally {
            clearTimeout(watchdog);
        }

        tick();
    }

    tick();
</script>
</body>
</html>
