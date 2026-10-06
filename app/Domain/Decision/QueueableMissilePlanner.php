<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\EspionageReport;
use OGame\Models\Message;
use OGame\Models\User;
use OGame\Services\PlayerService;

/**
 * Interplanetary missiles are how a player thins a wall before the fleet arrives, so a planet that holds
 * missiles and has a fresh report on a defended target inside the missile range fires them. The range,
 * the missile count and the target's standing defence are the host's; the planner only asks. A volley the
 * dispatch gate refused a moment ago is waited out, the way every other dispatch lane waits: the refusal
 * is not a mission, so this read is the only thing that remembers it.
 */
class QueueableMissilePlanner
{
    /** The host's machine name for the missile object, the one catalogue name the host's own mission also reads. */
    private const MISSILE = 'interplanetary_missile';

    private const REPORT_FRESH_HOURS = 12;

    /** A wall thinner than this is not worth a missile each. */
    private const MIN_DEFENCE_UNITS = 20;

    public function __construct(private PlayerServiceFactory $playerServiceFactory)
    {
    }

    public function plan(int $playerId): ?QueueableMissile
    {
        if (!AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->exists()) {
            return null;
        }

        if (!User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $player = $this->playerServiceFactory->make($playerId, true);
        $range = $player->getMissileRange();
        if ($range < 1) {
            return null;
        }

        // A refused volley is not a mission, so nothing else remembers it: the body the gate just
        // refused to fire from, and the target it blamed, are not offered again inside the cooling.
        // Without this the same planet fired at the same wall on every login and the host refused the
        // same dispatch again (the DISPATCH_REFUSALS repeat).
        $refusals = app(RecentRefusals::class);
        $refusedOrigins = $refusals->refusedOrigins($playerId, AiWorkKind::Missile);
        $refusedTargets = $refusals->refusedTargets($playerId);

        $reportIds = Message::query()
            ->where('user_id', $playerId)
            ->whereNotNull('espionage_report_id')
            ->where('created_at', '>=', now()->subHours(self::REPORT_FRESH_HOURS))
            ->pluck('espionage_report_id');
        if ($reportIds->isEmpty()) {
            return null;
        }

        $reports = EspionageReport::query()->whereIn('id', $reportIds)->orderByDesc('id')->get();

        foreach ($player->planets->all() as $planet) {
            if (isset($refusedOrigins[$planet->getPlanetId()])) {
                continue;
            }

            $missiles = $planet->getObjectAmount(self::MISSILE);
            if ($missiles < 1) {
                continue;
            }

            $origin = $planet->getPlanetCoordinates();
            $best = null;
            $bestWall = 0;

            foreach ($reports as $report) {
                if (isset($refusedTargets["{$report->planet_galaxy}:{$report->planet_system}:{$report->planet_position}"])) {
                    continue;
                }

                if ((int) $report->planet_galaxy !== $origin->galaxy
                    || abs((int) $report->planet_system - $origin->system) > $range
                    || (int) $report->planet_user_id === $playerId) {
                    continue;
                }

                $wall = (int) array_sum($report->defense ?? []);
                // The host's own interceptor is not a wall: the missiles of the target's silo answer 1:1 elsewhere.
                if ($wall < self::MIN_DEFENCE_UNITS || $wall <= $bestWall || $this->shielded($player, (int) $report->planet_user_id)) {
                    continue;
                }

                $best = $report;
                $bestWall = $wall;
            }

            if ($best === null) {
                continue;
            }

            return app()->makeWith(QueueableMissile::class, [
                'originPlanetId' => $planet->getPlanetId(),
                'targetGalaxy' => (int) $best->planet_galaxy,
                'targetSystem' => (int) $best->planet_system,
                'targetPosition' => (int) $best->planet_position,
                'targetType' => (int) $best->planet_type,
                'missiles' => $missiles,
            ]);
        }

        return null;
    }

    private function shielded(PlayerService $player, int $targetUserId): bool
    {
        if ($targetUserId <= 0 || !User::query()->whereKey($targetUserId)->exists()) {
            return true;
        }

        return $this->playerServiceFactory->make($targetUserId, true)->isNewbie($player);
    }
}
