<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\ReplayAiEconomyScheduleAction;
use Modules\AI\Console\Commands\Concerns\OpensRlUniverse;
use OGame\Factories\PlanetServiceFactory;

/**
 * W14 (plan/rl/WORLD-SIM-PLAN.md): plays a whole simulated world's trace (`cargo run --example trace`) on the same host
 * universe, planet by planet through the host's own queues and clock, and writes both sides next to each other. Every
 * planet runs in a transaction that is rolled back.
 *
 * DEV TOOLING: it opens only the universe file it is given and leaves it unchanged.
 */
#[Description('Replay an ogsim world trace on the host and report the differences (plan/rl W14).')]
#[Signature('ai:rl-world-replay
    {universe : The ai:rl-universe SQLite file the catalogue was exported from}
    {trace : JSON written by the ogsim trace example}
    {out : JSON file for the side-by-side result}')]
class ReplayAiRlWorldTrace extends Command
{
    use OpensRlUniverse;

    private const ORDER = ['touch' => 0, 'units' => 1];

    public function handle(): int
    {
        $this->openRlUniverse((string) $this->argument('universe'));
        $trace = json_decode((string) file_get_contents((string) $this->argument('trace')), true, 512, JSON_THROW_ON_ERROR);

        $planets = [];
        foreach ($trace['planets'] as $simulated) {
            // At one second a login loads the page first, then places its yard orders, then its build starts.
            $steps = $simulated['steps'];
            usort($steps, static fn (array $a, array $b): int => [(int) $a['t'], self::ORDER[$a['queue']] ?? 2] <=> [(int) $b['t'], self::ORDER[$b['queue']] ?? 2]);
            $steps = array_map(static fn (array $step): array => $step + ['start' => $step['t']], $steps);

            DB::beginTransaction();
            $planet = app(PlanetServiceFactory::class)->make((int) $simulated['planet_id'], true);
            $result = app(ReplayAiEconomyScheduleAction::class)->handle($planet, $steps, (int) $simulated['final']['t'], yardBlockedBy: $trace['yard_blocked_by'] ?? []);
            DB::rollBack();

            $planets[] = ['planet_id' => $simulated['planet_id'], 'steps' => $result['steps'], 'host_final' => $result['final'], 'sim_final' => $simulated['final']];
        }

        file_put_contents((string) $this->argument('out'), json_encode(['planets' => $planets], JSON_THROW_ON_ERROR));
        $this->info(sprintf('RL-WORLD-REPLAY: %d planet(s) -> %s', count($planets), $this->argument('out')));

        return self::SUCCESS;
    }
}
