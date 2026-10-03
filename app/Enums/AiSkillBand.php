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

    private function rate(string $key): float
    {
        static $rates = null;
        $rates ??= Yaml::parseFile(dirname(__DIR__, 2) . self::BEHAVIOR_FILE);

        return (float) $rates[strtolower($this->name)][$key];
    }
}
