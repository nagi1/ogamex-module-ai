<?php

namespace Modules\AI\Domain\Perception;

use Modules\AI\Domain\Decision\QueueableBuilding;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Decision\QueueableColonyPlanner;
use Modules\AI\Domain\Decision\QueueableFleetSavePlanner;
use Modules\AI\Domain\Decision\QueueableResearch;
use Modules\AI\Domain\Decision\QueueableSpyPlanner;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Domain\Decision\SaveFailurePolicy;
use Modules\AI\Domain\Lifecycle\AccountStateResolver;
use Modules\AI\Enums\AiAccountState;
use Modules\AI\Enums\AiCapability;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Message;
use OGame\Services\FleetMissionService;

/**
 * Reduces only the AI account's own current state into a transport-safe input.
 *
 * Enemy intelligence enters through explicitly published reports elsewhere;
 * keeping it out here prevents a future policy from accidentally gaining host
 * model access it was never meant to have.
 */
class PlayerObservationService
{
    /**
     * How long one espionage report stays fresh enough to raid from, before the
     * target has to be re-scouted. A stale target dies on its own; the account
     * does not raid blind.
     */
    private const INTEL_TTL_HOURS = 24;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private QueueableBuildingPlanner $queueableBuildingPlanner,
        private QueueableUnitPlanner $queueableUnitPlanner,
        private QueueableColonyPlanner $queueableColonyPlanner,
        private QueueableFleetSavePlanner $queueableFleetSavePlanner,
        private QueueableSpyPlanner $queueableSpyPlanner,
        private SaveFailurePolicy $saveFailurePolicy,
        private AccountStateResolver $accountStateResolver,
    ) {
    }

    /**
     * @return array{
     *     player_id:int,
     *     observed_at:int,
     *     account_state:string,
     *     planets:array<int, array{id:int, resources:array<string, float|int>}>,
     *     available_actions:array<string, bool>,
     *     target_reports:array<int, array<string, mixed>>,
     *     fleetsave_eligible:bool,
     *     fleetsave_skip_reason:string|null,
     *     inbound_fleets:list<array{mission_id:int, mission_type:int, time_arrival:int, planet_id_to:int}>
     * }
     */
    public function ownedState(int $playerId): array
    {
        $state = $this->accountStateResolver->resolve($playerId);
        $active = $state === AiAccountState::Active;

        return [
            'player_id' => $playerId,
            'observed_at' => (int) now()->timestamp,
            'account_state' => $state->value,
            'planets' => $state === AiAccountState::Final ? [] : $this->planets($playerId),
            // A suspended account is not playing, and the host is the authority
            // on that state: a banned or vacationing account is offered nothing
            // rather than a capability it cannot act on, so its session records
            // that it did nothing instead of recording a decision the host
            // would refuse. An account with no planets, and one the host no
            // longer has, are offered nothing for the same reason.
            'available_actions' => $active ? $this->availableActions($playerId) : [],
            // Enemy intel arrives through the host's own espionage-report
            // messages, never from this module reaching into target state.
            'target_reports' => $active ? $this->targetReports($playerId) : [],
            // Inbound fleets are assembled from the host's active fleet missions the same way
            // the fleet movement page does. IncomingFleetIntelService only redacts a row that
            // already exists; it is not the source of the inbound picture.
            ...$this->inboundThreat($playerId, $active),
        ];
    }

    /**
     * The espionage reports this account has received and can still act on.
     *
     * The host delivers a report as a message row carrying `espionage_report_id`;
     * that row is the account's own record, so reading it here publishes only
     * what the account has been told. The actual target state stays with the
     * report and the estimator — no target model reaches a policy from here.
     *
     * @return array<int, array{report_id:int, observed_at:int, expires_at:int, confidence:float, travel_cost:float, attack_permitted:bool}>
     */
    private function targetReports(int $playerId): array
    {
        $cutoff = now()->subHours(self::INTEL_TTL_HOURS);

        return Message::query()
            ->where('user_id', $playerId)
            ->whereNotNull('espionage_report_id')
            ->where('created_at', '>=', $cutoff)
            ->orderByDesc('id')
            ->limit(10)
            ->get(['espionage_report_id', 'created_at'])
            ->map(fn (Message $message): array => [
                'report_id' => (int) $message->espionage_report_id,
                'observed_at' => (int) ($message->created_at->timestamp ?? 0),
                'expires_at' => (int) ($message->created_at?->addHours(self::INTEL_TTL_HOURS)->timestamp ?? 0),
                // The report is the host's own picture at probe time; how much
                // it reveals is already redacted by the host's espionage level.
                'confidence' => 1.0,
                // Travel cost is the estimator's number, not a perception fact;
                // the candidate scorer treats this as unknown until then.
                'travel_cost' => 0.0,
                // Bashing and target legality are re-checked by the raid planner
                // at decision time, so the intel itself is simply attackable.
                'attack_permitted' => true,
            ])
            ->all();
    }

    /** @return array<int, array{id:int, resources:array<string, float|int>}> */
    private function planets(int $playerId): array
    {
        $planets = [];
        foreach ($this->playerServiceFactory->make($playerId, true)->planets->all() as $planet) {
            $planets[] = [
                'id' => $planet->getPlanetId(),
                'resources' => [
                    'metal' => $planet->metal()->get(),
                    'crystal' => $planet->crystal()->get(),
                    'deuterium' => $planet->deuterium()->get(),
                ],
            ];
        }

        return $planets;
    }

    /**
     * Only a capability the module can actually carry out is published.
     *
     * Publishing one it cannot is how the population came to decide without ever acting: a trace
     * would claim an action while the host was never touched, which reads as a quiet population
     * rather than as the gap it is. The economy plan answers with the queue the next step belongs
     * to, and the unit plan answers separately once a shipyard exists -- only those answers are
     * published.
     *
     * @return array<string, bool>
     */
    private function availableActions(int $playerId): array
    {
        $step = $this->queueableBuildingPlanner->plan($playerId);

        return [
            AiCapability::Build->value => $step instanceof QueueableBuilding,
            AiCapability::Research->value => $step instanceof QueueableResearch,
            AiCapability::QueueUnits->value => $this->queueableUnitPlanner->plan($playerId) !== null,
            AiCapability::Colonize->value => $this->queueableColonyPlanner->plan($playerId) !== null,
            AiCapability::Spy->value => $this->queueableSpyPlanner->plan($playerId) !== null,
        ];
    }

    /**
     * Foreign fleets headed at this account, and whether the host says that means under attack.
     *
     * Assembled from the host's active fleet missions the same way the fleet movement page does.
     * Which missions count as hostile is the host's `currentPlayerUnderAttack()` answer — this
     * module does not keep a mission-type list. The inbound rows carry only what every account
     * can see without espionage (id, type, ETA, destination); composition stays with the host's
     * redactor on the movement page.
     *
     * @return array{
     *     fleetsave_eligible:bool,
     *     fleetsave_skip_reason:string|null,
     *     inbound_fleets:list<array{mission_id:int, mission_type:int, time_arrival:int, planet_id_to:int}>
     * }
     */
    private function inboundThreat(int $playerId, bool $active): array
    {
        if (!$active) {
            return [
                'fleetsave_eligible' => false,
                'fleetsave_skip_reason' => null,
                'inbound_fleets' => [],
            ];
        }

        $player = $this->playerServiceFactory->make($playerId, true);
        $fleetMissions = app()->makeWith(FleetMissionService::class, ['player' => $player]);

        $inbound = [];
        foreach ($fleetMissions->getActiveFleetMissionsForCurrentPlayer() as $mission) {
            if ($mission->user_id === $playerId) {
                continue;
            }

            $inbound[] = [
                'mission_id' => (int) $mission->id,
                'mission_type' => (int) $mission->mission_type,
                'time_arrival' => (int) $mission->time_arrival,
                'planet_id_to' => (int) $mission->planet_id_to,
            ];
        }

        // A fleetsave candidate is only offered when it is both needed and possible: the host
        // says a hostile is inbound, and the account has a fleet and a second body to move it
        // to. Offering it otherwise is how a trace claims an action the account cannot take.
        $saveable = $fleetMissions->currentPlayerUnderAttack()
            && $this->queueableFleetSavePlanner->plan($playerId) !== null;

        // A save that fails is decided against a specific inbound fleet, so the same threat gets
        // the same judgement however many times the session re-reads it.
        if ($saveable) {
            $key = $inbound === [] ? 0 : (int) min(array_column($inbound, 'mission_id'));
            $seed = AiProfile::query()->where('player_id', $playerId)->value('random_seed');
            $skipReason = $this->saveFailurePolicy->shouldSkip($seed === null ? null : (int) $seed, $key);

            if ($skipReason !== null) {
                return [
                    'fleetsave_eligible' => false,
                    'fleetsave_skip_reason' => $skipReason,
                    'inbound_fleets' => $inbound,
                ];
            }
        }

        return [
            'fleetsave_eligible' => $saveable,
            'fleetsave_skip_reason' => null,
            'inbound_fleets' => $inbound,
        ];
    }
}
