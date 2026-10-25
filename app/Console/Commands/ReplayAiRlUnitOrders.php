<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\ReplayAiUnitOrdersAction;
use Modules\AI\Console\Commands\Concerns\OpensRlUniverse;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use RuntimeException;

/**
 * Parity check of the simulator's shipyard (plan/rl/world-sim-spec.md, units): random ship and defence orders on real
 * host planets, with what the host queued, charged and delivered, for `cargo test` to reproduce. Everything runs in a
 * transaction that is rolled back.
 *
 * DEV TOOLING: it opens only the universe file it is given and leaves it unchanged.
 */
#[Description('Replay random unit orders on host planets for the simulator parity check (plan/rl).')]
#[Signature('ai:rl-units-replay
    {universe : An ai:rl-universe SQLite file}
    {out : JSON file for the host answers}
    {--planets=3 : How many AI home planets to play, in player order}
    {--seed=1 : First seed; each planet uses the next}
    {--orders=40 : Orders per planet}
    {--hours=48 : The orders fall within this many hours}
    {--max-level=12 : Highest random level of a building, station or research}')]
class ReplayAiRlUnitOrders extends Command
{
    use OpensRlUniverse;

    public function handle(): int
    {
        $this->openRlUniverse((string) $this->argument('universe'));
        $playerIds = AiProfile::query()->orderBy('player_id')->limit(max(1, (int) $this->option('planets')))->pluck('player_id')->all();

        $replays = [];
        foreach ($playerIds as $index => $playerId) {
            DB::beginTransaction();
            $planet = app(PlayerServiceFactory::class)->make($playerId, true)->planets->first() ?? throw new RuntimeException(sprintf('Player %d has no planet.', $playerId));
            $replays[] = app(ReplayAiUnitOrdersAction::class)->handle($planet, (int) $this->option('seed') + $index, max(1, (int) $this->option('orders')), max(1, (int) $this->option('hours')) * 3600, max(2, (int) $this->option('max-level')));
            DB::rollBack();
        }

        file_put_contents((string) $this->argument('out'), json_encode(['replays' => $replays], JSON_THROW_ON_ERROR));
        $accepted = array_sum(array_map(static fn (array $replay): int => count(array_filter($replay['orders'], static fn (array $order): bool => $order['accepted'] > 0)), $replays));
        $this->info(sprintf('RL-UNITS-REPLAY: %d planet(s), %d order(s) accepted -> %s', count($replays), $accepted, $this->argument('out')));

        return self::SUCCESS;
    }
}
