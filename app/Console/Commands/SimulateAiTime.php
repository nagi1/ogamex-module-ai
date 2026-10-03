<?php

namespace Modules\AI\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\SimulatedTime;
use Throwable;

/**
 * Play the cohort forward by simulated hours without waiting for any of them.
 *
 * The game is calculation over timestamps, so this command owns the clock: it freezes Carbon at an
 * instant, runs everything that is due at that instant (fleet arrivals, the module's due work, the
 * once-a-while maintenance commands the scheduler would fire), then jumps straight to the next instant
 * anything is due. A quiet night costs one jump, not eight hours. The routines, waking windows and
 * night rest stay real, because the sessions read the simulated clock; nothing is accelerated or
 * bypassed, which is the difference from `ai.population.session_interval_seconds`.
 *
 * DEV TOOLING, write-heavy: it moves a database into the future, so it only runs on a database whose
 * name says it is a simulation copy (`ogamex-sim...`), never on the live cohort. `bash scripts/ogamex
 * sim` clones the cohort into one, runs this and reads the scorecard and invariants back at the
 * simulated instant.
 */
#[Description('Advance simulated game time and play the AI cohort through it without waiting.')]
#[Signature('ai:sim
    {--hours=24 : Simulated hours to play}
    {--from= : Start instant (default: where the last run on this database stopped, else the real now)}
    {--accounts= : Play only the first N enabled accounts}
    {--max-step=900 : Longest jump in simulated seconds when nothing is due}
    {--maintenance=900 : Simulated seconds between campaign, alliance and score-sample passes}
    {--highscore-every=3600 : Simulated seconds between the three highscore generators (each walks every player; the real schedule runs them every 300 s)}
    {--max-wall= : Stop after this many real seconds and keep the state, so a later run continues}
    {--external-cognition : Keep the HTTP cognition/memory sidecars and the language provider (a session then waits on their 2-5 s timeouts)}
    {--keep-accelerated : Keep ai.population.session_interval_seconds instead of playing real routines}
    {--max-errors=300 : Abort when this many errors pile up with no session having run (a broken build, not a result)}
    {--force-db : Allow a database whose name does not contain "sim"}')]
class SimulateAiTime extends Command
{
    private const PASSES_PER_INSTANT = 40;

    private const BATCH = 500;

    /** @var array<string, int> */
    private array $errors = [];

    /** @var array<string, array{0: float, 1: int}> seconds and calls per phase, printed as PROFILE at the end */
    private array $profile = [];

    private CarbonImmutable|null $nextHighscore = null;

