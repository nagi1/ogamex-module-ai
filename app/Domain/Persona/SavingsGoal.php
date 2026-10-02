<?php

namespace Modules\AI\Domain\Persona;

use OGame\Models\Resources;

/**
 * What an account is holding its resources for, on purpose.
 *
 * "Can I afford the best available thing? Buy if yes" is a hoarder's rule: it saves only by
 * accident, when the next step happens to be too expensive, which is what leaves a mature account
 * sitting on a pile it never intended (the 2B-metal pathology). A saving player names the goal
 * first and spends what is left over: spendable = available - reserved. The reserve is the goal's
 * own price capped by what the account actually holds, so a goal it cannot reach yet never reserves
 * more than it has, and a goal it can already pay for leaves the whole balance spendable.
 *
 * The goal's own purchase spends the reserve -- the price is the point of holding it -- so `objectId`
 * names the object the reserve was raised for and covers() answers whether a candidate is that
 * object. Nothing here names a game object: the price comes from the host for whatever the account
 * decided it wants.
 */
final readonly class SavingsGoal
{
    public function __construct(
        public Resources $cost,
        public int $objectId,
    ) {
    }

    /** The pile that must survive any other spend: the goal's price, capped by what is held. */
    public function reserved(Resources $available): Resources
    {
        return new Resources(
            min($this->cost->metal->get(), $available->metal->get()),
            min($this->cost->crystal->get(), $available->crystal->get()),
            min($this->cost->deuterium->get(), $available->deuterium->get()),
        );
    }

    /** What the account may still spend while saving: available - reserved, and never negative. */
    public function spendable(Resources $available): Resources
    {
        $reserved = $this->reserved($available);

        return new Resources(
            max(0.0, $available->metal->get() - $reserved->metal->get()),
            max(0.0, $available->crystal->get() - $reserved->crystal->get()),
            max(0.0, $available->deuterium->get() - $reserved->deuterium->get()),
        );
    }

    /** Whether this candidate is the goal itself, whose purchase is what the reserve exists for. */
    public function covers(int $objectId): bool
    {
        return $this->objectId === $objectId;
    }
}
