<?php

namespace Modules\AI\Tests\Support;

use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Domain\Perception\PlayerPerceptionBuilder;

/**
 * Delivers a published observation fixture through the replaceable perception
 * seam. It is deliberately the sole test double: policy, scoring, session,
 * persistence, queue and lock behavior remain production implementations.
 */
final class FixturePlayerPerceptionBuilder extends PlayerPerceptionBuilder
{
    public function __construct(private readonly PerceptionSnapshot $snapshot)
    {
    }

    public function build(int $playerId, ?int $upcomingAbsenceMinutes = null): PerceptionSnapshot
    {
        return $this->snapshot;
    }
}
