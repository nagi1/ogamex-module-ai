<?php

namespace Modules\AI\Enums;

/**
 * The archetypes the mined profile names fold onto (per-repo residue audit, RP-004):
 * raider → Raider, defensive → Turtle, neutral → Casual; Miner and Trader carry no fold. Trader and
 * Casual are kept for stored profiles (never renumbered); new accounts grow as one of the other five.
 */
enum AiArchetype: int
{
    case Miner = 1;
    case Turtle = 2;
    case Fleeter = 3;
    case Trader = 4;
    case Casual = 5;
    case Raider = 6;
    case Hybrid = 7;

    /** Trader and Casual are migration-only: a stored profile grows like the style its behaviour moved into (economic role, activity band). */
    public function growthStyle(): self
    {
        return match ($this) {
            self::Trader => self::Miner,
            self::Casual => self::Hybrid,
            default => $this,
        };
    }
}
