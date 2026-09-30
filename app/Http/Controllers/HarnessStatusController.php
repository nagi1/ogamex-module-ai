<?php

namespace Modules\AI\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use PDO;
use Throwable;

/**
 * A live window onto the build-time harness, for whoever is watching it work.
 *
 * DEV TOOLING, never shipped behaviour: it reads the pipeline's own artifacts — the task database,
 * the proposal directory, the run log — and reports them. It writes nothing and decides nothing, and
 * it is unreachable outside a local environment, so the framework cannot leak into a universe.
 *
 * Long polling rather than websockets: one sleeping worker per open tab beats deploying a service.
 * ponytail: each open tab holds a PHP worker for up to POLL_SECONDS; move to SSE if several people
 * ever watch at once.
 */
class HarnessStatusController
{
    /** How long a poll waits for the harness to change something before answering anyway. */
    private const POLL_SECONDS = 10;

    /** A harness that has written nothing for this long is stopped, not slow. */
    private const IDLE_SECONDS = 120;

    /**
     * A worker that has not refreshed its heartbeat for this long is gone, not thinking.
     *
     * It has to exceed the longest model call, or the page reports "nothing in flight" while four calls
     * are running: a worker publishes once at the start of a call and then waits for the answer, which
     * can take minutes (`MODEL_TIMEOUT_SECONDS` is 300).
     */
    private const WORKER_SECONDS = 360;

    private const LOG_TAIL = 60;

    private const FEED = 10;

    public function index(): View
    {
        $this->localOnly();

        return view('ai::harness');
    }

    public function poll(Request $request): JsonResponse
    {
        $this->localOnly();

        $since = (string) $request->query('since', '');
        $deadline = microtime(true) + self::POLL_SECONDS;

        while (microtime(true) < $deadline) {
            $fingerprint = $this->fingerprint();
            if ($fingerprint !== $since) {
                return response()->json($this->snapshot($fingerprint));
            }

            usleep(250_000);
        }

        return response()->json($this->snapshot($this->fingerprint()));
    }

    /**
     * The whole task ledger, every column and every row.
     *
     * The overview answers "what is moving"; this answers "what is in the store and what does each row
     * wait on". It is fetched on load and on demand rather than on the poll, because a table that
     * re-sorts itself under the reader while they search it is worse than one a refresh behind.
     */
    public function tasks(): JsonResponse
    {
        $this->localOnly();

        $tasks = $this->taskRows();

        return response()->json([
            'at' => now()->format('H:i:s'),
            'total' => count($tasks),
            'tasks' => $tasks,
        ]);
    }

    /**
     * Build-time tooling has no business answering outside a development machine, where it would
     * expose the plan directory and the run log to anyone who guessed the URL.
     */
    private function localOnly(): void
    {
        abort_unless(app()->isLocal(), 404);
    }

    private function path(string $relative): string
    {
        return module_path('AI', $relative);
    }

    /**
     * A cheap "has anything changed" digest, so a poll can sleep without touching the database.
     */
    private function fingerprint(): string
    {
        $parts = [];

        foreach ([$this->taskDatabase(), ...$this->logs()] as $file) {
            $parts[] = $file.':'.(@filemtime($file) ?: 0).':'.(@filesize($file) ?: 0);
        }

        $proposals = $this->proposals();
        $implemented = $this->implemented();
        $parts[] = 'proposals:'.count($proposals).':'.max([0, ...array_map('filemtime', $proposals)]);
        $parts[] = 'implemented:'.count($implemented).':'.max([0, ...array_map('filemtime', $implemented)]);
        $workers = $this->workerFiles();
        $parts[] = 'workers:'.count($workers).':'.max([0, ...array_map('filemtime', $workers)]);
        $claims = $this->claimFiles();
        $parts[] = 'claims:'.count($claims).':'.max([0, ...array_map('filemtime', $claims)]);
        $slots = $this->modelSlotFiles();
        $parts[] = 'slots:'.count($slots).':'.max([0, ...array_map('filemtime', $slots)]);
        $status = $this->statusFile();
        if ($status !== null) {
            $parts[] = 'status:'.(@filemtime($status) ?: 0);
        }

        return md5(implode('|', $parts));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $fingerprint): array
    {
        return [
            'fingerprint' => $fingerprint,
            'at' => now()->format('H:i:s'),
            'tasks' => $this->taskCounts(),
            'sources' => $this->sourceCounts(),
            'harness' => $this->harnessState(),
            'workers' => $this->workers(),
            'claims' => $this->claims(),
            'modelSlots' => count($this->modelSlotFiles()),
            'activity' => $this->activity(),
            'feed' => $this->feed(),
        ];
    }

