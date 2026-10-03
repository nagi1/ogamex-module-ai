<?php

namespace Modules\AI\Domain\Decision;

/**
 * A ship or defence piece this account can legally queue right now, on one of its planets.
 *
 * The unit and the amount are both derived from host properties and host prices: which object fills
 * a role is not a name this module keeps, and how many fit is what the host says the planet can pay
 * for. The reason names the role that selected it, so a trace can show why this unit rather than
 * another without restating the arithmetic.
 *
 * `aheadOfEconomy` marks the one order whose placement decides whether it happens at all: the first
 * wall of a planet that stands bare beside a walled sibling. The session's building steps spend the
 * balance this order was priced against when they run first, so the host refuses the wall and the
 * planet stays naked (QUAL-003); the schedule runs a marked order before those steps instead.
 *
 * `surplusSpend` marks the war fleet's own order: the best hull a planet's yard can build, bought out
 * of what the economy leaves. A login whose decision was about something else still keeps the yard
 * busy, the way a player with a war chest buys ships on whichever page they opened, so the fleet grows
 * without waiting for the sessions the engine happens to pick the shipyard (measured live 3 Oct 2026:
 * a hundred accounts held 8,613 small cargo and eight hulls dearer than the median between them).
 */
readonly class QueueableUnit
{
    public function __construct(
        public int $planetId,
        public int $unitId,
        public int $amount,
        public string $reason,
        public bool $aheadOfEconomy = false,
        public bool $surplusSpend = false,
    ) {
    }
}
