<?php

namespace Modules\AI\Domain\Persona;

use Modules\AI\Support\RandomSource;

/**
 * The continuous seeded dimensions that make one account a different player from the next.
 *
 * The archetype stays the label — a Fleeter still fleetsaves, a Miner still never raids — but these
 * scores shift *how much*, so two accounts of one archetype are two people rather than two copies.
 * Each score is a deterministic draw from the account's own seed, the same source as every other
 * per-account draw, so a replay reproduces the whole character.
 *
 * `diligence` is how often the account opens the game, `aggression` how much fleet risk it
 * tolerates, `sociability` how much it keeps a conversation going, each on 0..1.
 */
readonly class PersonaTaste
{
    public function __construct(
        public float $diligence,
        public float $aggression,
        public float $sociability,
    ) {
    }

    public static function fromSeed(int $seed, RandomSource $random): self
    {
        return app()->makeWith(self::class, [
            'diligence' => $random->unitInterval($seed, 'taste:diligence'),
            'aggression' => $random->unitInterval($seed, 'taste:aggression'),
            'sociability' => $random->unitInterval($seed, 'taste:sociability'),
        ]);
    }
}
