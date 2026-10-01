<?php

namespace Modules\AI\Tests\Support;

use Illuminate\Support\Facades\DB;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\Models\DebrisField;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Services\PlanetService;

/**
 * Plant a situation on a test account, let the account play one real session, read what it decided.
 *
 * The live cohort proves a rule too slowly to iterate on: a situation takes minutes, depends on workers
 * and a clock, and cannot be composed. This does the same in about a second on the test database with the
 * real host services: state goes in through the host's own helpers, the session runs the real planners and
 * executors in-process, and the answer is the work the account created. Every method returns the situation,
 * so a test reads as the story it proves:
 *
 *     Situation::of($this)->resources(1_000_000, 1_000_000, 1_000_000)->ships('recycler', 2)
 *         ->debris(40_000, 20_000)->session()->expectWork(AiWorkKind::Recycle);
 *
 * Nothing here names a building, ship or technology: callers pass machine names, so an object a mod adds
 * is plantable with no edit.
 */
final class Situation
{
    private AiProfile $profile;

    private ?PlanetService $neighbour = null;

    private function __construct(private readonly object $test)
    {
        // The conversation lane is a language-model call and has no part in a rule about play.
        config(['ai.cognition.conversation.enabled' => false]);
        $this->bindExecutors();

        $playerId = $this->host('currentUserId');
        $this->profile = AiProfile::query()->where('player_id', $playerId)->first() ?? AiProfile::create([
            'player_id' => $playerId,
            'archetype' => AiArchetype::Miner,
            'skill_band' => AiSkillBand::Standard,
            'random_seed' => 20_000 + $playerId,
            'enabled' => true,
        ]);
    }

    /** The account the test case already made, as an AI-managed Miner of ordinary skill. */
    public static function of(object $test): self
    {
        return new self($test);
    }

    public function archetype(AiArchetype $archetype): self
    {
        $this->profile->update(['archetype' => $archetype]);

        return $this;
    }

    public function resources(int $metal, int $crystal, int $deuterium): self
    {
        $this->host('planetAddResources', new Resources($metal, $crystal, $deuterium));

        return $this;
    }

    public function level(string $machineName, int $level): self
    {
        $this->host('planetSetObjectLevel', $machineName, $level);

        return $this;
    }

    public function research(string $machineName, int $level): self
    {
        $this->host('playerSetResearchLevel', $machineName, $level);

        return $this;
    }

    public function ships(string $machineName, int $amount): self
    {
        $this->host('planetAddUnit', $machineName, $amount);

        return $this;
    }

    /** A debris field at the account's own coordinates, where the host keeps one field per position. */
    public function debris(int $metal, int $crystal): self
    {
        $home = $this->host('planetService')->getPlanetCoordinates();
        DebrisField::query()->where(['galaxy' => $home->galaxy, 'system' => $home->system, 'planet' => $home->position])->delete();
        DebrisField::create(['galaxy' => $home->galaxy, 'system' => $home->system, 'planet' => $home->position, 'metal' => $metal, 'crystal' => $crystal, 'deuterium' => 0]);

        return $this;
    }

    /** A hostile fleet due at the account's planet in $seconds: the host's own attack row from a neighbour. */
    public function hostileFleet(int $seconds): self
    {
        $foreign = $this->host('createForeignPlanet');
        $mission = new FleetMission();
        $mission->user_id = $foreign->getPlayer()->getId();
        $mission->planet_id_from = $foreign->getPlanetId();
        $mission->planet_id_to = $this->host('currentPlanetId');
        $mission->mission_type = 1;
        $mission->time_departure = now()->subMinute()->timestamp;
        $mission->time_arrival = now()->addSeconds($seconds)->timestamp;
        $mission->time_arrival_ms = 0;
        $mission->processed = 0;
        $mission->canceled = 0;
        $mission->save();

        return $this;
    }

    /** A neighbour who stopped logging in $daysQuiet days ago, holding stock a raid would be worth. */
    public function inactiveNeighbour(int $daysQuiet = 8, int $metal = 400_000, int $crystal = 200_000): self
    {
        $this->neighbour = $this->host('createForeignPlanet');
        $this->neighbour->addResources(new Resources($metal, $crystal, 0));
        DB::table('users')->where('id', $this->neighbour->getPlayer()->getId())->update(['time' => now()->subDays($daysQuiet)->timestamp]);

        return $this;
    }

