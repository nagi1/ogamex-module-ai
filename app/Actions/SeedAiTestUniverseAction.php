<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;
use OGame\Actions\Fortify\CreateNewUser;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\User;
use RuntimeException;

/**
 * Creates the AI accounts a test or pilot universe needs, through the real registration path.
 *
 * Accounts are made by the host's own registration action rather than by inserts, so a seeded
 * account is an ordinary account: it gets its planets, its tech and its welcome message the same
 * way a human registration does. Each one is given an enabled profile and one session work item,
 * because the module has no other way to start an account — the first session is what schedules
 * everything after it.
 *
 * Identity is per-account rather than per-cohort: a plausible address, an uncorrelated seed, a
 * join date that is not the same second as its neighbours, a dark-matter balance inside a small
 * band, and a renamed homeworld. The host already invents a unique username; this path stamps
 * that rename so the account does not look like one that has never touched its name.
 *
 * Seeding is idempotent by the deterministic address, and it refuses to be the first account in a
 * universe: the host promotes the very first user to admin, and an operator who seeds an empty
 * universe would end up with an AI administrator.
 */
class SeedAiTestUniverseAction
{
    /**
     * Deliverable-looking domains. `.invalid` is reserved and announces the account the moment
     * anyone sees the address; these do not.
     *
     * @var list<string>
     */
    private const EMAIL_DOMAINS = [
        'orbitmail.net',
        'stellarbox.com',
        'voidpost.org',
        'galaxypost.net',
        'cometmail.com',
    ];

    /**
     * Homeworld names a human might pick. Kept short for the host's 2–20 character planet rule.
     *
     * @var list<string>
     */
    private const PLANET_NAMES = [
        'New Terra',
        'Outpost',
        'Haven',
        'Frontier',
        'Aurora',
        'Base Camp',
        'Sunrise',
        'Anchor',
        'Redoubt',
        'Waystation',
    ];

    /** Starting dark matter the host gives every registration, measured on the pilot cohort. */
    private const DARK_MATTER_BASE = 8000;

    /** Half-width of the per-account dark-matter band around the host default. */
    private const DARK_MATTER_SPREAD = 1200;

    /** Newest account can appear up to this many days ago, so a cohort does not share one minute. */
    private const JOIN_STAGGER_DAYS = 21;

    public function __construct(
        private readonly AiClock $clock,
        private readonly PlayerServiceFactory $playerServiceFactory,
    ) {
    }

    /**
     * @return list<array{player_id: int, archetype: string, created: bool}>
     */
    public function handle(int $players): array
    {
        if (User::query()->count() === 0) {
            throw new RuntimeException('This universe has no accounts yet. Register the first human account before seeding, so the seeded ones are not the administrator.');
        }

        $now = $this->clock->now();
        $seeded = [];

        for ($index = 1; $index <= $players; $index++) {
            $seeded[] = $this->seed($index, $now);
        }

        return $seeded;
    }

    /**
     * @return array{player_id: int, archetype: string, created: bool}
     */
    private function seed(int $index, CarbonImmutable $now): array
    {
        $email = $this->email($index);
        $existing = User::query()->where('email', $email)->first();
        $created = $existing === null;
        $user = $existing ?? app(CreateNewUser::class)->create([
            'email' => $email,
            'password' => Str::password(32),
        ]);
        $archetype = $this->archetype($index);
        $seed = $this->randomSeed($index);

        AiProfile::query()->firstOrCreate(
            ['player_id' => $user->id],
            [
                'archetype' => $archetype,
                'skill_band' => $this->skillBand($index),
                'random_seed' => $seed,
                'enabled' => true,
                'settings' => ['pilot' => true, 'pilot_index' => $index],
            ],
        );

        if ($created) {
            $this->personalise($user, $index, $seed, $now, $archetype);
        }

        // Generation one is the first session; the session action schedules every later one.
        AiWorkItem::query()->firstOrCreate(
            ['idempotency_key' => 'session:' . $user->id . ':1'],
            [
                'player_id' => $user->id,
                'kind' => AiWorkKind::RunSession,
                'due_at' => $now,
                'state' => AiWorkState::Pending,
            ],
        );

        return ['player_id' => $user->id, 'archetype' => $archetype->name, 'created' => $created];
    }