    /**
     * Model calls in flight. The pipeline paces every request through one shared slot file per allowed
     * connection, because DeepSeek's limit is concurrency, not requests per minute -- so this number is
     * the actual spend rate, whatever the worker count says.
     *
     * @return array<int, string>
     */
    private function modelSlotFiles(): array
    {
        return glob($this->path('plan/research/ogame/model-slots/slot-*')) ?: [];
    }

    /**
     * Files and lanes the workers are holding right now.
     *
     * A claim is what keeps two parallel implementers out of one file. Seeing them matters as much as
     * having them: a claim that stays held long after a slice should have finished is a worker stuck
     * in a test run, and it silently stops other slices that need the same file.
     *
     * @return array<int, array<string, mixed>>
     */
    private function claims(): array
    {
        $now = time();
        $claims = [];

        foreach ($this->claimFiles() as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $claims[] = [
                'key' => basename((string) ($lines[0] ?? 'unknown')),
                'age' => $now - (int) filemtime($file),
            ];
        }

        usort($claims, static fn (array $a, array $b): int => $b['age'] <=> $a['age']);

        return array_slice($claims, 0, 12);
    }

    /**
     * @return array<int, string>
     */
    private function claimFiles(): array
    {
        return glob($this->path('plan/research/ogame/claims/*.lock')) ?: [];
    }

    /**
     * The parallel shards, as each one describes itself.
     *
     * The pipeline writes one heartbeat per worker because they overwrite a shared one: the last
     * worker to speak used to erase the other five, so a six-worker pass read as one agent doing one
     * thing. Only fresh files count -- a killed worker leaves its last heartbeat behind, and a
     * heartbeat nobody refreshes is not work in progress.
     *
     * @return array<int, array<string, mixed>>
     */
    private function workers(): array
    {
        $now = time();
        $workers = [];

        foreach ($this->workerFiles() as $file) {
            $age = $now - (int) filemtime($file);
            if ($age > self::WORKER_SECONDS) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($file), true);
            if (!is_array($decoded)) {
                continue;
            }

            $workers[] = [
                'pid' => (int) ($decoded['pid'] ?? 0),
                'phase' => (string) ($decoded['phase'] ?? 'working'),
                'detail' => (string) ($decoded['detail'] ?? ''),
                'age' => $age,
            ];
        }

        usort($workers, static fn (array $a, array $b): int => $a['age'] <=> $b['age']);

