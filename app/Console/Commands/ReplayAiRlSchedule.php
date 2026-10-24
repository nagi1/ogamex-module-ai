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
 * Parity check of the fast simulator (plan/rl, rl/ogsim): replays a simulated build order on the same host planet
 * and writes both sides next to each other. Everything runs in a transaction that is rolled back.
 *
 * DEV TOOLING: it opens only the universe file it is given and leaves it unchanged.
 */
#[Description('Replay an ogsim build order on the host and report the differences (plan/rl).')]
#[Signature('ai:rl-replay
    {universe : The ai:rl-universe SQLite file the catalogue was exported from}
    {schedule : JSON written by python -m ogrl.econ schedule}
    {out : JSON file for the side-by-side result}')]
class ReplayAiRlSchedule extends Command
{
    use OpensRlUniverse;

    public function handle(): int
    {
        $this->openRlUniverse((string) $this->argument('universe'));
        $schedule = json_decode((string) file_get_contents((string) $this->argument('schedule')), true, 512, JSON_THROW_ON_ERROR);

        DB::beginTransaction();
        $planet = app(PlanetServiceFactory::class)->make((int) $schedule['planet_id'], true);
        $result = app(ReplayAiEconomyScheduleAction::class)->handle($planet, $schedule['steps'], (int) $schedule['horizon_seconds']);
        DB::rollBack();

        file_put_contents((string) $this->argument('out'), json_encode($result + ['sim_final' => $schedule['final']], JSON_THROW_ON_ERROR));
        $this->info(sprintf('RL-REPLAY: planet %d, %d step(s) -> %s', $schedule['planet_id'], count($result['steps']), $this->argument('out')));

        return self::SUCCESS;
    }
}
