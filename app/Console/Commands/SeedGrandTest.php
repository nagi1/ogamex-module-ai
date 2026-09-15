<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\SeedAiTestUniverseAction;
use OGame\Models\ChatMessage;
use OGame\Models\User;

/**
 * Seeds the 10-account grand-test universe.
 *
 * Every account starts exactly where a normal player starts: zero buildings, zero research,
 * default resources, one Homeworld — the same registration path a human takes. Nothing is gifted;
 * every building the account ever shows is one it queued through its own planner on its own
 * schedule. The only thing this command adds beyond registration is a couple of inbound greetings.
 */
#[Description('Seed the grand-test universe of fresh accounts. Refuses production.')]
#[Signature('ai:seed-grand-test
            {--players=10 : Number of AI accounts}
            {--confirm : Required confirmation}')]
class SeedGrandTest extends Command
{
    public function handle(): int
    {
        if ($this->getLaravel()->environment('production')) {
            $this->error('Refusing to seed synthetic accounts in production.');

            return self::FAILURE;
        }

        if (!$this->option('confirm')) {
            $this->error('This creates AI accounts. Re-run with --confirm.');

            return self::FAILURE;
        }

        $players = max(1, (int) $this->option('players'));

        $seeded = app(SeedAiTestUniverseAction::class)->handle($players);
        $aiIds = array_map(static fn (array $account): int => $account['player_id'], $seeded);

        $rows = [];

        foreach ($seeded as $index => $account) {
            $rows[] = [$index + 1, $account['archetype'], $account['player_id'], $account['created'] ? 'created' : 'already seeded'];
        }

        $this->seedSocialMessages($seeded, $aiIds);

        $this->newLine();
        $this->table(['#', 'Archetype', 'Player', 'Status'], $rows);

        return self::SUCCESS;
    }

    /**
     * A human neighbour greets two accounts. This is an external event a fresh player can receive
     * on day one, not a head start: the account still owns nothing and must answer from its own
     * authored replies. The conversation path is what this exercises.
     *
     * @param list<array{player_id:int, archetype:string, created:bool}> $seeded
     * @param list<int> $aiIds
     */
    private function seedSocialMessages(array $seeded, array $aiIds): void
    {
        $human = User::query()->whereNotIn('id', $aiIds)->first();
        if ($human === null) {
            return;
        }

        foreach (array_slice($seeded, 0, 2) as $account) {
            ChatMessage::query()->create([
                'sender_id' => $human->id,
                'recipient_id' => $account['player_id'],
                'message' => 'Hello neighbour — good luck out there.',
            ]);
        }
    }
}
