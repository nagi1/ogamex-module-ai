<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\DrawAiBattleCasesAction;
use Modules\AI\Console\Commands\Concerns\OpensRlUniverse;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\Services\CharacterClassService;

/**
 * Writes random fights answered by the host's own battle flow, the parity cases of the simulator's combat module
 * (plan/rl/world-sim-spec.md). The attacker is the account the combat catalogue is valued for; the defender is the
 * next AI account of the same class, so one catalogue holds both sides' values. Everything drawn is rolled back.
 *
 * DEV TOOLING: it opens only the file it is given and never touches the configured database.
 */
#[Description('Draw random fights answered by the host, for the combat module parity test (plan/rl).')]
#[Signature('ai:rl-battle-cases
    {universe : A SQLite file made by ai:rl-universe}
    {out : The JSON file to write}
    {--n=500 : Number of fights}
    {--max-level=12 : Highest research level drawn (the combat catalogue\'s --max-level)}
    {--seed=1 : Random seed}')]
class GenerateAiRlBattleCases extends Command
{
    use OpensRlUniverse;

    public function handle(): int
    {
        $this->openRlUniverse((string) $this->argument('universe'));

        $factory = app(PlayerServiceFactory::class);
        $classes = app(CharacterClassService::class);
        // A General's Hamill manoeuvre is outside the simulator, so neither side is one.
        $accounts = AiProfile::query()->orderBy('player_id')->pluck('player_id')
            ->map(static fn (int $id) => $factory->make($id, true))
            ->reject(static fn ($player): bool => $classes->isGeneral($player->getUser()))
            ->values();
        $attacker = $accounts->first();
        $defender = $accounts->first(static fn ($player): bool => $player->getId() !== $attacker?->getId() && $player->getUser()->character_class === $attacker?->getUser()->character_class);
        $target = $defender?->planets->first();
        if ($attacker === null || $defender === null || $target === null) {
            $this->error('The universe needs two AI accounts of one class that is not General, the second with a planet.');

            return self::FAILURE;
        }

        DB::beginTransaction();
        $cases = app(DrawAiBattleCasesAction::class)->handle(
            $attacker,
            $target,
            max(1, (int) $this->option('n')),
            max(0, (int) $this->option('max-level')),
            (int) $this->option('seed'),
        );
        DB::rollBack();
        file_put_contents((string) $this->argument('out'), json_encode($cases, JSON_THROW_ON_ERROR));

        $this->info(sprintf('RL-BATTLE-CASES: %s, %d fight(s), attacker %d, defender %d', $this->argument('out'), count($cases['cases']), $attacker->getId(), $defender->getId()));

        return self::SUCCESS;
    }
}
