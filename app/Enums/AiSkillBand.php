<?php

namespace Modules\AI\Enums;

use Symfony\Component\Yaml\Yaml;

enum AiSkillBand: int
{
    case Novice = 1;
    case Standard = 2;
    case Veteran = 3;

    private const BEHAVIOR_FILE = '/resources/behavior/skill-bands.yaml';

    public function variationWeight(): float
    {
        return $this->rate('variation_weight');
    }

    public function selectionMargin(): float
    {
        return $this->rate('selection_margin');
    }

    /**
     * How fully the account acts on its own mood. A novice plays the feeling, a veteran
     * shrugs it off: the same evidence moves a novice further than a veteran, which is the
     * per-profile divergence 6B measures rather than a fixed reaction every account shares.
     */
    public function evidenceReaction(): float
    {
        return $this->rate('evidence_reaction');
    }

    /**
     * The chance a session that could act instead does nothing: the "opened the
     * game, did nothing, closed it" moment (FS-018). A novice idles more often
     * than a veteran.
     */
    public function idleOverrideProbability(): float
    {
        return $this->rate('idle_override_probability');
    }

    /** How much of the real exposure the account sizes its wall to (PERS-009). */
    public function exposureAwareness(): float
    {
        return $this->rate('exposure_awareness');
    }

    /** Extra weight on exposure after the planet was attacked in the last day; only a veteran carries it. */
    public function threatMemory(): float
    {
        return $this->rate('threat_memory');
    }

    private function rate(string $key): float
    {
        static $rates = null;
        $rates ??= Yaml::parseFile(dirname(__DIR__, 2) . self::BEHAVIOR_FILE);

        return (float) $rates[strtolower($this->name)][$key];
    }

    /**
     * The share of simulated runs a raiding fleet must survive. A novice only flies when the odds
     * are nearly safe; a veteran accepts a fight a fifth of which it loses, because it has read the
     * simulation rather than feared it.
     */
    public function raidSurvivalFloor(): float
    {
        return match ($this) {
            self::Novice => 0.8,
            self::Standard => 0.7,
            self::Veteran => 0.6,
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