        return $workers;
    }

    /**
     * @return array<int, string>
     */
    private function workerFiles(): array
    {
        return glob($this->path('plan/research/ogame/workers/*.json')) ?: [];
    }

    /**
     * What the harness last did to each task, newest first.
     *
     * Read from the harness's own artifacts rather than from task status: a task's status never moves
     * (a person owns that column), so listing the newest rows showed the same idle backlog forever.
     * A proved marker and a failed attempt are both timestamps of real work.
     *
     * @return array<int, array<string, string>>
     */
    private function feed(): array
    {
        $events = [];

        foreach ($this->markers('md') as $marker) {
            $events[] = [basename($marker, '.md'), 'proved and wired', (int) filemtime($marker)];
        }

        foreach (glob($this->path('plan/research/ogame/attempts/*.count')) ?: [] as $counter) {
            $code = basename($counter, '.count');
            $log = $this->path("plan/research/ogame/attempts/{$code}.log");
            $attempts = (int) trim((string) @file_get_contents($counter));
            $events[] = [$code, "attempt {$attempts} failed, retrying", (int) (@filemtime($log) ?: filemtime($counter))];
        }

        usort($events, static fn (array $a, array $b): int => $b[2] <=> $a[2]);
        $titles = $this->titles();
        $feed = [];

        foreach (array_slice($events, 0, self::FEED) as [$code, $event, $at]) {
            $feed[] = [
                'code' => $code,
                'event' => $event,
                'at' => date('H:i:s', $at),
                'title' => $titles[$code] ?? '',
            ];
        }

        return $feed;
    }

    /**
     * Task titles, so a feed entry says what the task is as well as what happened to it.
     *
     * @return array<string, string>
     */
    private function titles(): array
    {
        if (!is_file($this->taskDatabase())) {
            return [];
        }

        try {
            $connection = new PDO('sqlite:'.$this->taskDatabase(), null, null, [PDO::ATTR_TIMEOUT => 2]);
            /** @var array<string, string> $rows */
            $rows = $this->queryRows($connection, 'SELECT code, title FROM tasks', PDO::FETCH_KEY_PAIR);
        } catch (Throwable) {
            return [];
        }

        return $rows;
    }

    /**
     * What is moving right now: the slices proved most recently, how many are being attempted, and how
     * many proposals are still to prove.
     *
     * Task status is deliberately not part of this. The harness never marks a task done -- that stays a
     * person's ledger -- so a bar driven by it can never move, which reads as a fault when it is only a
     * different number.
     *
     * @return array<string, mixed>
     */
    private function activity(): array
    {
        $markers = $this->implemented();
        usort($markers, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        $recent = [];
        foreach (array_slice($markers, 0, 6) as $marker) {
            $recent[] = ['code' => basename($marker, '.md'), 'at' => date('H:i', (int) filemtime($marker))];
        }

        $now = time();
        $proposals = count($this->proposals());

        return [
            'recent' => $recent,
            'provedLastHour' => count(array_filter(
                $markers,
                static fn (string $marker): bool => $now - (int) filemtime($marker) < 3600
            )),
            'attempts' => count(glob($this->path('plan/research/ogame/attempts/*.count')) ?: []),
            'unproved' => max(0, $proposals - count($markers)),
        ];
    }

    private function statusFile(): ?string
    {
        $status = $this->path('plan/research/ogame/harness-status.json');

        return is_file($status) ? $status : null;
    }

    /**
     * @return array<int, string>
     */
    private function implemented(): array
    {
        return $this->markers('md');
    }

    /**
     * Markers the harness writes beside finished work: `md` verified, `skip` an edit-only plan,
     * `stuck` parked after repeated test failures. Parked work is reported rather than hidden -- a
     * task that silently stops being retried looks identical to one that was forgotten.
     *
     * @return array<int, string>
     */
    private function markers(string $extension): array
    {
        return glob($this->path('plan/research/ogame/implemented/*.'.$extension)) ?: [];
    }

    private function taskDatabase(): string
    {
        return $this->path('plan/tasks/tasks.db');
    }

    /**
     * @return array<int, string>
     */
    private function logs(): array
    {
        return array_values(array_filter([
            ...(glob('/tmp/pipeline-run*.log') ?: []),
            ...(glob('/tmp/harness-*.log') ?: []),
        ], is_file(...)));
    }

    /**
     * @return array<int, string>
     */
    private function proposals(): array
    {
        return glob($this->path('plan/research/ogame/proposals/*.md')) ?: [];
    }

    /**
     * @return array<string, int>
     */
    private function taskCounts(): array
    {
        $counts = ['done' => 0, 'todo' => 0, 'in_progress' => 0, 'deferred' => 0, 'total' => 0, 'promoted' => 0];

        if (!is_file($this->taskDatabase())) {
            return $counts;
        }

        try {
            // Read-only by construction: this page may only ever look at the task store.
            $connection = new PDO('sqlite:'.$this->taskDatabase(), null, null, [PDO::ATTR_TIMEOUT => 2]);

            foreach ($this->queryRows($connection, 'SELECT status, COUNT(*) AS total FROM tasks GROUP BY status') as $row) {
                $counts[(string) $row['status']] = (int) $row['total'];
                $counts['total'] += (int) $row['total'];
            }

            $promoted = $this->queryRows($connection, "SELECT COUNT(*) AS total FROM tasks WHERE notes LIKE '%auto-promoted%'");
            $counts['promoted'] = (int) ($promoted[0]['total'] ?? 0);
        } catch (Throwable) {
            return $counts;
        }

        return $counts;
    }

    /**
     * Every column of every task, plus what the row waits on and what the harness already did to it.
     *
     * Read-only by construction: the ledger belongs to a person, and this page may only look at it. A
     * dependency edge is stored as ids, so both ends are joined back to codes here rather than leaving
     * the page to resolve numbers, and readiness comes from the store's own view so this page can never
     * disagree with `task.py ready` about what is next.
     *
     * @return array<int, array<string, mixed>>
     */
    private function taskRows(): array
    {
        if (!is_file($this->taskDatabase())) {
            return [];
        }

        try {
            $connection = new PDO('sqlite:'.$this->taskDatabase(), null, null, [PDO::ATTR_TIMEOUT => 2]);
            // The ledger is a person's to edit; this window may only ever read it, whatever is added here
            // later. SQLite enforces that rather than this comment.
            $connection->exec('PRAGMA query_only = 1');

            /** @var array<int, array<string, mixed>> $rows */
            $rows = $this->queryRows($connection, 'SELECT * FROM tasks ORDER BY priority, code');
            $needs = $this->taskNeeds($connection);
            $ready = array_flip($this->queryRows($connection, 'SELECT code FROM ready_tasks', PDO::FETCH_COLUMN));
        } catch (Throwable) {
            return [];
        }

        return array_map(fn (array $row): array => [
            ...$row,
            'deps' => $needs[(string) $row['code']] ?? [],
            'ready' => isset($ready[(string) $row['code']]),
            'attempts' => $this->attempts((string) $row['code']),
            'proved' => is_file($this->path('plan/research/ogame/implemented/'.$row['code'].'.md')),
        ], $rows);
    }

    /**
     * What each row waits on, as the code it waits for and that code's current status.
     *
     * @return array<string, array<int, array<string, string>>>
     */
    private function taskNeeds(PDO $connection): array
    {
        $needs = [];

        foreach ($this->queryRows(
            $connection,
            'SELECT t.code AS task, d.code AS needs, d.status AS status FROM dependencies e
             JOIN tasks t ON t.id = e.task_id JOIN tasks d ON d.id = e.depends_on ORDER BY d.code'
        ) as $edge) {
            $needs[(string) $edge['task']][] = ['code' => (string) $edge['needs'], 'status' => (string) $edge['status']];
        }

        return $needs;
    }

    /**
     * One read against the task store, with `PDO::query`'s failure answered once.
     *
     * A driver error has the same sensible answer at every call site here -- no rows -- so the guard
     * lives in one place instead of being repeated per query.
     *
     * @return array<int|string, mixed>
     */
    private function queryRows(PDO $connection, string $sql, int $mode = PDO::FETCH_ASSOC): array
    {
        $statement = $connection->query($sql);

        return $statement === false ? [] : $statement->fetchAll($mode);
    }

    /**
     * Failed attempts the retry loop has counted for this task, so the ledger shows the same number the
     * harness acts on rather than a second opinion about how hard a slice has been.
     */
    private function attempts(string $code): int
    {
        $counter = $this->path("plan/research/ogame/attempts/{$code}.count");

        return is_file($counter) ? (int) trim((string) file_get_contents($counter)) : 0;
    }

    /**
     * @return array<string, int>
     */
    private function sourceCounts(): array
    {
        $proposals = $this->proposals();
        $validated = 0;

        foreach ($proposals as $proposal) {
            $validated += str_contains((string) file_get_contents($proposal), 'VALIDATED') ? 1 : 0;
        }

        $raw = $this->path('plan/research/ogame/raw');

        return [
            'raw' => is_dir($raw) ? count(File::allFiles($raw)) : 0,
            'proposals' => count($proposals),
            'validated' => $validated,
            'implemented' => count($this->implemented()),
            'edit_only' => count($this->markers('skip')),
            'stuck' => count($this->markers('stuck')),
        ];
    }

    /**
     * What the harness says it is doing, plus how long ago it said it.
     *
     * The log file is the fallback for a harness started before it published status; the phase is
     * preferred because a log mtime cannot tell "thinking" from "died".
     *
     * @return array<string, mixed>
     */
    private function harnessState(): array
    {
        $status = $this->statusFile();
        $phase = null;
        $detail = null;
        $heartbeat = null;

        if ($status !== null) {
            $decoded = json_decode((string) file_get_contents($status), true);
            $phase = is_array($decoded) ? ($decoded['phase'] ?? null) : null;
            $detail = is_array($decoded) ? ($decoded['detail'] ?? null) : null;
            $heartbeat = (int) (time() - (int) filemtime($status));
        }

        $newest = null;

        foreach ($this->logs() as $log) {
            if ($newest === null || filemtime($log) > filemtime($newest)) {
                $newest = $log;
            }
        }

        $idle = $newest === null ? $heartbeat : (int) (time() - (int) filemtime($newest));
        // A model call writes nothing to the log for minutes, so a quiet log is not a stopped harness.
        // The status file the pass republishes is the other clock, and liveness is whichever is fresher:
        // "waiting on the model" used to read as "idle for 153s" while a call was in flight.
        $ages = array_filter([$idle, $heartbeat], static fn (?int $age): bool => $age !== null);
        $idle = $ages === [] ? null : min($ages);
        // Pest colours its output; the escape codes reach the log and would show as noise on the page.
        $lines = array_map(
            static fn (string $line): string => preg_replace('/\x1b\[[0-9;]*m/', '', $line) ?? $line,
            $newest === null ? [] : (file($newest, FILE_IGNORE_NEW_LINES) ?: [])
        );

        return [
            'active' => $idle !== null && $idle < self::IDLE_SECONDS,
            'idle' => $idle,
            'phase' => $phase,
            'detail' => $detail,
            'heartbeat' => $heartbeat,
            'log' => $newest === null ? null : basename($newest),
            'lines' => array_slice($lines, -self::LOG_TAIL),
        ];
    }
}
