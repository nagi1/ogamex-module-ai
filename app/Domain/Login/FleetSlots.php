<?php

namespace Modules\AI\Domain\Login;

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiWorkItem;
use OGame\Factories\PlayerServiceFactory;
use Throwable;

/**
 * Fleet slots a login can still spend: the host's maximum less the missions flying and the dispatch orders
 * already written but not yet sent. Every manager that sends a fleet draws from this one number.
 */
class FleetSlots
{
    /** The work kinds that occupy a host fleet slot once they run. */
    public const DISPATCHING = [
        AiWorkKind::Raid,
        AiWorkKind::Spy,
        AiWorkKind::Transfer,
        AiWorkKind::Expedition,
        AiWorkKind::Colonize,
        AiWorkKind::Recycle,
        AiWorkKind::FleetSave,
        AiWorkKind::Defend,
    ];

    public function __construct(private PlayerServiceFactory $playerServiceFactory)
    {
    }

    public function free(int $playerId): int
    {
        try {
            $player = $this->playerServiceFactory->make($playerId, true);
            $free = $player->getFleetSlotsMax() - $player->getFleetSlotsInUse();
        } catch (Throwable) {
            return 0;
        }

        $pending = AiWorkItem::query()
            ->where('player_id', $playerId)
            ->whereIn('kind', self::DISPATCHING)
            ->whereIn('state', [AiWorkState::Pending, AiWorkState::Leased, AiWorkState::Retry])
            ->count();

        return $free - $pending;
    }
}
