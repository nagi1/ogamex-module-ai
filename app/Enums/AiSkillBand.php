<?php

namespace Modules\AI\Enums;

enum AiSkillBand: int
{
    case Novice = 1;
    case Standard = 2;
    case Veteran = 3;

    public function variationWeight(): float
    {
        return match ($this) {
            self::Novice => 8.0,
            self::Standard => 4.0,
            self::Veteran => 1.0,
        };
    }

    public function selectionMargin(): float
    {
        return match ($this) {
            self::Novice => 10.0,
            self::Standard => 2.5,
            self::Veteran => 1.0,
        };
    }

    /**
     * How fully the account acts on its own mood. A novice plays the feeling, a veteran
     * shrugs it off — the same evidence moves a novice further than a veteran, which is the
     * per-profile divergence 6B measures rather than a fixed reaction every account shares.
     */
    public function evidenceReaction(): float
    {
        return match ($this) {
            self::Novice => 1.0,
            self::Standard => 0.5,
            self::Veteran => 0.2,
        };
    }
}