    /**
     * The identity tells a cohort shares when provisioning is uniform: same dark matter, same
     * join minute, a Homeworld that was never renamed, and a username that has never been touched.
     */
    private function personalise(User $user, int $index, int $seed, CarbonImmutable $now, AiArchetype $archetype): void
    {
        // Index participates so two nearby crc32 values cannot collapse a small cohort onto one
        // day or one dark-matter balance.
        $joinedAt = $now->subDays(($seed % self::JOIN_STAGGER_DAYS) + $index)
            ->subHours(($seed + $index) % 20)
            ->subMinutes((($seed >> 3) + ($index * 7)) % 50);

        // Force-fill: dark_matter and created_at are not on the host's registration fillable list,
        // and writing them through the model is the same shape an admin correction would take.
        $user->forceFill([
            'dark_matter' => $this->darkMatter($seed, $index),
            'created_at' => $joinedAt,
            'updated_at' => $joinedAt,
            // Stamp the rename the host's registration never records: without it every AI username
            // looks untouched forever, which is the galaxy-view tell.
            'username_updated_at' => $joinedAt,
            // A human picks a class at registration; the seeded account picks the one its persona
            // would and has used its free selection, so no AI account sits classless forever.
            'character_class' => $this->characterClass($archetype)->value,
            'character_class_free_used' => true,
            'character_class_changed_at' => $joinedAt,
        ])->save();

        $player = $this->playerServiceFactory->make($user->id, true);

        foreach ($player->planets->all() as $planet) {
            if ($planet->getPlanetName() !== 'Homeworld') {
                continue;
            }

            $name = self::PLANET_NAMES[($seed + $index) % count(self::PLANET_NAMES)];
            if ($planet->isValidPlanetName($name)) {
                $planet->setPlanetName($name);
            }
        }
    }

    private function email(int $index): string
    {
        // Deterministic and unique per index, but not a sequential local-part an operator can scan
        // for. The domain rotates so a cohort does not share one mailbox provider either.
        $local = 'u' . substr(hash('sha256', 'ai-pilot:' . $index), 0, 10);
        $domain = self::EMAIL_DOMAINS[($index - 1) % count(self::EMAIL_DOMAINS)];

        return $local . '@' . $domain;
    }

    private function randomSeed(int $index): int
    {
        // Uncorrelated with the index: two neighbouring accounts must not share nearby seeds.
        return (int) sprintf('%u', crc32('ai-persona:' . $index . ':v1'));
    }

    private function darkMatter(int $seed, int $index): int
    {
        $offset = (($seed + ($index * 97)) % (self::DARK_MATTER_SPREAD * 2 + 1)) - self::DARK_MATTER_SPREAD;

        return self::DARK_MATTER_BASE + $offset;
    }

    /**
     * The character class a persona would pick: mines and economy for the miner and the casual,
     * combat for the fleeter and the turtle, expeditions for the trader. The class itself is the
     * host's; only the persona-to-class taste lives here.
     */
    private function characterClass(AiArchetype $archetype): CharacterClass
    {
        return match ($archetype) {
            AiArchetype::Miner, AiArchetype::Casual => CharacterClass::COLLECTOR,
            AiArchetype::Turtle, AiArchetype::Fleeter => CharacterClass::GENERAL,
            AiArchetype::Trader => CharacterClass::DISCOVERER,
        };
    }

    /**
     * A seeded population is varied on purpose: one archetype per account, in a fixed order, is
     * what makes a pilot comparable between runs.
     */
    private function archetype(int $index): AiArchetype
    {
        $cases = AiArchetype::cases();

        return $cases[($index - 1) % count($cases)];
    }

    private function skillBand(int $index): AiSkillBand
    {
        $cases = AiSkillBand::cases();

        return $cases[($index - 1) % count($cases)];
    }
}
