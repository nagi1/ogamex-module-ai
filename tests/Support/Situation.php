<?php

namespace Modules\AI\Tests\Support;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\AI\Actions\AdvanceAiAllianceLifeAction;
use Modules\AI\Actions\AdvanceAiCampaignStateAction;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\Factories\GameMissionFactory;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Alliance;
use OGame\Models\BuildingQueue;
use OGame\Models\ChatMessage;
use OGame\Models\DebrisField;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\ResearchQueue;
use OGame\Models\Resources;
use OGame\Models\UnitQueue;
use OGame\Services\AllianceService;
use OGame\Services\ChatService;
use OGame\Services\MessageService;
use OGame\Services\ObjectService;
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

    private ?PlanetService $stranger = null;

    private ?Alliance $alliance = null;

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

    /** The same stock on every planet the account owns, colonies included: an economy with no excuse to idle. */
    public function stockEveryPlanet(int $metal, int $crystal, int $deuterium): self
    {
        foreach (Planet::query()->where('user_id', $this->profile->player_id)->where('planet_type', 1)->pluck('id') as $planetId) {
            app(PlanetServiceFactory::class)->make((int) $planetId, true)?->addResources(new Resources($metal, $crystal, $deuterium));
        }

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

    /**
     * $players active players around the account, created before anything planted after this call: a real
     * universe. A test universe of two players hides every "first N by id" cap (QueueableSpyPlanner looked
     * at the 20 oldest planets, so on grand the seeded inactives were never seen while the test passed).
     */
    public function crowd(int $players = 25): self
    {
        for ($player = 0; $player < $players; $player++) {
            $this->host('createForeignPlanet');
        }

        return $this;
    }

    /** A neighbour who stopped logging in $daysQuiet days ago, holding stock a raid would be worth. */
    public function inactiveNeighbour(int $daysQuiet = 8, int $metal = 400_000, int $crystal = 200_000): self
    {
        $this->neighbour = $this->host('createForeignPlanet');
        $this->neighbour->addResources(new Resources($metal, $crystal, 0));
        DB::table('users')->where('id', $this->neighbour->getPlayer()->getId())->update(['time' => now()->subDays($daysQuiet)->timestamp]);
        $this->quiet($daysQuiet);

        return $this;
    }

    /** One more planet for the account at a free nearby slot: a colony the economy has to keep busy too. */
    public function colony(): self
    {
        $player = $this->host('planetService')->getPlayer();
        app(PlanetServiceFactory::class)->createAdditionalPlanetForPlayer($player, $this->host('getNearbyEmptyCoordinate'));

        return $this;
    }

    /**
     * A fully probed espionage report on the neighbour in the account's inbox, built field by field the way
     * the host's EspionageMission builds one, so the raid story can start from "I have a report".
     */
    public function spyReport(): self
    {
        $target = $this->neighbour ?? throw new LogicException('spyReport() reports on the neighbour: plant inactiveNeighbour() first.');
        $owner = $target->getPlayer();
        $at = $target->getPlanetCoordinates();

        $report = new EspionageReport();
        $report->planet_galaxy = $at->galaxy;
        $report->planet_system = $at->system;
        $report->planet_position = $at->position;
        $report->planet_type = $target->getPlanetType()->value;
        $report->planet_user_id = $owner->getId();
        $report->player_info = ['player_id' => (string) $owner->getId(), 'player_name' => $owner->getUsername()];
        $report->resources = [
            'metal' => (int) $target->metal()->get(),
            'crystal' => (int) $target->crystal()->get(),
            'deuterium' => (int) $target->deuterium()->get(),
            'energy' => (int) $target->energy()->get(),
        ];
        $report->ships = $target->getShipUnits()->toArray();
        $report->defense = $target->getDefenseUnits()->toArray();
        $report->buildings = $target->getBuildingArray();
        $report->research = $owner->getResearchArray();
        $report->counter_espionage_chance = 0;
        $report->save();

        app(MessageService::class)->sendEspionageReportMessageToPlayer($this->host('planetService')->getPlayer(), $report->id);

        return $this;
    }

    /** Ships or defence standing on the neighbour's planet, so a report or a raid sees a defended target. */
    public function neighbourUnits(string $machineName, int $amount): self
    {
        $target = $this->neighbour ?? throw new LogicException('neighbourUnits() arms the neighbour: plant inactiveNeighbour() first.');
        $target->addUnit($machineName, $amount);
        $this->quiet((int) now()->diffInDays(Date::createFromTimestamp((int) DB::table('users')->where('id', $target->getPlayer()->getId())->value('time')), true));

        return $this;
    }

    /**
     * The account leads an alliance and another player applied $minutesAgo minutes ago: the leader's
     * decision is due. Read the outcome with expectApplicationDecided().
     */
    public function allianceApplication(int $minutesAgo = 30): self
    {
        $this->alliance = app(AllianceService::class)->createAlliance($this->profile->player_id, 'S' . $this->profile->player_id % 10_000, 'Situation ' . $this->profile->player_id);
        $applicant = $this->stranger()->getPlayer()->getId();
        $application = app(AllianceService::class)->applyToAlliance($applicant, $this->alliance->id, 'Active player looking for a home.');
        $application->forceFill(['created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now()->subMinutes($minutesAgo)])->save();

        return $this;
    }

    /** Another player writes to the account through the host chat. Conversations are switched on for it. */
    public function directMessage(string $text = 'hey, are you active? want to trade?'): self
    {
        $this->conversations();
        app(ChatService::class)->sendDirectMessage($this->stranger()->getPlayer()->getId(), $this->profile->player_id, $text);

        return $this;
    }

    /** The authored-reply lane, off by default because a rule about play has nothing to do with it. */
    public function conversations(bool $enabled = true): self
    {
        config(['ai.cognition.conversation.enabled' => $enabled]);

        return $this;
    }

    public function minutesLater(int $minutes): self
    {
        $this->host('travel', $minutes)->minutes();

        return $this;
    }

    /**
     * One real session: the planners decide, then every intent the session scheduled runs through its
     * executor when it falls due, the clock moving to it as the live worker's would. Intents due later than
     * $windowMinutes stay pending for a later session(); $rounds bounds an intent that keeps scheduling more.
     */
    public function session(int $rounds = 12, int $windowMinutes = 30): self
    {
        $session = AiWorkItem::create([
            'player_id' => $this->profile->player_id,
            'kind' => AiWorkKind::RunSession,
            'due_at' => now(),
            'idempotency_key' => 'situation-session:' . $this->profile->player_id . ':' . uniqid(),
            'state' => AiWorkState::Pending,
        ]);
        app()->makeWith(ProcessAiWork::class, ['workItemId' => $session->id])->handle();
        $horizon = now()->addMinutes($windowMinutes);

        for ($round = 0; $round < $rounds; $round++) {
            $next = AiWorkItem::query()->where('player_id', $this->profile->player_id)
                ->where('state', AiWorkState::Pending)->where('kind', '!=', AiWorkKind::RunSession)
                ->where('due_at', '<=', $horizon)->orderBy('due_at')->orderBy('id')->first();
            if ($next === null) {
                break;
            }
            $next->due_at->isFuture() && $this->host('travelTo', $next->due_at);
            app()->makeWith(ProcessAiWork::class, ['workItemId' => $next->id])->handle();
        }

        return $this;
    }

    /** $count sessions, $minutesApart apart: a story that takes more than one login, such as a raid after a spy. */
    public function sessions(int $count, int $minutesApart = 45): self
    {
        for ($login = 0; $login < $count; $login++) {
            $login > 0 && $this->minutesLater($minutesApart);
            $this->session();
        }

        return $this;
    }

    /** The module's every-minute scheduled work (alliance life, campaigns) run once, in-process. */
    public function minute(): self
    {
        app(AdvanceAiAllianceLifeAction::class)->handle();
        app(AdvanceAiCampaignStateAction::class)->handle();

        return $this;
    }

    /** @return list<AiWorkKind> every kind of work the account created, in the order it did. */
    public function work(): array
    {
        return AiWorkItem::query()->where('player_id', $this->profile->player_id)
            ->where('kind', '!=', AiWorkKind::RunSession)->orderBy('id')->get()
            ->map(static fn (AiWorkItem $item): AiWorkKind => $item->kind)->all();
    }

    /** @return list<string> every intent an executor refused, as "action: reason", so a failure names the gate that said no. */
    public function refused(): array
    {
        return AiActionReceipt::query()->where('player_id', $this->profile->player_id)->where('state', AiReceiptState::Rejected)->orderBy('id')->get()
            ->map(static fn (AiActionReceipt $receipt): string => $receipt->action_type->name . ': ' . json_encode($receipt->result['reason'] ?? $receipt->result))->all();
    }

    /** @return list<string> machine names in the account's building, research and shipyard queues, oldest first. */
    public function queued(): array
    {
        $planets = Planet::query()->where('user_id', $this->profile->player_id)->pluck('id');
        $rows = collect()
            ->concat(BuildingQueue::query()->whereIn('planet_id', $planets)->get(['id', 'object_id']))
            ->concat(ResearchQueue::query()->whereIn('planet_id', $planets)->get(['id', 'object_id']))
            ->concat(UnitQueue::query()->whereIn('planet_id', $planets)->get(['id', 'object_id']));

        return $rows->map(static fn ($row): string => ObjectService::getObjectById((int) $row->object_id)->machine_name)->values()->all();
    }

    /** @return list<string> the missions the account flew, by the host mission class ("Recycle", "Espionage", ...). */
    public function missions(): array
    {
        $classes = GameMissionFactory::getMissionClasses();

        return FleetMission::query()->where('user_id', $this->profile->player_id)->whereNull('parent_id')->orderBy('id')->pluck('mission_type')
            ->map(static fn ($type): string => preg_replace('/Mission$/', '', class_basename($classes[(int) $type] ?? 'Unknown')))->all();
    }

    /** @return list<string> every action its decisions ranked, across every session so far. */
    public function candidates(): array
    {
        return DB::table('ai_decision_traces')->where('player_id', $this->profile->player_id)->pluck('candidates')
            ->flatMap(static fn ($json): array => array_column(json_decode((string) $json, true) ?: [], 'action'))
            ->unique()->values()->all();
    }

    /** Something of this object (any kind: building, research, ship, defence) is in one of the account's queues. */
    public function expectQueued(string $machineName): self
    {
        expect(in_array($machineName, $this->queued(), true))->toBeTrue('expected ' . $machineName . ' queued, but ' . $this->account());

        return $this;
    }

    /** Every planet the account owns has a building under construction: no idle build queue. */
    public function expectEveryPlanetBuilding(): self
    {
        $planets = Planet::query()->where('user_id', $this->profile->player_id)->where('planet_type', 1)->pluck('id');
        $building = BuildingQueue::query()->whereIn('planet_id', $planets)->where('processed', 0)->where('canceled', 0)->distinct()->pluck('planet_id');
        expect($building->count())->toBe($planets->count(), sprintf('expected all %d planets building, but %d are; ', $planets->count(), $building->count()) . $this->account());

        return $this;
    }

    /** The home planet started a building this session. session() advances the clock to each intent, so a short build may already be complete. */
    public function expectBuildingQueueBusy(): self
    {
        $busy = BuildingQueue::query()->where('planet_id', $this->host('currentPlanetId'))->where('canceled', 0)->exists();
        expect($busy)->toBeTrue('expected the build queue busy, but it is idle; ' . $this->account());

        return $this;
    }

    /** A research is running: OGame's lab is a queue of its own, beside the build queue. */
    public function expectResearchQueueBusy(): self
    {
        $planets = Planet::query()->where('user_id', $this->profile->player_id)->pluck('id');
        $busy = ResearchQueue::query()->whereIn('planet_id', $planets)->where('processed', 0)->where('canceled', 0)->exists();
        expect($busy)->toBeTrue('expected a research running, but the lab is idle; ' . $this->account());

        return $this;
    }

    /** The account flew this mission, by host mission name: "Attack", "Espionage", "Recycle", "Colonisation", ... */
    public function expectMission(string $mission): self
    {
        expect(in_array($mission, $this->missions(), true))->toBeTrue('expected a ' . $mission . ' mission, but ' . $this->account());

        return $this;
    }

    public function expectNoMission(string $mission): self
    {
        expect(in_array($mission, $this->missions(), true))->toBeFalse('expected no ' . $mission . ' mission, but ' . $this->account());

        return $this;
    }

    /** A decision at least considered this action: the step before "and chose it", for a candidate that never appears. */
    public function expectCandidate(string $action): self
    {
        expect(in_array($action, $this->candidates(), true))->toBeTrue('expected ' . $action . ' among the ranked candidates, but ' . $this->account());

        return $this;
    }

    /** The application planted by allianceApplication() is no longer pending. */
    public function expectApplicationDecided(): self
    {
        $pending = DB::table('alliance_applications')->where('alliance_id', $this->alliance?->id)->where('status', 0)->count();
        expect($pending)->toBe(0, 'expected the application decided, but it is still pending; ' . $this->account());

        return $this;
    }

    /** The account wrote back to the player who messaged it. */
    public function expectReply(): self
    {
        $replies = ChatMessage::query()->where('sender_id', $this->profile->player_id)->where('recipient_id', $this->stranger?->getPlayer()->getId())->count();
        expect($replies)->toBeGreaterThan(0, 'expected a reply to the message, but none was sent; ' . $this->account());

        return $this;
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
        $kinds = AiWorkItem::query()->where('player_id', $this->profile->player_id)->where('kind', '!=', AiWorkKind::RunSession)->orderBy('id')->get()
            ->map(static fn (AiWorkItem $item): string => $item->kind->name . '(' . $item->state->name
                . ($item->state === AiWorkState::Completed ? '' : ', due ' . now()->diffInSeconds($item->due_at, false) . 's') . ')')->all();
        $trace = DB::table('ai_decision_traces')->where('player_id', $this->profile->player_id)->orderByDesc('id')->first(['candidates', 'score_components']);
        $rejected = json_decode((string) ($trace->score_components ?? '{}'), true)['rejections'] ?? [];
        $ranked = array_map(static function (array $candidate): string {
            $parts = [];
            foreach (array_filter($candidate['components'] ?? []) as $name => $value) {
                $parts[] = $name . '=' . round((float) $value, 1);
            }

            return sprintf('%s %.1f (%s)', $candidate['action'], (float) $candidate['score'], implode(', ', $parts));
        }, array_slice(json_decode((string) ($trace->candidates ?? '[]'), true) ?: [], 0, 6));

        return 'the account created work: [' . implode(', ', $kinds) . '], executors refused: [' . implode('; ', $this->refused()) . '], queued: ['
            . implode(', ', $this->queued()) . '], flew: [' . implode(', ', $this->missions()) . '], not offered: ['
            . implode(', ', array_map(static fn ($key, $reason): string => $key . ' (' . $reason . ')', array_keys($rejected), $rejected)) . '] and its last decision ranked: ' . ($ranked === [] ? 'no candidates' : implode('; ', $ranked));
    }

    /** The neighbour planted by inactiveNeighbour(), for a test that asserts on what happened to it. */
    public function neighbour(): ?PlanetService
    {
        return $this->neighbour;
    }

    /**
     * Planting saves the neighbour's planet, which stamps it as touched now: the galaxy activity star a
     * player reads before flying. An inactive's planet was last touched when its owner left.
     */
    private function quiet(int $days): void
    {
        Planet::query()->whereKey($this->neighbour?->getPlanetId())->update(['time_last_update' => now()->subDays($days)->timestamp]);
        $this->neighbour?->reloadPlanet();
    }

    /** Another active player, created once: the applicant, the one who writes. */
    private function stranger(): PlanetService
    {
        return $this->stranger ??= $this->host('createForeignPlanet');
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