    public function handle(): int
    {
        $database = (string) DB::connection()->getDatabaseName();

        if (!str_contains(strtolower($database), 'sim') && !$this->option('force-db')) {
            $this->error("Refusing to move [{$database}] into the future: the name must contain \"sim\" (or pass --force-db).");

            return self::FAILURE;
        }

        config(['queue.default' => 'sync']);
        if (!$this->option('external-cognition')) {
            // A session in hybrid mode calls the affect, experience and memory sidecars over HTTP (2 to 5 s
            // connect/read timeouts each) and the conversation lane calls a paid language provider. Measured
            // on grand: 181 sessions took 908 s, about 5 s each, nearly all of it waiting on those calls. The
            // native engines are the floor the sidecars sit on, so the simulation plays on them alone, and any
            // request that still escapes throws at once instead of waiting for a timeout (or spending money).
            config([
                'ai.cognition.mode' => 'native',
                'ai.cognition.memory.driver' => 'native',
                'ai.cognition.experience.driver' => 'native',
                'ai.cognition.conversation.enabled' => false,
                'ai.language.enabled' => false,
            ]);
            Http::preventStrayRequests();
        }
        if (!$this->option('keep-accelerated')) {
            config(['ai.population.session_interval_seconds' => 0]);
        }

        SimulatedTime::release();
        $stateFile = storage_path('app/ai-sim/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $database) . '.txt');
        $start = $this->startInstant($stateFile);
        $end = $start->addSeconds((int) round((float) $this->option('hours') * 3600));
        $players = $this->players();

        if ($players === []) {
            $this->error('No enabled AI account in this database.');

            return self::FAILURE;
        }

        $this->info(sprintf('SIM: %s -> %s (%.1f h), %d account(s), database %s', $start->toIso8601String(), $end->toIso8601String(), $start->floatDiffInHours($end), count($players), $database));

        $wallStart = microtime(true);
        $wallLimit = $this->option('max-wall') === null ? null : (float) $this->option('max-wall');
        $maintenanceEvery = max(60, (int) $this->option('maintenance'));
        $maxStep = max(1, (int) $this->option('max-step'));
        $now = $start;
        $nextMaintenance = $start;
        $hourIndex = -1;
        $hour = ['sessions' => 0, 'other' => 0, 'errors' => 0];
        $total = ['sessions' => 0, 'other' => 0, 'errors' => 0];
        $stoppedEarly = false;
        $jumps = 0;

        while ($now->lessThan($end)) {
            SimulatedTime::freezeAt($now);

            $this->timed('fleet arrivals', fn () => $this->runFleetArrivals());
            $ran = $this->timed('due work (all sessions and orders)', fn (): array => $this->drainDueWork($players));
            foreach ($ran as $key => $count) {
                $hour[$key] += $count;
                $total[$key] += $count;
            }

            if ($now->greaterThanOrEqualTo($nextMaintenance)) {
                $this->runMaintenance($now);
                $nextMaintenance = $now->addSeconds($maintenanceEvery);
            }

            $currentHour = (int) floor($start->diffInSeconds($now) / 3600);
            if ($currentHour !== $hourIndex) {
                if ($hourIndex >= 0) {
                    $this->reportHour($start->addHours($hourIndex), $hour);
                }
                $hourIndex = $currentHour;
                $hour = ['sessions' => 0, 'other' => 0, 'errors' => 0];
            }

            if ($total['errors'] >= max(1, (int) $this->option('max-errors')) && $total['sessions'] === 0) {
                $this->error('SIM ABORTED: ' . $total['errors'] . ' errors and no session has run -- the build is broken, the numbers would mean nothing.');
                $this->reportErrors();

                return self::FAILURE;
            }

            if ($wallLimit !== null && microtime(true) - $wallStart > $wallLimit) {
                $stoppedEarly = true;

                break;
            }

            $now = $this->timed('next-instant queries', fn (): CarbonImmutable => $this->nextInstant($now, $end, $nextMaintenance, $players, $maxStep));
            $jumps++;
        }

        if ($hourIndex >= 0) {
            $this->reportHour($start->addHours($hourIndex), $hour);
        }

        $stoppedAt = $stoppedEarly ? $now : $end;
        SimulatedTime::freezeAt($stoppedAt);
        @mkdir(dirname($stateFile), 0775, true);
        file_put_contents($stateFile, $stoppedAt->toIso8601String());

        $this->reportErrors();
        $this->line(sprintf('JUMPS: %d (average %.0f simulated seconds per jump)', $jumps, $start->diffInSeconds($stoppedAt) / max(1, $jumps)));
        $this->reportProfile();
        $wall = max(0.001, microtime(true) - $wallStart);
        $simulated = $start->diffInSeconds($stoppedAt);
        $this->info(sprintf(
            'SIM_NOW: %s',
            $stoppedAt->toIso8601String(),
        ));
        $this->info(sprintf(
            'SIM: %s h played in %.0f s (x%.0f), %d session(s), %d other work item(s), %d error(s)%s',
            number_format($simulated / 3600, 1),
            $wall,
            $simulated / $wall,
            $total['sessions'],
            $total['other'],
            $total['errors'],
            $stoppedEarly ? ' -- stopped at --max-wall, run again to continue' : '',
        ));

        return self::SUCCESS;
    }

    /** @template T @param callable(): T $work @return T */
    private function timed(string $phase, callable $work): mixed
    {
        $started = microtime(true);

        try {
            return $work();
        } finally {
            $this->profile[$phase] ??= [0.0, 0];
            $this->profile[$phase][0] += microtime(true) - $started;
            $this->profile[$phase][1]++;
        }
    }

    private function reportProfile(): void
    {
        uasort($this->profile, fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $this->line('PROFILE (real seconds spent per phase, biggest first):');
        foreach ($this->profile as $phase => [$seconds, $calls]) {
            $this->line(sprintf('  %8.1f s  %6d call(s)  %7.3f s/call  %s', $seconds, $calls, $seconds / max(1, $calls), $phase));
        }
    }

    private function startInstant(string $stateFile): CarbonImmutable
    {
        if ($this->option('from')) {
            return CarbonImmutable::parse((string) $this->option('from'));
        }

        if (is_file($stateFile)) {
            return CarbonImmutable::parse(trim((string) file_get_contents($stateFile)));
        }

        return CarbonImmutable::now();
    }

    /** @return list<int> */
    private function players(): array
    {
        $query = AiProfile::query()->where('enabled', true)->orderBy('player_id');

        if ($this->option('accounts') !== null) {
            $query->limit(max(1, (int) $this->option('accounts')));
        }

        return $query->pluck('player_id')->map(fn ($id): int => (int) $id)->all();
    }

    private function runFleetArrivals(): void
    {
        // The command walks the overdue backlog; calling it when nothing has landed is pure overhead.
        $due = DB::table('fleet_missions')->where('processed', 0)->where('time_arrival', '<=', SimulatedTime::now()->getTimestamp())->exists();
        if (!$due) {
            return;
        }

        try {
            Artisan::call('ogamex:scheduler:process-fleet-arrivals', ['--limit' => self::BATCH]);
        } catch (Throwable $exception) {
            $this->noteError('fleet arrivals: ' . $exception->getMessage());
        }
    }

    /**
     * Everything the real scheduler would fire during a stretch of minutes: campaign, alliance and
     * score-sample passes, language reconciliation and the three highscore generators the module
     * runs on the first tick of each five-minute window.
     */
    private function runMaintenance(CarbonImmutable $now): void
    {
        $commands = [
            'ai:advance-campaigns',
            'ai:advance-alliance-life',
            'ai:reconcile-language-requests',
            'ai:record-score-samples',
            'ai:run-campaign',
        ];

        // The highscore generators walk every player and rank them; at the real five-minute cadence they
        // dominated a simulated quarter hour, and nothing the cohort decides reads them that often.
        if ($this->nextHighscore === null || $now->greaterThanOrEqualTo($this->nextHighscore)) {
            array_push($commands, 'ogamex:scheduler:generate-highscores', 'ogamex:scheduler:generate-alliance-highscores', 'ogamex:scheduler:generate-highscore-ranks');
            $this->nextHighscore = $now->addSeconds(max(300, (int) $this->option('highscore-every')));
        }

        foreach ($commands as $command) {
            try {
                $this->timed('maintenance: ' . $command, fn (): int => Artisan::call($command));
            } catch (Throwable $exception) {
                $this->noteError($command . ': ' . $exception->getMessage());
            }
        }
    }

    /**
     * Run every work item due at the frozen instant, orders before sessions, and repeat because a
     * session can make more work due now. Returns what ran, split into sessions and other work.
     *
     * @param list<int> $players
     * @return array{sessions: int, other: int, errors: int}
     */
    private function drainDueWork(array $players): array
    {
        $ran = ['sessions' => 0, 'other' => 0, 'errors' => 0];
        $attempted = [];

        for ($pass = 0; $pass < self::PASSES_PER_INSTANT; $pass++) {
            $due = $this->dueQuery($players)
                ->orderByRaw('kind = ? asc', [AiWorkKind::RunSession->value])
                ->orderBy('due_at')
                ->limit(self::BATCH)
                ->get(['id', 'kind'])
                ->reject(fn (object $row): bool => isset($attempted[$row->id]));

            if ($due->isEmpty()) {
                break;
            }

            foreach ($due as $row) {
                $attempted[$row->id] = true;

                try {
                    app()->makeWith(ProcessAiWork::class, ['workItemId' => (int) $row->id])->handle();
                    $ran[(string) $row->kind === (string) AiWorkKind::RunSession->value ? 'sessions' : 'other']++;
                } catch (Throwable $exception) {
                    $ran['errors']++;
                    $this->noteError('work item: ' . $exception->getMessage());
                }
            }
        }

        return $ran;
    }

    /** @param list<int> $players */
    private function dueQuery(array $players): \Illuminate\Database\Query\Builder
    {
        $now = SimulatedTime::now();

        return DB::table('ai_work_items')
            ->whereIn('player_id', $players)
            ->where(function ($query) use ($now): void {
                $query->where(function ($ready) use ($now): void {
                    $ready->whereIn('state', [AiWorkState::Pending->value, AiWorkState::Retry->value])
                        ->where('due_at', '<=', $now);
                })->orWhere(function ($stranded) use ($now): void {
                    $stranded->where('state', AiWorkState::Leased->value)->where('lease_until', '<', $now);
                });
            });
    }

    /**
     * The next instant anything can happen: a work item coming due, a fleet landing, the next
     * maintenance pass, the end of the run, or `max-step` of nothing at all. Work that is already
     * due but was refused (a claim that will not take it) cannot stall the clock: it waits a minute.
     *
     * @param list<int> $players
     */
    private function nextInstant(CarbonImmutable $now, CarbonImmutable $end, CarbonImmutable $nextMaintenance, array $players, int $maxStep): CarbonImmutable
    {
        $candidates = [$end, $nextMaintenance, $now->addSeconds($maxStep)];

        $nextWork = DB::table('ai_work_items')
            ->whereIn('player_id', $players)
            ->whereIn('state', [AiWorkState::Pending->value, AiWorkState::Retry->value])
            ->min('due_at');
        if ($nextWork !== null) {
            // Still due after the drain means the worker refused it (a claim that waits for the waking
            // window, an admission that is off). Retrying such an item every simulated minute made a night
            // of hundreds of pointless jumps, so it is looked at again in five minutes.
            $due = CarbonImmutable::parse($nextWork);
            $candidates[] = $due->greaterThan($now) ? $due : $now->addSeconds(min($maxStep, 300));
        }

        $nextLease = DB::table('ai_work_items')
            ->whereIn('player_id', $players)
            ->where('state', AiWorkState::Leased->value)
            ->min('lease_until');
        if ($nextLease !== null) {
            $candidates[] = CarbonImmutable::parse($nextLease)->addSecond();
        }

        $nextArrival = DB::table('fleet_missions')
            ->where('processed', 0)->where('canceled', 0)
            ->where('time_arrival', '>', $now->getTimestamp())
            ->min('time_arrival');
        if ($nextArrival !== null) {
            $candidates[] = CarbonImmutable::createFromTimestamp((int) $nextArrival);
        }

        $next = collect($candidates)->sortBy(fn (CarbonImmutable $at): int => $at->getTimestamp())->first();

        return $next->lessThanOrEqualTo($now) ? $now->addSeconds(min($maxStep, 60)) : $next;
    }

    /** @param array{sessions: int, other: int, errors: int} $hour */
    private function reportHour(CarbonImmutable $hourStart, array $hour): void
    {
        $this->line(sprintf('  %s  sessions %-4d other %-4d errors %d', $hourStart->format('Y-m-d H:00'), $hour['sessions'], $hour['other'], $hour['errors']));
    }

    private function noteError(string $message): void
    {
        $key = mb_substr(preg_replace('/\d+/', 'N', $message) ?? $message, 0, 160);
        $this->errors[$key] = ($this->errors[$key] ?? 0) + 1;
    }

    private function reportErrors(): void
    {
        if ($this->errors === []) {
            return;
        }

        arsort($this->errors);
        $this->warn('Errors by kind (numbers folded to N):');
        foreach (array_slice($this->errors, 0, 8, true) as $message => $count) {
            $this->line(sprintf('  %5d  %s', $count, $message));
        }
    }
}
