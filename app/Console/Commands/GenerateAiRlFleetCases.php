<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\DrawAiFleetCasesAction;
use Modules\AI\Console\Commands\Concerns\OpensRlUniverse;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;

/**
 * Writes random flights with the host's own distance, flight time and fuel, the parity cases of the simulator's
 * fleet module (plan/rl/world-sim-spec.md). The settings and activity it draws are rolled back; the file is unchanged.
 *
 * DEV TOOLING: it opens only the file it is given and never touches the configured database.
 */
#[Description('Draw random flights answered by the host, for the fleet module parity test (plan/rl).')]
#[Signature('ai:rl-fleet-cases
    {universe : A SQLite file made by ai:rl-universe}
    {out : The JSON file to write}
    {--n=10000 : Number of flights}
    {--per-group=500 : Flights per drawn set of universe settings}
    {--player= : The account whose ships fly (default: the first AI account, as ai:rl-combat-catalogue)}
    {--max-level=20 : Highest research level drawn (the combat catalogue\'s --max-level)}
    {--seed=1 : Random seed}')]
class GenerateAiRlFleetCases extends Command
{
    use OpensRlUniverse;

    public function handle(): int
    {
        $this->openRlUniverse((string) $this->argument('universe'));

        $playerId = (int) ($this->option('player') ?? AiProfile::query()->orderBy('player_id')->value('player_id'));
        $player = app(PlayerServiceFactory::class)->make($playerId, true);

        DB::beginTransaction();
        $cases = app(DrawAiFleetCasesAction::class)->handle(
            $player,
            max(1, (int) $this->option('n')),
            max(1, (int) $this->option('per-group')),
            max(0, (int) $this->option('max-level')),
            (int) $this->option('seed'),
        );
        DB::rollBack();
        file_put_contents((string) $this->argument('out'), json_encode($cases, JSON_THROW_ON_ERROR));

        $this->info(sprintf('RL-FLEET-CASES: %s, %d group(s), player %d', $this->argument('out'), count($cases['groups']), $playerId));

        return self::SUCCESS;
    }
}
