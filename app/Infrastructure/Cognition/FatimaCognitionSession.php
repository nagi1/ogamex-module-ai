<?php

namespace Modules\AI\Infrastructure\Cognition;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\AI\Enums\AiArchetype;
use Throwable;

/**
 * The single integrated FAtiMA character state behind both cognition contracts.
 *
 * The module requires one character state per persona, not one per contract, so this
 * session is the only place that talks to the sidecar and both adapters share it.
 *
 * The driver holds emotion and mood in process memory. Left alone, the same stimulus
 * would produce a drifting intensity as mood accumulated, so every appraisal reloads
 * the authored scenario first: that restores a clean emotional state and keeps the
 * driver a calculator rather than a store. The module's own affect, relationship and
 * obligation tables stay authoritative.
 */
class FatimaCognitionSession
{
    /** How long to wait between attempts while another appraisal holds the character. */
    private const RETRY_MICROSECONDS = 50_000;

    public function __construct(private readonly FatimaClient $client)
    {
    }

    /**
     * @param  array<string, string>  $beliefs
     * @return array{mood: float, emotions: list<FatimaEmotion>}|null
     */
    public function appraise(AiArchetype $archetype, string $event, array $beliefs): array|null
    {
        return $this->remembered('appraise', [$archetype->name, $event, $beliefs], fn (): array|null => $this->appraiseOnce($archetype, $event, $beliefs));
    }

    /**
     * Every appraisal reloads the authored scenario first, so the answer is a function of the request
     * alone. The same request is answered from the cache instead of four or more round trips, and a
     * failed (null) answer is never kept.
     *
     * @template TResult
     * @param  array<int, mixed>  $request
     * @param  Closure(): (TResult|null)  $compute
     * @return TResult|null
     */
    private function remembered(string $kind, array $request, Closure $compute): mixed
    {
        $key = 'ai:fatima:' . $kind . ':' . md5(json_encode($request) ?: '');
        $known = Cache::get($key);

        if ($known !== null) {
            return $known;
        }

        $result = $compute();

        if ($result !== null) {
            Cache::put($key, $result, now()->addHour());
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $beliefs
     * @return array{mood: float, emotions: list<FatimaEmotion>}|null
     */
    private function appraiseOnce(AiArchetype $archetype, string $event, array $beliefs): array|null
    {
        return $this->sequential(function () use ($archetype, $event, $beliefs): array|null {
            $scenario = $this->scenario();
            $character = $archetype->name;

            $this->client->loadScenario($scenario);
            $this->writeBeliefs($scenario, $character, $beliefs);
            $this->client->perceive($scenario, $this->instance(), $character, $event);

            $state = $this->client->emotions($scenario, $this->instance(), $character);

            if ($state === null) {
                return null;
            }

            // The pool spans recent appraisals, so only the event just perceived counts.
            return [
                'mood' => $state['mood'],
                'emotions' => array_values(array_filter(
                    $state['emotions'],
                    static fn (FatimaEmotion $emotion): bool => $emotion->causeEvent === $event,
                )),
            ];
        });
    }

    /**
     * @param  array<string, string>  $beliefs
     * @return list<array{name: string, step: string, volitions: array<string, float>}>|null
     */
    public function evaluateSocialExchanges(AiArchetype $archetype, string $counterparty, array $beliefs): array|null
    {
        return $this->remembered('social', [$archetype->name, $counterparty, $beliefs], fn (): array|null => $this->evaluateSocialExchangesOnce($archetype, $counterparty, $beliefs));
    }

    /**
     * @param  array<string, string>  $beliefs
     * @return list<array{name: string, step: string, volitions: array<string, float>}>|null
     */
    private function evaluateSocialExchangesOnce(AiArchetype $archetype, string $counterparty, array $beliefs): array|null
    {
        return $this->sequential(function () use ($archetype, $counterparty, $beliefs): array|null {
            $scenario = $this->scenario();
            $character = $archetype->name;

            $this->client->loadScenario($scenario);
            $this->writeBeliefs($scenario, $character, $beliefs);

            return $this->client->evaluateSocialExchanges($scenario, $this->instance(), $character, $counterparty);
        });
    }

    /**
     * @param  array<string, string>  $beliefs
     */
    private function writeBeliefs(string $scenario, string $character, array $beliefs): void
    {
        foreach ($beliefs as $name => $value) {
            $this->client->setBelief($scenario, $this->instance(), $character, $name, $value);
        }
    }

    /**
     * The sidecar answers one request at a time, so a run of belief writes followed by a
     * perception must not interleave with another appraisal against the same character.
     *
     * @template TResult
     * @param  Closure(): (TResult|null)  $operation
     * @return TResult|null
     */
    private function sequential(Closure $operation): mixed
    {
        try {
            return $this->underLock($operation);
        } catch (Throwable $exception) {
            // Contention or an unusable lock store degrades to the native implementation
            // rather than risking interleaved state on a shared character.
            Log::warning('The FAtiMA cognition session could not acquire its lock.', [
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @template TResult
     * @param  Closure(): (TResult|null)  $operation
     * @return TResult|null
     */
    private function underLock(Closure $operation): mixed
    {
        $lock = Cache::lock('ai:cognition:fatima', $this->lockSeconds());

        if (!$this->acquireWithin($lock)) {
            throw new LockTimeoutException();
        }

        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }

    /**
     * Waits for the lock against a wall clock instead of the framework's deadline.
     *
     * Laravel's Lock::block() derives its timeout from Carbon::now(). A frozen or
     * backwards system clock therefore never reaches that deadline and its retry loop
     * spins without end, which is what a test travelling in time does to a shared
     * character. microtime() is independent of the test clock, so this bound holds under
     * time travel as well.
     */
    private function acquireWithin(Lock $lock): bool
    {
        $deadline = microtime(true) + $this->lockSeconds();

        while (!$lock->get()) {
            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(self::RETRY_MICROSECONDS);
        }

        return true;
    }

    private function scenario(): string
    {
        return (string) config('ai.cognition.fatima.scenario', 'OgameCognition');
    }

    private function instance(): int
    {
        return (int) config('ai.cognition.fatima.instance', 1);
    }

    private function lockSeconds(): int
    {
        return max(1, (int) config('ai.cognition.fatima.lock_seconds', 10));
    }
}
