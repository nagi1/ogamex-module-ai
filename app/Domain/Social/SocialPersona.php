<?php

namespace Modules\AI\Domain\Social;

use Symfony\Component\Yaml\Yaml;

/**
 * Who an account is socially: 12 traits in 0..1 drawn near an archetype of `resources/behavior/social-persona.yaml`,
 * drifting with experience. The draw is the simulator's (rl/ogsim src/persona.rs `draw`), bit for bit, so one seed and
 * account give the same person in both.
 */
class SocialPersona
{
    private const FILE = '/resources/behavior/social-persona.yaml';

    public const TRAITS = ['ambition', 'honesty', 'loyalty', 'cruelty', 'cunning', 'courage', 'generosity',
        'dominance', 'freedom', 'authority', 'forgiveness', 'vindictiveness'];

    /** @var array<string, mixed>|null */
    private static ?array $file = null;

    /**
     * One account's archetype and traits, on a stream of its own (seed, account).
     *
     * @return array{archetype: string, traits: array<string, float>}
     */
    public function draw(int $seed, int $account): array
    {
        $archetypes = (array) ($this->file()['archetypes'] ?? []);
        $x = $seed ^ self::wrappingMul($account, -0x61C8864680B583EB) ^ 0x5EED9E750A117A11;
        $unit = static function () use (&$x): float {
            $x ^= $x << 13;
            $x ^= ($x >> 7) & (PHP_INT_MAX >> 6);
            $x ^= $x << 17;

            return (($x >> 11) & (PHP_INT_MAX >> 10)) / 9_007_199_254_740_992.0;
        };

        $pick = $unit() * array_sum(array_map(static fn (array $a): float => (float) $a['share'], $archetypes));
        $name = (string) array_key_last($archetypes);
        foreach ($archetypes as $candidate => $archetype) {
            $pick -= (float) $archetype['share'];
            if ($pick < 0.0) {
                $name = (string) $candidate;
                break;
            }
        }

        $spread = (float) ($this->file()['spread'] ?? 0.0);
        $traits = [];
        foreach ((array) $archetypes[$name]['traits'] as $i => $value) {
            // Box-Muller gaussian noise, as the simulator draws it.
            [$u1, $u2] = [max(1e-12, $unit()), $unit()];
            $z = sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
            $traits[self::TRAITS[$i]] = min(1.0, max(0.0, (float) $value + $z * $spread));
        }

        return ['archetype' => $name, 'traits' => $traits];
    }

    /**
     * Experience moves the traits (`drift` in the file).
     *
     * @param array<string, float> $traits
     * @return array<string, float>
     */
    public function experience(array $traits, string $event): array
    {
        foreach ((array) ($this->file()['drift'][$event] ?? []) as $trait => $delta) {
            $traits[$trait] = min(1.0, max(0.0, (float) ($traits[$trait] ?? 0.5) + (float) $delta));
        }

        return $traits;
    }

    /** u64 wrapping multiply of a non-negative account id below 2^31 by any 64-bit word, in PHP's signed ints. */
    private static function wrappingMul(int $account, int $word): int
    {
        $low = $account * ($word & 0xFFFFFFFF);
        $high = (($account * (($word >> 32) & 0xFFFFFFFF)) & 0xFFFFFFFF) << 32;
        $sumLow = ($low & 0xFFFFFFFF) + ($high & 0xFFFFFFFF);
        $sumHigh = (($low >> 32) & 0xFFFFFFFF) + (($high >> 32) & 0xFFFFFFFF) + ($sumLow >> 32);

        return (($sumHigh & 0xFFFFFFFF) << 32) | ($sumLow & 0xFFFFFFFF);
    }

    /** @return array<string, mixed> */
    private function file(): array
    {
        if (self::$file === null) {
            $file = Yaml::parseFile(dirname(__DIR__, 3) . self::FILE);
            self::$file = is_array($file) ? $file : [];
        }

        return self::$file;
    }
}
