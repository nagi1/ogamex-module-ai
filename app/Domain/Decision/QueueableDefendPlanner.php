<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Actions\QueueAiDefendAction;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\FlightFuel;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\AcsDefendMission;
use OGame\Models\BattleReport;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;

/**
 * Whether this account should send part of its combat fleet to hold at an ally just seen attacked.
 *
 * The consumer DEF-010 asked for: the AllyUnderAttack observation names the report, the report names
 * the defending co-member. The account helps only a member it still shares an alliance with, only once
 * per ally per window, and only from a body that owns combat hulls *and* can pay the host's own fuel
 * quote for the flight it would send — a plan the gate would refuse is no help at all. The host's
 * ACS-defend gate (buddy or alliance, ACS switched on, target exists) is the final word at dispatch.
 */
class QueueableDefendPlanner
{
    private const WINDOW_HOURS = 6;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private PlanetServiceFactory $planetServiceFactory,
        private QueueAiDefendAction $defendAction,
    ) {
    }

    public function plan(int $playerId): ?QueueableDefend
    {
        if (!AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->exists()) {
            return null;
        }

        $myAlliance = User::query()->whereKey($playerId)->value('alliance_id');
        if ($myAlliance === null) {
            return null;
        }

        $reportIds = AiObservation::query()
            ->where('player_id', $playerId)
            ->where('kind', AiObservationKind::AllyUnderAttack)
            ->where('observed_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->orderByDesc('observed_at')
            ->pluck('source_id');

        if ($reportIds->isEmpty()) {
            return null;
        }

        $player = $this->playerServiceFactory->make($playerId, true);
        // A body the gate just refused a dispatch from is no source this login offers again: a refused
        // dispatch is not a mission, so the empty tank it blamed is only remembered here.
        $refusedOrigins = app(RecentRefusals::class)->refusedOrigins($playerId, AiWorkKind::Defend);

        foreach ($reportIds as $reportId) {
            $allyId = BattleReport::query()->whereKey($reportId)->value('planet_user_id');
            if ($allyId === null || User::query()->whereKey($allyId)->value('alliance_id') !== $myAlliance) {
                continue;
            }

            $targetId = Planet::query()->where('user_id', $allyId)->orderBy('id')->value('id');
            if ($targetId === null || $this->alreadyHelping($playerId, (int) $targetId)) {
                continue;
            }

            $target = $this->planetServiceFactory->make((int) $targetId, true);
            if ($target === null) {
                continue;
            }

            $source = $this->source($player, $target, $refusedOrigins);
            if ($source === null) {
                continue;
            }

            return app()->makeWith(QueueableDefend::class, [
                'sourcePlanetId' => $source->getPlanetId(),
                'targetPlanetId' => (int) $targetId,
            ]);
        }

        return null;
    }

    /**
     * The own body that flies the help: it must own combat hulls to send, the gate must not have just
     * refused a dispatch from it, and it must hold the deuterium the host's own quote demands for the
     * exact fleet and route — the same quote the dispatch gate asks, so the plan is never refused for
     * a tank the planner already knew was empty.
     *
     * @param array<int, true> $refusedOrigins own bodies the gate just refused a dispatch from
     */
    private function source(PlayerService $player, PlanetService $target, array $refusedOrigins): ?PlanetService
    {
        foreach ($player->planets->all() as $source) {
            if (isset($refusedOrigins[$source->getPlanetId()])) {
                continue;
            }

            $fleet = $this->defendAction->heldFleet($source);
            if ($fleet->units === []) {
                continue;
            }

            if (!app(FlightFuel::class)->affordable($player, $source, $fleet, $target->getPlanetCoordinates(), QueueAiDefendAction::SPEED, QueueAiDefendAction::HOLDING_HOURS)) {
                continue;
            }

            return $source;
        }

        return null;
    }

    /** A defence already sent (and not yet home) to this planet is not sent twice. */
    private function alreadyHelping(int $playerId, int $targetPlanetId): bool
    {
        return FleetMission::query()
            ->where('user_id', $playerId)
            ->where('mission_type', AcsDefendMission::getTypeId())
            ->where('planet_id_to', $targetPlanetId)
            ->where('processed', 0)
            ->where('canceled', 0)
            ->exists();
    }
}
