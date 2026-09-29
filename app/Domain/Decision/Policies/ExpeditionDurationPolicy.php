<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Decision\Policies;

/**
 * WHY: the source states expedition gains do not grow with the held duration, only the return
 * delay does (Holding Time ÷ Universe Flight Speed), so a coordinate 16 dispatch is always held
 * for the recommended hour and the relevant technology level never enters the calculation.
 */
final class ExpeditionDurationPolicy
{
    public const COORDINATE = 16;

    public const DURATION_SECONDS = 3600;

    public function targetCoordinate(): int
    {
        return self::COORDINATE;
    }

    /**
     * WHY: the technology level is accepted so callers can pass whatever they have to hand, but
     * it is deliberately unused — deriving the duration from technology is what the source forbids.
     */
    public function durationSeconds(int $technologyLevel): int
    {
        return self::DURATION_SECONDS;
    }

    public function returnDelaySeconds(int $holdingTimeSeconds, int $universeFlightSpeed): int
    {
        return intdiv($holdingTimeSeconds, $universeFlightSpeed);
    }
}