    public function minutesLater(int $minutes): self
    {
        $this->host('travel', $minutes)->minutes();

        return $this;
    }

    /**
     * One real session: the planners decide, then every intent the session scheduled runs through its
     * executor, to a fixed point. $rounds bounds an intent that keeps scheduling more.
     */
    public function session(int $rounds = 6): self
    {
        $session = AiWorkItem::create([
            'player_id' => $this->profile->player_id,
            'kind' => AiWorkKind::RunSession,
            'due_at' => now(),
            'idempotency_key' => 'situation-session:' . $this->profile->player_id . ':' . uniqid(),
            'state' => AiWorkState::Pending,
        ]);
        app()->makeWith(ProcessAiWork::class, ['workItemId' => $session->id])->handle();

        for ($round = 0; $round < $rounds; $round++) {
            $due = AiWorkItem::query()->where('player_id', $this->profile->player_id)
                ->where('state', AiWorkState::Pending)->where('kind', '!=', AiWorkKind::RunSession)->get();
            if ($due->isEmpty()) {
                break;
            }
            foreach ($due as $intent) {
                app()->makeWith(ProcessAiWork::class, ['workItemId' => $intent->id])->handle();
            }
        }

        return $this;
    }

    /** @return list<AiWorkKind> every kind of work the account created, in the order it did. */
    public function work(): array
    {
        return AiWorkItem::query()->where('player_id', $this->profile->player_id)
            ->where('kind', '!=', AiWorkKind::RunSession)->orderBy('id')->get()
            ->map(static fn (AiWorkItem $item): AiWorkKind => $item->kind)->all();
    }

    public function expectWork(AiWorkKind $kind): self
    {
        expect(in_array($kind, $this->work(), true))->toBeTrue('expected ' . $kind->name . ' work, but ' . $this->account());

        return $this;
    }

    public function expectNoWork(AiWorkKind $kind): self
    {
        expect(in_array($kind, $this->work(), true))->toBeFalse('expected no ' . $kind->name . ' work, but ' . $this->account());

        return $this;
    }

    /**
     * What the account did and why: the work it created, and the candidates its last decision ranked with
     * the score components that made them win or lose. A failing expectation then says "Recycle scored 12.0
     * (resource_need=0.4, safety=0.3) against Build 40.7" instead of "false".
     */
    public function account(): string
    {
        $kinds = array_map(static fn (AiWorkKind $kind): string => $kind->name, $this->work());
        $trace = DB::table('ai_decision_traces')->where('player_id', $this->profile->player_id)->orderByDesc('id')->first(['candidates']);
        $ranked = array_map(static function (array $candidate): string {
            $parts = [];
            foreach (array_filter($candidate['components'] ?? []) as $name => $value) {
                $parts[] = $name . '=' . round((float) $value, 1);
            }

            return sprintf('%s %.1f (%s)', $candidate['action'], (float) $candidate['score'], implode(', ', $parts));
        }, array_slice(json_decode((string) ($trace->candidates ?? '[]'), true) ?: [], 0, 6));

        return 'the account created work: [' . implode(', ', $kinds) . '] and its last decision ranked: ' . ($ranked === [] ? 'no candidates' : implode('; ', $ranked));
    }

    /** The neighbour planted by inactiveNeighbour(), for a test that asserts on what happened to it. */
    public function neighbour(): ?PlanetService
    {
        return $this->neighbour;
    }

    /**
     * The module's executors are bound by the service provider only for an enabled module, and the test
     * case boots without it: bind each `Contracts\QueueAiX` to the `Actions\QueueAiXAction` that implements
     * it, so a session can run end to end whatever a particular test needed.
     */
    private function bindExecutors(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/app/Contracts/QueueAi*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $contract = 'Modules\\AI\\Contracts\\' . $name;
            $action = 'Modules\\AI\\Actions\\' . $name . 'Action';
            if (class_exists($action) && !app()->bound($contract)) {
                app()->bind($contract, $action);
            }
        }
    }

    /** Call a (possibly protected) helper or read a property of the test case, which owns the host fixtures. */
    private function host(string $name, mixed ...$arguments): mixed
    {
        return (function () use ($name, $arguments) {
            return method_exists($this, $name) ? $this->{$name}(...$arguments) : $this->{$name};
        })->call($this->test);
    }
}
