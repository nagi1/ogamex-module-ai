<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\Rl\DrawAiScoreCasesAction;
use Modules\AI\Console\Commands\Concerns\OpensRlUniverse;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\SimulatedTime;
use OGame\Factories\PlayerServiceFactory;

/**
 * Writes random account states answered by the host's highscore, the parity cases of the simulator's score module
 * (plan/rl/north-star-plan.md, N1): building levels, research, ships, defence, a moon and fleets in flight on the
 * universe's AI accounts, with the four scores the host made of them. Everything drawn is rolled back, which the
 * file's checksum before and after proves.
 *
 * DEV TOOLING: it opens only the file it is given and never touches the configured database.
 */
#[Description('Draw random account states answered by the host highscore, for the score parity test (plan/rl).')]
#[Signature('ai:rl-score-cases
    {universe : A SQLite file made by ai:rl-universe}
    {out : The JSON file to write}
    {--n=600 : Number of accounts drawn}
    {--max-level=12 : Highest level drawn for a building or a research}
    {--seed=1 : Random seed}')]
class RlScoreCasesCommand extends Command
{
    use OpensRlUniverse;

    public function handle(): int
    {
        $universe = (string) $this->argument('universe');
        $checksum = hash_file('sha256', $universe);
        if ($checksum === false) {
            $this->error("Cannot read {$universe}.");

            return self::FAILURE;
        }
        $this->openRlUniverse($universe);
        // A drawn account may hold a lock while it is scored; an in-process store keeps that off any configured cache.
        app('cache')->setDefaultDriver('array');

        $factory = app(PlayerServiceFactory::class);
        $accounts = array_values(AiProfile::query()->orderBy('player_id')->pluck('player_id')
            ->map(static fn (int $id) => $factory->make($id, true))
            ->filter(static fn ($player): bool => $player->planets->allPlanets() !== [])
            ->all());
        if (count($accounts) < 2) {
            $this->error('The universe needs two AI accounts with a planet.');

            return self::FAILURE;
        }

        // The universe's own clock: the latest second any planet was brought up to.
        $start = (int) (DB::table('planets')->max('time_last_update') ?: Date::now()->getTimestamp());
        SimulatedTime::freezeAt(Date::createFromTimestamp($start));
        $cases = app(DrawAiScoreCasesAction::class)->handle($accounts, max(1, (int) $this->option('n')), max(0, (int) $this->option('max-level')), (int) $this->option('seed'), $start);
        DB::disconnect('ai_rl_universe');

        if (hash_file('sha256', $universe) !== $checksum) {
            $this->error('The universe file changed; nothing is written.');

            return self::FAILURE;
        }
        ini_set('serialize_precision', '-1');
        file_put_contents((string) $this->argument('out'), json_encode($cases, JSON_THROW_ON_ERROR));

        $fleets = array_sum(array_map(static fn (array $case): int => count($case['fleets']), $cases['cases']));
        $this->info(sprintf('RL-SCORE-CASES: %s, %d case(s), %d fleet(s) in flight, universe unchanged (sha256 %s)', $this->argument('out'), count($cases['cases']), $fleets, substr($checksum, 0, 12)));

        return self::SUCCESS;
    }
}
