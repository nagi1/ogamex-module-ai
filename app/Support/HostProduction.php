<?php

namespace Modules\AI\Support;

use Illuminate\Support\Facades\Date;
use OGame\Models\Resources;
use OGame\Services\PlanetService;

/**
 * The host's own production figure for one object at one level, at 100% energy, asked once per planet and second.
 *
 * Late-game sessions asked the host the same question about 95,000 times for twelve accounts: every planner prices
 * the next level of every producing object on every planet, and each answer reruns the host's whole production
 * calculation (STRONG-PLAYER-PLAN, 4 Nov: 35% of a late session). The answer only changes when the planet's levels,
 * the account's research, class or officers, or the object's percentage change; levels and research change when a
 * queue completes, which the host processes before a session plans, and the percentage is part of the key. The memo
 * is also dropped at the start of every login, and the clock's second is part of the key.
 *
 * The figure returned is shared: read it, never change it.
 */
final class HostProduction
{
    private const LIMIT = 50_000;

    /** @var array<string, Resources> */
    private static array $memo = [];

    public static function at(PlanetService $planet, string $machineName, int $level): Resources
    {
        $key = $planet->getPlanetId() . ':' . $machineName . ':' . $level . ':' . $planet->getBuildingPercent($machineName) . ':' . Date::now()->getTimestamp();
        if (count(self::$memo) >= self::LIMIT) {
            self::$memo = [];
        }

        return self::$memo[$key] ??= $planet->getObjectProduction($machineName, $level, true);
    }

    public static function forget(): void
    {
        self::$memo = [];
    }
}
