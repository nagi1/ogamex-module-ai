<?php

namespace Modules\AI\Enums;

/**
 * What the account believes about static defence: how much it leaves exposed and
 * what wall it builds, as a persisted strategic belief rather than an archetype
 * ratio. Two miners with different doctrines genuinely disagree.
 */
enum AiDefenseDoctrine: int
{
    case Minimalist = 1;
    case ProductionShell = 2;
    case FodderHeavy = 3;
    case RocketPlasma = 4;
    case BalancedMixed = 5;
    case HeavyMixed = 6;
    case Bunker = 7;
    case Adaptive = 8;

    /**
     * The doctrine in `resources/behavior/defence-doctrines.yaml` this belief builds to.
     *
     * Several beliefs share a shape (two miners can both want a mixed wall, two turtles a big-gun
     * one), which is why the enum has more cases than the file has sourced doctrines.
     */
    public function doctrineKey(): string
    {
        return match ($this) {
            self::Minimalist, self::BalancedMixed => 'balanced',
            self::ProductionShell => 'early_game',
            self::FodderHeavy => 'fodder_heavy',
            self::RocketPlasma, self::HeavyMixed => 'big_gun_heavy',
            self::Bunker => 'late_game_turtle',
            self::Adaptive => 'endgame_against_rips',
        };
    }
}
