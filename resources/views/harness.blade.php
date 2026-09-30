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
        .toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 10px; }
        .toolbar .spacer { flex: 1 1 auto; }
        .toolbar input, .toolbar select, .toolbar button {
            background: #0d1117; color: #e6edf3; border: 1px solid #30363d; border-radius: 6px;
            padding: 4px 8px; font: inherit;
        }
        .toolbar input { min-width: 260px; }
        .toolbar button { cursor: pointer; }
        .toolbar button.on { border-color: #2f81f7; color: #2f81f7; }
        .kanban { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 10px;
            max-height: 72vh; overflow: auto; }
        .column { background: #0d1117; border: 1px solid #21262d; border-radius: 8px; padding: 8px; }
        .column .label { margin-bottom: 6px; }
        .kcard { background: #161b22; border: 1px solid #21262d; border-left: 3px solid #30363d;
            border-radius: 6px; padding: 6px 8px; margin-bottom: 6px; }
        .kcard .code { color: #2f81f7; margin-right: 6px; }
        .kcard .pri { margin-right: 6px; color: #8b949e; }
        .kcard .pri.P0, .kcard .pri.P1 { color: #f85149; }
        .kcard .title { color: #c9d1d9; font-size: 12px; }
        .kcard .waits { color: #d29922; font-size: 11px; margin-top: 2px; }
        .kcard .proved { color: #3fb950; font-size: 11px; }
        .ledger { max-height: 72vh; overflow: auto; }
        .ledger th { position: sticky; top: 0; background: #161b22; cursor: pointer; text-align: left;
            padding: 4px 6px; font-size: 11px; text-transform: uppercase; letter-spacing: .06em;
            color: #8b949e; border-bottom: 1px solid #30363d; white-space: nowrap; }
        .ledger td.notes, .ledger td.wide { color: #8b949e; max-width: 420px; }
        .ledger tr:hover td { background: #1c2129; }
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

<div class="card" style="margin-top:16px">
    <div class="toolbar">
        <div class="label">Task ledger <span id="ledgerMeta" class="stamp">loading…</span></div>
        <div class="spacer"></div>
        <input id="ledgerSearch" type="search" placeholder="search code, title, notes, file, gap…" autocomplete="off">
        <select id="ledgerStatus"><option value="all">all statuses</option></select>
        <button id="tabKanban" type="button" class="on">kanban</button>
        <button id="tabTable" type="button">table</button>
        <button id="ledgerRefresh" type="button">refresh</button>
    </div>
    <div class="kanban" id="kanban"></div>
    <div class="ledger" id="ledgerWrap" hidden></div>
    <div class="meta">
        read-only: this window never writes to the task store. Click a table header to sort, and note that
        a card cannot be dragged — a task's status is a person's column, not the harness's.
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
    // Every card, column and cell below is built as HTML, so task text has to be escaped before it is
    // interpolated: a task title quoting markup must print, not run.
    const esc = text => String(text ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

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
        set('workersValue', workers.length === 1 ? '1 worker' : workers.length + ' workers');
        document.getElementById('workers').innerHTML = workers.length
            ? workers.map(worker => '<tr><td class="status">' + worker.age + 's</td><td class="phase">'
                + esc(worker.phase) + '</td><td class="detail">' + esc(worker.detail) + '</td></tr>').join('')
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
                + esc(claim.key) + '</td></tr>').join('')
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
            + row.event + '</span><span class="why">' + esc(row.title.slice(0, 120))
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

    // The ledger is read once and on demand, never on the poll: a table that re-sorts itself under the
    // reader while they search it is worse than one that is one refresh behind.
    const ledgerUrl = @json(route('ai.harness.tasks'));
    const COLUMNS = [
        ['code', 'code'], ['status', 'status'], ['priority', 'pri'], ['kind', 'kind'], ['title', 'title'],
        ['ready', 'ready'], ['waiting', 'waits'], ['attempts', 'tries'], ['proved', 'proved'],
        ['assignee', 'who'], ['gap_ref', 'gap'], ['file_ref', 'file'], ['doc_refs', 'docs'],
        ['principle_refs', 'principle'], ['algorithm_ref', 'algorithm'], ['updated_at', 'updated'],
        ['notes', 'notes'],
    ];
    const STATUS_ORDER = ['in_progress', 'blocked', 'todo', 'deferred', 'done', 'open'];
    const BOOLEAN_SORTS = ['ready', 'proved'];

    let ledger = [];
    let ledgerAt = '—';
    let query = '';
    let statusFilter = 'all';
    let sortKey = 'priority';
    let sortDirection = 1;

    const outstanding = task => (task.deps ?? []).filter(dep => dep.status !== 'done');

    // Booleans sort as numbers and unknown values last, so those columns answer "what is left" rather
    // than reading alphabetically.
    const sortValue = (task, key) => {
        if (key === 'waiting') return outstanding(task).length;
        if (BOOLEAN_SORTS.includes(key)) return task[key] ? 1 : 0;
        return task[key];
    };

    function compare(left, right) {
        const a = sortValue(left, sortKey);
        const b = sortValue(right, sortKey);
        if (a === b) return String(left.code).localeCompare(String(right.code));
        if (a === null || a === undefined) return 1;
        if (b === null || b === undefined) return -1;
        if (typeof a === 'number' && typeof b === 'number') return a - b;
        return String(a).localeCompare(String(b));
    }

    function visibleTasks() {
        const needle = query.trim().toLowerCase();
        const matched = ledger.filter(task => {
            if (statusFilter !== 'all' && task.status !== statusFilter) return false;
            if (needle === '') return true;
            return [task.code, task.title, task.notes, task.file_ref, task.gap_ref, task.assignee]
                .some(field => String(field ?? '').toLowerCase().includes(needle));
        });

        return matched.sort((a, b) => sortDirection * compare(a, b));
    }

    function card(task) {
        const waits = outstanding(task);
        return '<div class="kcard">'
            + '<div><span class="code">' + esc(task.code) + '</span>'
            + '<span class="pri ' + esc(task.priority) + '">' + esc(task.priority) + '</span>'
            + '<span class="stamp">' + esc(task.kind) + '</span></div>'
            + '<div class="title">' + esc(task.title) + '</div>'
            + (waits.length ? '<div class="waits">waits on ' + esc(waits.map(dep => dep.code).join(', ')) + '</div>' : '')
            + (task.attempts ? '<div class="waits">' + task.attempts + ' failed attempt(s)</div>' : '')
            + (task.proved ? '<div class="proved">proved and wired</div>' : '')
            + '</div>';
    }

    function renderKanban(rows) {
        // A status this page does not know about is still shown: the store is the authority on its own
        // vocabulary, and hiding a row would misreport the backlog.
        const statuses = [...STATUS_ORDER, ...new Set(ledger.map(task => task.status))]
            .filter((status, index, all) => all.indexOf(status) === index)
            .filter(status => ledger.some(task => task.status === status));

        document.getElementById('kanban').innerHTML = statuses.map(status => {
            const column = rows.filter(task => task.status === status);

            return '<div class="column"><div class="label">' + esc(status) + ' · ' + column.length + '</div>'
                + (column.length ? column.map(card).join('') : '<div class="stamp">nothing here</div>')
                + '</div>';
        }).join('');
    }

    function renderTable(rows) {
        const head = COLUMNS.map(([key, label]) => '<th data-key="' + key + '">' + label
            + (sortKey === key ? (sortDirection > 0 ? ' ↑' : ' ↓') : '') + '</th>').join('');

        const body = rows.map(task => {
            const waits = outstanding(task);

            return '<tr>'
                + '<td>' + esc(task.code) + '</td>'
                + '<td class="status">' + esc(task.status) + '</td>'
                + '<td class="status">' + esc(task.priority) + '</td>'
                + '<td class="status">' + esc(task.kind) + '</td>'
                + '<td>' + esc(task.title) + '</td>'
                + '<td class="status">' + (task.ready ? 'ready' : '—') + '</td>'
                + '<td class="wide">' + (waits.length
                    ? esc(waits.map(dep => dep.code + ' (' + dep.status + ')').join(', ')) : '—') + '</td>'
                + '<td class="status">' + (task.attempts || '—') + '</td>'
                + '<td class="status">' + (task.proved ? 'yes' : '—') + '</td>'
                + '<td class="status">' + esc(task.assignee ?? '—') + '</td>'
                + '<td class="status">' + esc(task.gap_ref ?? '—') + '</td>'
                + '<td class="wide" title="' + esc(task.file_ref) + '">' + esc(task.file_ref ?? '—') + '</td>'
                + '<td class="wide" title="' + esc(task.doc_refs) + '">' + esc(task.doc_refs ?? '—') + '</td>'
                + '<td class="wide" title="' + esc(task.principle_refs) + '">' + esc(task.principle_refs ?? '—') + '</td>'
                + '<td class="wide" title="' + esc(task.algorithm_ref) + '">' + esc(task.algorithm_ref ?? '—') + '</td>'
                + '<td class="status">' + esc(task.updated_at ?? '—') + '</td>'
                + '<td class="notes" title="' + esc((task.notes ?? '').slice(0, 400)) + '">'
                + esc((task.notes ?? '').slice(0, 160)) + '</td>'
                + '</tr>';
        }).join('');

        const wrap = document.getElementById('ledgerWrap');
        wrap.innerHTML = '<table><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table>';

        wrap.querySelectorAll('th').forEach(header => header.addEventListener('click', () => {
            const key = header.dataset.key;
            sortDirection = sortKey === key ? -sortDirection : 1;
            sortKey = key;
            renderLedger();
        }));
    }

    function renderLedger() {
        const rows = visibleTasks();
        const waiting = ledger.filter(task => task.status === 'todo' && !task.ready).length;

        set('ledgerMeta', rows.length + ' of ' + ledger.length + ' rows · '
            + ledger.filter(task => task.ready).length + ' ready · ' + waiting
            + ' todo waiting on a dependency · read ' + ledgerAt);

        renderKanban(rows);
        renderTable(rows);
    }

    function showLedgerView(next) {
        document.getElementById('kanban').hidden = next !== 'kanban';
        document.getElementById('ledgerWrap').hidden = next !== 'table';
        document.getElementById('tabKanban').classList.toggle('on', next === 'kanban');
        document.getElementById('tabTable').classList.toggle('on', next === 'table');
    }

    async function loadLedger() {
        try {
            const response = await fetch(ledgerUrl, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(response.status);
            const data = await response.json();
            ledger = data.tasks ?? [];
            ledgerAt = data.at;

            // Status options come from the data, so a status the store grows appears without an edit here.
            const select = document.getElementById('ledgerStatus');
            select.innerHTML = '<option value="all">all statuses</option>'
                + [...new Set(ledger.map(task => task.status))].sort()
                    .map(status => '<option value="' + esc(status) + '">' + esc(status) + '</option>').join('');
            select.value = statusFilter;

            renderLedger();
        } catch (error) {
            set('ledgerMeta', 'ledger failed (' + error.message + ') — press refresh');
        }
    }

    document.getElementById('ledgerSearch').addEventListener('input', event => {
        query = event.target.value;
        renderLedger();
    });
    document.getElementById('ledgerStatus').addEventListener('change', event => {
        statusFilter = event.target.value;
        renderLedger();
    });
    document.getElementById('ledgerRefresh').addEventListener('click', loadLedger);
    document.getElementById('tabKanban').addEventListener('click', () => showLedgerView('kanban'));
    document.getElementById('tabTable').addEventListener('click', () => showLedgerView('table'));

    showLedgerView('kanban');
    loadLedger();

    tick();
</script>
</body>
</html>
