<?php

namespace Modules\AI\Enums;

/**
 * The archetypes the mined profile names fold onto (per-repo residue audit, RP-004):
 * raider → Fleeter, defensive → Turtle, neutral → Casual; Miner and Trader carry no fold.
 */
enum AiArchetype: int
{
    case Miner = 1;
    case Turtle = 2;
    case Fleeter = 3;
    case Trader = 4;
    case Casual = 5;
}
