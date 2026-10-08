<?php

namespace Modules\AI\Domain\Social;

use Modules\AI\Domain\Decision\Enemies;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Symfony\Component\Yaml\Yaml;

/**
 * Whether an account wants an alliance and whether it leads one: persona taste from
 * `resources/behavior/alliance-ambition.yaml`, drawn once per account from its seed. Every account in a club,
 * and every account a locked-out founder, was the ALLY-001 lane; real servers have solo players and few leaders.
 */
class AllianceAmbition
{
    private const FILE = '/resources/behavior/alliance-ambition.yaml';

    /** @var array<string, mixed>|null */
    private static ?array $policy = null;

    public function wantsAlliance(int $playerId): bool
    {
        return $this->draws($playerId, 'joins') || $this->leads($playerId) || $this->besieged($playerId);
    }

    /**
     * A player several others keep hitting looks for a club whatever kind they are: "you theoretically get far less raid
     * from others after joining an alliance" (alliance guide). Without it the solos a dominant club farmed never organised
     * and no second bloc ever stood against it (JEV judge, 9437/9441/9442: 9 of 14 players unallied, 88 raids on them).
     */
    private function besieged(int $playerId): bool
    {
        $enemies = (int) ($this->policy()['siege_enemies'] ?? 0);

        return $enemies > 0 && AiRelationship::query()->where('player_id', $playerId)->where('threat', '>=', Enemies::ENEMY_THREAT)->count() >= $enemies;
    }

    public function leads(int $playerId): bool
    {
        return $this->draws($playerId, 'leads');
    }

    public function betrays(int $playerId): bool
    {
        return $this->draws($playerId, 'betrays');
    }

    public function reapplyAfterHours(): int
    {
        return (int) ($this->policy()['reapply_after_hours'] ?? 24);
    }

    /** The archetype's share of leaders: how readily this kind of player founds a club. */
    public function leadShare(int $playerId): float
    {
        return $this->share(AiProfile::query()->where('player_id', $playerId)->first(['archetype', 'random_seed']), 'leads');
    }

    private function draws(int $playerId, string $trait): bool
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->first(['archetype', 'random_seed']);
        if ($profile === null) {
            return false;
        }

        // An independent draw per trait, so a leader is not simply the account that also most wants to join.
        return (crc32($trait . ':' . $profile->random_seed) % 1000) / 1000 < $this->share($profile, $trait);
    }

    private function share(AiProfile|null $profile, string $trait): float
    {
        return $profile === null ? 0.0 : (float) ($this->policy()['archetypes'][strtolower($profile->archetype->name)][$trait] ?? 0.0);
    }

    /** @return array<string, mixed> */
    private function policy(): array
    {
        if (self::$policy === null) {
            $file = Yaml::parseFile(dirname(__DIR__, 3) . self::FILE);
            self::$policy = is_array($file) ? $file : [];
        }

        return self::$policy;
    }
}
