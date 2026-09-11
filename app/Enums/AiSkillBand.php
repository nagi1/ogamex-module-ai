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
}
