<?php

namespace Modules\AI\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    /** Most lines the log pane is sent: an hour of a busy harness is a few thousand. */
    private const LOG_LINES = 4000;

    /** A row that used its attempts waits this long before it is tried again (`COOLOFF_SECONDS`). */
    private const COOLOFF_SECONDS = 2700;

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
     * The harness output of the last N minutes, from the persistent timestamped log.
     *
     * The harness's own log lives in /tmp and carries no times, so `scripts/harness-log.py` keeps a
     * stamped copy under plan/research/ogame/logs. Reading that is what lets the page answer "what
     * happened in the last hour" after a reload or a reboot.
     */
    public function log(Request $request): JsonResponse
    {
        $this->localOnly();

        $minutes = max(5, min(180, (int) $request->query('minutes', 60)));
        $cutoff = now('UTC')->subMinutes($minutes);
        $lines = [];

        foreach ($this->persistentLogs() as $file) {
            $day = substr(basename($file, '.log'), strlen('harness-'));
            if ($day < $cutoff->format('Y-m-d')) {
                continue;
            }

            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
                [$time, $text] = array_pad(explode("\t", $raw, 2), 2, '');
                if ($day.' '.$time < $cutoff->format('Y-m-d H:i:s')) {
                    continue;
                }

                $lines[] = ['t' => $time, 'text' => $text, 'kind' => $this->lineKind($text)];
            }
        }

        $counts = array_count_values(array_column($lines, 'kind'));
        // The default view is the events; plain lines (test and scorecard output) are sent only when asked
        // for, so a full hour of events fits under the cap instead of being cut off by noise.
        if (!$request->boolean('all')) {
            $lines = array_values(array_filter($lines, static fn (array $line): bool => $line['kind'] !== 'plain'));
        }
        $total = count($lines);

        return response()->json([
            'minutes' => $minutes,
            'total' => $total,
            'counts' => $counts,
            'truncated' => $total > self::LOG_LINES,
            'since' => $cutoff->format('H:i:s'),
            'lines' => array_slice($lines, -self::LOG_LINES),
        ]);
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

        // What the page now draws from: the stamped log, the model ledger, the attempt counters, the
        // newest scorecard and the cohort verdict.
        foreach ([...$this->persistentLogs(), $this->path('plan/research/ogame/model-usage.jsonl'), '/tmp/harness-quality-grand.txt', $this->path('plan/research/ogame/stories.json')] as $file) {
            $parts[] = $file.':'.(@filemtime($file) ?: 0).':'.(@filesize($file) ?: 0);
        }
        $attempts = glob($this->path('plan/research/ogame/attempts/*.{count,stuck}'), GLOB_BRACE) ?: [];
        $parts[] = 'attempts:'.count($attempts).':'.max([0, ...array_map('filemtime', $attempts)]);
        $scorecards = glob($this->path('plan/research/ogame/scorecards/*.json')) ?: [];
        $parts[] = 'scorecards:'.count($scorecards).':'.max([0, ...array_map('filemtime', $scorecards)]);

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
            'queue' => $this->queue(),
            'northStar' => $this->northStar(),
            'stories' => $this->stories(),
            'model' => $this->model(),
            'rows' => $this->rows(),
            'cohort' => $this->cohort(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function persistentLogs(): array
    {
        $files = glob($this->path('plan/research/ogame/logs/harness-*.log')) ?: [];
        sort($files);

        return $files;
    }

    /**
     * Which kind of event a log line is. `plain` is everything that is not news: the scorecard and test
     * output printed on every pass, and the banners that restate a state the header already shows.
     * Order matters: the first rule that matches wins.
     */
    private function lineKind(string $text): string
    {
        $line = trim($text);

        return match (true) {
            (bool) preg_match('/^(PASS|FAIL)\s+\w+\s+\d+\s+floor/', $line) => 'plain',
            (bool) preg_match('/^=== (QUALITY FAILED|LIVE VERIFICATION FAILED|cohort verification)|^(UNPROVEN|READY|WAITING|STUCK):|^delivered, not proven|^P0-P2 code rows|^model today|^next one free/', $line) => 'plain',
            (bool) preg_match('/^=== |^--- (proving|parked)/', $line) => 'stage',
            (bool) preg_match('/PROOF: PASS|DELIVERED|^done |test file\(s\): PASS|proven\b/i', $line) && !str_contains($line, 'NOT proven') => 'pass',
            (bool) preg_match('/PROOF: FAIL|attempt \d+ failed|is stuck|STUCK:|did not hold|unfinished|test file\(s\): FAIL|SQLSTATE|Exception|proof (unchanged|regressed|suspect)/i', $line) => 'fail',
            (bool) preg_match('/\[in_progress\]|file\(s\) to write|^raised |left for the next pass|skipped /i', $line) => 'work',
            default => 'plain',
        };
    }

    /**
     * What the pipeline itself says about the queue, read once through its own `status` command.
     *
     * The rules for "proven", "delivered" and "cooling off" live in strategy-pipeline.py, so this asks it
     * instead of restating them. Cached for a few seconds because a page with several tabs open would
     * otherwise start a Python process per poll.
     *
     * @return array<string, mixed>
     */
    private function queue(): array
    {
        $output = Cache::remember('ai-harness-queue-status', 15, function (): string {
            $command = 'cd '.escapeshellarg($this->path('')).' && timeout 20 python3 scripts/strategy-pipeline.py status 2>&1';

            return (string) @shell_exec($command);
        });

        $queue = ['proven' => 0, 'closedBlind' => 0, 'delivered' => 0, 'ready' => 0, 'cooling' => 0,
            'deliveredCodes' => [], 'stuck' => [], 'waiting' => [], 'nextInMinutes' => null, 'available' => $output !== ''];

        if (preg_match('/(\d+) proven, (\d+) closed before proofs existed, (\d+) delivered but NOT proven, (\d+) ready now, (\d+) cooling off/', $output, $m)) {
            [, $queue['proven'], $queue['closedBlind'], $queue['delivered'], $queue['ready'], $queue['cooling']] = array_map('intval', $m);
        }
        if (preg_match('/^delivered, not proven: (.+)$/m', $output, $m)) {
            $queue['deliveredCodes'] = array_map('trim', explode(',', $m[1]));
        }
        if (preg_match('/^STUCK: ([^ ]+(?:, [^ ]+)*)/m', $output, $m)) {
            $queue['stuck'] = array_map('trim', explode(',', $m[1]));
        }
        if (preg_match_all('/^WAITING: (\S+) — (.+)$/m', $output, $m, PREG_SET_ORDER)) {
            $queue['waiting'] = array_map(static fn (array $row): array => ['code' => $row[1], 'why' => $row[2]], $m);
        }
        if (preg_match('/next one free in (\d+) min/', $output, $m)) {
            $queue['nextInMinutes'] = (int) $m[1];
        }

        return $queue;
    }

    /**
     * The player's day as the newest scorecard read it: one entry per aspect, passing or not.
     *
     * This is the north star the whole harness is judged by, so it leads the page. The newest read is
     * the one whose `at` is latest, not whose file name sorts last: baselines and per-row proofs share
     * the folder.
     *
     * @return array<string, mixed>
     */
    private function northStar(): array
    {
        $newest = null;

        foreach (glob($this->path('plan/research/ogame/scorecards/*.json')) ?: [] as $file) {
            $card = json_decode((string) file_get_contents($file), true);
            if (!is_array($card) || !isset($card['aspects'], $card['at']) || !str_contains($file, 'grand')) {
                continue;
            }
            if ($newest === null || (string) $card['at'] > (string) $newest['at']) {
                $newest = $card;
            }
        }

        if ($newest === null) {
            return ['at' => null, 'aspects' => [], 'passing' => 0, 'total' => 0];
        }

        $aspects = [];
        foreach ($newest['aspects'] as $name => $aspect) {
            $aspects[] = [
                'name' => (string) $name,
                'pass' => (bool) ($aspect['pass'] ?? false),
                'count' => (int) ($aspect['count'] ?? 0),
                'floor' => (int) ($aspect['floor'] ?? 0),
                'player' => (string) ($aspect['player'] ?? ''),
            ];
        }

        // Failing aspects first: they are what the next row has to move.
        usort($aspects, static fn (array $a, array $b): int => [$a['pass'], $a['name']] <=> [$b['pass'], $b['name']]);

        return [
            'at' => (string) $newest['at'],
            'hours' => (int) ($newest['hours'] ?? 0),
            'aspects' => $aspects,
            'passing' => count(array_filter($aspects, static fn (array $a): bool => $a['pass'])),
            'total' => count($aspects),
        ];
    }

    /**
     * The behaviour board (scripts/stories.py): each Situation-kit story, what the account did, and for a
     * failing one the kit's own diagnosis. Seconds old, where the scorecard is hours old.
     *
     * @return array<string, mixed>
     */
    private function stories(): array
    {
        $board = json_decode((string) @file_get_contents($this->path('plan/research/ogame/stories.json')), true);
        if (!is_array($board) || !isset($board['stories'])) {
            return ['at' => null, 'stories' => [], 'passing' => 0, 'total' => 0];
        }

        return [
            'at' => date('H:i', (int) $board['at']),
            'age' => (int) floor((time() - (int) $board['at']) / 60),
            'seconds' => (float) ($board['seconds'] ?? 0),
            'stories' => $board['stories'],
            'passing' => count(array_filter($board['stories'], static fn (array $story): bool => (bool) $story['pass'])),
            'total' => count($board['stories']),
        ];
    }

    /**
     * Writer spend: today's totals and the last few calls, from the pipeline's own usage ledger.
     *
     * @return array<string, mixed>
     */
    private function model(): array
    {
        $file = $this->path('plan/research/ogame/model-usage.jsonl');
        $model = ['calls' => 0, 'unfinished' => 0, 'answers' => 0, 'tokens' => 0, 'reasoning' => 0, 'recent' => []];
        if (!is_file($file)) {
            return $model;
        }

        $hourAgo = gmdate('Y-m-d\\TH:i:s\\Z', time() - 3600);
        $writer = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $call = json_decode($line, true);
            if (!is_array($call) || (string) ($call['at'] ?? '') < $hourAgo || !str_starts_with((string) ($call['purpose'] ?? ''), 'implementing')) {
                continue;
            }

            $finished = ($call['finish'] ?? 'stop') === 'stop';
            $model['calls']++;
            $model['unfinished'] += $finished ? 0 : 1;
            $model['tokens'] += (int) ($call['output'] ?? 0);
            $model['reasoning'] += (int) ($call['reasoning'] ?? 0);
            $writer[] = $call;
        }

        $model['recent'] = array_map(static fn (array $call): array => [
            'at' => substr((string) $call['at'], 11, 8),
            'code' => trim(substr((string) $call['purpose'], strlen('implementing'))),
            'finish' => (string) ($call['finish'] ?? ''),
            'output' => (int) ($call['output'] ?? 0),
            'seconds' => (float) ($call['seconds'] ?? 0),
        ], array_reverse(array_slice($writer, -6)));
        unset($model['answers']);

        return $model;
    }

    /**
     * Every row the harness has touched, with what happened to it last, most urgent first.
     *
     * The old feed read "implemented" markers that the harness no longer writes, so it showed the same
     * rows forever. This reads what the loop acts on: the attempt counter, the stuck marker, the last
     * failure and the delivered list. Rows in the ledger the harness never tried are not here.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        $ledger = $this->openLedger();
        $delivered = $this->queue()['deliveredCodes'];
        $writing = [];
        foreach ($this->workers() as $worker) {
            // A shard that skipped a row ("skipped X (cooling off)") is not writing it.
            if (!str_starts_with($worker['detail'], 'skipped') && preg_match('/\b([A-Z]+-\d+)\b/', $worker['detail'], $m)) {
                $writing[$m[1]] = true;
            }
        }

        $now = time();
        // Only rows still open in the ledger: a frozen or finished row keeps its old counter, and listing
        // it would bury what is moving tonight.
        $codes = array_values(array_filter(array_unique([
            ...array_map(static fn (string $f): string => basename($f, '.count'), glob($this->path('plan/research/ogame/attempts/*.count')) ?: []),
            ...$delivered,
            ...array_keys($writing),
        ]), static fn (string $code): bool => isset($ledger[$code])));

        $rows = [];
        foreach ($codes as $code) {
            $counter = $this->path("plan/research/ogame/attempts/{$code}.count");
            $log = $this->path("plan/research/ogame/attempts/{$code}.log");
            $attempts = is_file($counter) ? (int) trim((string) file_get_contents($counter)) : 0;
            $touched = (int) max(@filemtime($counter) ?: 0, @filemtime($log) ?: 0);
            $stuck = is_file($this->path("plan/research/ogame/attempts/{$code}.stuck"));

            $state = match (true) {
                isset($writing[$code]) => 'writing',
                in_array($code, $delivered, true) => 'delivered',
                $stuck => 'stuck',
                $attempts >= 3 && $now - $touched < self::COOLOFF_SECONDS => 'cooling',
                default => 'retrying',
            };

            // A row nobody has touched for hours is not part of tonight's work.
            if ($state === 'retrying' && ($touched === 0 || $now - $touched > 10800)) {
                continue;
            }

            $failure = '';
            if (is_file($log) && $state !== 'delivered') {
                $text = preg_replace('/\x1b\[[0-9;]*m/', '', (string) file_get_contents($log)) ?? '';
                foreach (preg_split('/\R/', $text) ?: [] as $line) {
                    if (trim($line) !== '') {
                        $failure = mb_substr(trim($line), 0, 160);
                        break;
                    }
                }
            }

            $rows[] = [
                'code' => $code,
                'title' => $ledger[$code]['title'],
                'priority' => $ledger[$code]['priority'],
                'state' => $state,
                'attempts' => $attempts,
                'age' => $touched === 0 ? null : $now - $touched,
                'failure' => $failure,
                'coolsIn' => $state === 'cooling' ? max(0, self::COOLOFF_SECONDS - ($now - $touched)) : null,
            ];
        }

        $order = ['writing' => 0, 'delivered' => 1, 'retrying' => 2, 'cooling' => 3, 'stuck' => 4];
        usort($rows, static fn (array $a, array $b): int => [$order[$a['state']], $a['age'] ?? PHP_INT_MAX] <=> [$order[$b['state']], $b['age'] ?? PHP_INT_MAX]);

        return array_slice($rows, 0, 40);
    }

    /**
     * The rows still open in the ledger (todo, in progress, blocked), by code.
     *
     * @return array<string, array{title: string, priority: string}>
     */
    private function openLedger(): array
    {
        if (!is_file($this->taskDatabase())) {
            return [];
        }

        try {
            $connection = new PDO('sqlite:'.$this->taskDatabase(), null, null, [PDO::ATTR_TIMEOUT => 2]);
            $rows = $this->queryRows($connection, "SELECT code, title, priority FROM tasks WHERE status IN ('todo', 'in_progress', 'blocked')");
        } catch (Throwable) {
            return [];
        }

        $ledger = [];
        foreach ($rows as $row) {
            $ledger[(string) $row['code']] = ['title' => (string) $row['title'], 'priority' => (string) $row['priority']];
        }

        return $ledger;
    }

    /**
     * The newest cohort verdict the harness wrote: the invariants and aspects it flagged.
     *
     * @return array<string, mixed>
     */
    private function cohort(): array
    {
        $file = '/tmp/harness-quality-grand.txt';
        $cohort = ['at' => null, 'violations' => [], 'saturated' => []];
        if (!is_file($file)) {
            return $cohort;
        }

        $cohort['at'] = date('H:i:s', (int) filemtime($file));
        $text = preg_replace('/\x1b\[[0-9;]*m/', '', (string) file_get_contents($file)) ?? '';

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, '! [')) {
                $cohort['violations'][] = mb_substr($line, 2, 140);
            }
            if (str_starts_with($line, 'SATURATED')) {
                $cohort['saturated'][] = mb_substr($line, 0, 140);
            }
        }

        // The same invariant fires once per account; one line per invariant is what a person reads.
        $counts = [];
        foreach ($cohort['violations'] as $violation) {
            $name = preg_match('/^\[(\w+)\]/', $violation, $m) ? $m[1] : 'other';
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        $cohort['violations'] = array_map(static fn (string $name, int $n): array => ['name' => $name, 'count' => $n], array_keys($counts), $counts);
        $cohort['saturated'] = array_slice($cohort['saturated'], 0, 3);

        return $cohort;
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
