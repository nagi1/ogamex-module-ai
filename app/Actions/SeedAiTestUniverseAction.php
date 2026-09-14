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
 * Seeding is idempotent by account identity, and it refuses to be the first account in a
 * universe: the host promotes the very first user to admin, and an operator who seeds an empty
 * universe would end up with an AI administrator.
 */
class SeedAiTestUniverseAction
{
    private const NAME_PREFIX = 'AI Pilot';

    private const EMAIL_DOMAIN = 'ai-pilot.invalid';

    /** Keeps a seeded persona reproducible from its index alone. */
    private const SEED_BASE = 10_000;

    public function __construct(private readonly AiClock $clock)
    {
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
        $user = $existing ?? app(CreateNewUser::class)->create([
            'email' => $email,
            'password' => Str::password(32),
        ]);
        $archetype = $this->archetype($index);

        AiProfile::query()->firstOrCreate(
            ['player_id' => $user->id],
            [
                'archetype' => $archetype,
                'skill_band' => $this->skillBand($index),
                'random_seed' => self::SEED_BASE + $index,
                'enabled' => true,
            ],
        );

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

        return ['player_id' => $user->id, 'archetype' => $archetype->name, 'created' => $existing === null];
    }

    private function email(int $index): string
    {
        return 'ai-pilot-' . $index . '@' . self::EMAIL_DOMAIN;
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
