<?php

namespace Modules\AI\Domain\Persona;

use Modules\AI\Enums\AiActivityBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\RandomSource;
use Symfony\Component\Yaml\Yaml;

/**
 * How well an account plays (behavior/skill.yaml, the simulator's arm `skill`): one quantile q in 0..1, drawn once
 * from the account's own seed and kept on its profile. It acts only through what a player's skill acts through, how
 * often the account plays and how many of its expedition slots it keeps busy; no game rule changes. Off by default
 * (`ai.population.skill`), and while off every account plays exactly as before.
 */
class SkillQuantile
{
    private const BEHAVIOR_FILE = '/resources/behavior/skill.yaml';

    public function __construct(private RandomSource $random, private AiPersonaFactory $personas)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('ai.population.skill', false);
    }

    /** The account's quantile, drawn and stored on first use; null while the spread is off. */
    public function of(AiProfile $profile): float|null
    {
        if (!$this->enabled()) {
            return null;
        }

        if ($profile->skill_quantile === null) {
            $profile->skill_quantile = $this->random->unitInterval((int) $profile->random_seed, 'persona:skill');
            if ($profile->exists) {
                $profile->saveQuietly();
            }
        }

        return (float) $profile->skill_quantile;
    }

    /**
     * The account's activity band and its diligence inside it. With the spread on, q walks its archetype's activity
     * mix by the cumulative shares (the band shares stay the persona factory's; the strong are the active) and the
     * diligence is where q sits inside the band's share. With it off, the stored band and the seeded diligence.
     *
     * @return array{0: AiActivityBand|null, 1: float}
     */
    public function attendance(AiProfile $profile): array
    {
        $q = $this->of($profile);
        if ($q === null) {
            return [$profile->activity_band, PersonaTaste::fromSeed((int) $profile->random_seed, $this->random)->diligence];
        }

        $mix = $this->personas->activityDistribution($profile->archetype);
        $pick = $q * array_sum($mix);
        foreach ($mix as $band => $weight) {
            if ($pick < $weight) {
                return [AiActivityBand::from($band), max(0.0, min(1.0, $pick / $weight))];
            }
            $pick -= $weight;
        }

        return [AiActivityBand::from((int) array_key_last($mix)), 1.0];
    }

    /** The activity band the account actually plays. */
    public function activityBand(AiProfile $profile): AiActivityBand|null
    {
        return $this->attendance($profile)[0];
    }

    /** How many of the host's expedition slots the account keeps busy: all of them while the spread is off. */
    public function expeditionSlots(AiProfile $profile, int $hostSlots): int
    {
        $q = $this->of($profile);
        if ($q === null || $hostSlots <= 0) {
            return $hostSlots;
        }

        $rates = $this->rates();
        $use = $rates['expedition_use_floor'] + (1.0 - $rates['expedition_use_floor']) * max(0.0, min(1.0, $q)) ** $rates['expedition_use_power'];
        if ($use >= 1.0) {
            return $hostSlots;
        }

        return max(1, min($hostSlots, (int) ceil($hostSlots * $use)));
    }

    /** @return array{expedition_use_floor: float, expedition_use_power: float} */
    private function rates(): array
    {
        static $rates = null;
        $rates ??= Yaml::parseFile(dirname(__DIR__, 3) . self::BEHAVIOR_FILE);

        return [
            'expedition_use_floor' => (float) $rates['expedition_use_floor'],
            'expedition_use_power' => (float) $rates['expedition_use_power'],
        ];
    }
}
