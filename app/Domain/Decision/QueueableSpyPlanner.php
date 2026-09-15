<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\EspionageMission;
use OGame\Models\EspionageReport;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\PlanetService;

/**
 * Answers whether this account can probe a neighbour now, and which one.
 *
 * Scouting is the ordinary first contact an experienced player makes with the
 * galaxies around them, and it is what turns "no target intel" into the reports
 * raids later depend on. The target is a foreign planet the account does not own
 * and whose owner is neither in vacation mode nor the protected administrator —
 * the same gates the host's own espionage mission applies — and the probe the
 * mission consumes is the ship the host requires. The candidate list is the
 * host's own planets table, so no target is named here.
 *
 * A target the account already holds fresh intel on is skipped, so scouting is
 * bounded: each neighbour is probed once per intel window, and the account
 * stops probing once it knows the neighbourhood instead of re-probing the same
 * planets it cannot act on.
 */
class QueueableSpyPlanner
{
    /** Bounded: only this many candidate targets are inspected per decision. */
    private const MAX_CANDIDATES = 20;

    /** A report stays fresh this long; scouting and raiding agree on the window. */
    private const INTEL_TTL_HOURS = 24;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private PlanetServiceFactory $planetServiceFactory,
    ) {
    }

    public function plan(int $playerId): ?QueueableSpy
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return null;
        }

        if (!User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $origin = $this->origin($playerId);
        if ($origin === null) {
            return null;
        }

        $target = $this->target($playerId, $this->freshIntelCoordinates($playerId));
        if ($target === null) {
            return null;
        }

        return app()->makeWith(QueueableSpy::class, [
            'planetId' => $origin->getPlanetId(),
            'targetGalaxy' => (int) $target->galaxy,
            'targetSystem' => (int) $target->system,
            'targetPosition' => (int) $target->planet,
            'targetType' => (int) $target->planet_type,
            'missionType' => EspionageMission::getTypeId(),
        ]);
    }

    /**
     * The first planet carrying an idle probe.
     */
    private function origin(int $playerId): ?PlanetService
    {
        $player = $this->playerServiceFactory->make($playerId, true);

        foreach ($player->planets->all() as $planet) {
            if ($planet->getShipUnits()->getAmountByMachineName(EspionageMission::getRequiredShipMachineNames()[0]) > 0) {
                return $planet;
            }
        }

        return null;
    }

    /**
     * A legal foreign target the account has no fresh intel on yet, walking the
     * host's planets in id order.
     *
     * Own, destroyed, vacationing and administrator-protected planets are all
     * skipped — the module restates no rule, it only declines candidates the
     * host's own mission would refuse. A planet already inside the intel window
     * is skipped too: re-probing what the account already knows is how the
     * population came to scout without ever gaining anything.
     *
     * @param array<string, true> $freshCoordinates
     */
    private function target(int $playerId, array $freshCoordinates): ?Planet
    {
        $candidates = Planet::query()
            ->where('user_id', '!=', $playerId)
            ->where('destroyed', 0)
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATES)
            ->get();

        foreach ($candidates as $planet) {
            if (isset($freshCoordinates["{$planet->galaxy}:{$planet->system}:{$planet->planet}"])) {
                continue;
            }

            $target = $this->planetServiceFactory->make($planet->id, true);

            $owner = $target->getPlayer();

            if ($owner->isInVacationMode()) {
                continue;
            }
            if ($owner->getUsername(false) === 'Legor') {
                continue;
            }

            return $planet;
        }

        return null;
    }

    /**
     * The coordinates the account already holds fresh intel on, keyed g:s:p.
     *
     * A report is reached through the account's own messages, the host's link
     * from a probe to the report it produced, and only reports inside the intel
     * window count. The window matches the observation service's TTL so scouting
     * and raiding agree on what "recently probed" means.
     *
     * @return array<string, true>
     */
    private function freshIntelCoordinates(int $playerId): array
    {
        $reportIds = Message::query()
            ->where('user_id', $playerId)
            ->whereNotNull('espionage_report_id')
            ->where('created_at', '>=', now()->subHours(self::INTEL_TTL_HOURS))
            ->pluck('espionage_report_id');

        $coordinates = [];
        foreach (EspionageReport::query()->whereIn('id', $reportIds)->get(['planet_galaxy', 'planet_system', 'planet_position']) as $report) {
            $coordinates["{$report->planet_galaxy}:{$report->planet_system}:{$report->planet_position}"] = true;
        }

        return $coordinates;
    }
}
