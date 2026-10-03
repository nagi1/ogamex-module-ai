<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\User;
use OGame\Services\MerchantService;
use OGame\Services\PlanetService;

/**
 * Whether this account should call the resource merchant (LOOP-002).
 *
 * A player pays the merchant's dark matter only when one store is about to overflow while another
 * runs thin on the same planet: the overflow would be wasted anyway, the thin resource is what
 * the next build waits on. The merchant is paid in dark matter the account already holds (expedition
 * finds, regeneration), never bought. A third of the overflowing stock is offered, so the planet
 * keeps most of what it produced.
 */
class QueueableTradePlanner
{
    private const OVERFLOW_RATIO = 0.8;

    private const THIN_RATIO = 0.25;

    private const OFFER_SHARE = 0.33;

    /** A trade smaller than this does not repay the dark matter. */
    private const MINIMUM_GIVE = 100_000;

    private const RESOURCES = ['metal', 'crystal', 'deuterium'];

    public function __construct(private PlayerServiceFactory $playerServiceFactory)
    {
    }

    public function plan(int $playerId): ?QueueableTrade
    {
        if (!AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->exists()) {
            return null;
        }
        $user = User::query()->find($playerId);
        if ($user === null || $user->dark_matter < MerchantService::DARK_MATTER_COST) {
            return null;
        }

        $player = $this->playerServiceFactory->make($playerId, true);
        foreach ($player->planets->all() as $planet) {
            $planet->updateResources(false);
            $planet->updateResourceStorageStats(false);

            $trade = $this->tradeFor($planet);
            if ($trade !== null) {
                return $trade;
            }
        }

        return null;
    }

    private function tradeFor(PlanetService $planet): ?QueueableTrade
    {
        $give = null;
        $receive = null;
        foreach (self::RESOURCES as $resource) {
            $stock = $planet->{$resource}()->get();
            $capacity = $planet->{$resource . 'Storage'}()->get();
            if ($capacity <= 0) {
                continue;
            }

            $ratio = $stock / $capacity;
            if ($ratio >= self::OVERFLOW_RATIO && ($give === null || $ratio > $give['ratio'])) {
                $give = ['resource' => $resource, 'ratio' => $ratio, 'stock' => $stock];
            }
            if ($ratio <= self::THIN_RATIO && ($receive === null || $ratio < $receive['ratio'])) {
                $receive = ['resource' => $resource, 'ratio' => $ratio];
            }
        }

        if ($give === null || $receive === null) {
            return null;
        }

        $amount = (int) floor($give['stock'] * self::OFFER_SHARE);

        return $amount < self::MINIMUM_GIVE ? null : new QueueableTrade($planet->getPlanetId(), $give['resource'], $receive['resource'], $amount);
    }
}
