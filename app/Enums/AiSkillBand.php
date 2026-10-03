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

    /**
     * The chance a session that could act instead does nothing — the "opened the
     * game, did nothing, closed it" moment (FS-018). A novice idles more often
     * than a veteran. ponytail: three unmeasured rates; the review loop's hourly
     * sample is the upgrade path once the no-op rate is observable in play.
     */
    public function idleOverrideProbability(): float
    {
        return match ($this) {
            self::Novice => 0.05,
            self::Standard => 0.02,
            self::Veteran => 0.01,
        };
    }

    /**
     * The share of simulated runs a raiding fleet must survive. A novice only flies when the odds
     * are nearly safe; a veteran accepts a fight a fifth of which it loses, because it has read the
     * simulation rather than feared it.
     */
    public function raidSurvivalFloor(): float
    {
        return match ($this) {
            self::Novice => 0.9,
            self::Standard => 0.8,
            self::Veteran => 0.7,
        };
    }

    /** How many runs the confirming simulation draws: a veteran checks a launch harder than a novice does. */
    public function raidConfirmSamples(): int
    {
        return match ($this) {
            self::Novice => 10,
            self::Standard => 30,
            self::Veteran => 50,
        };
    }
}
