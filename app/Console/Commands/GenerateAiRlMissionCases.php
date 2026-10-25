<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\DrawAiMissionCasesAction;
use Modules\AI\Console\Commands\Concerns\OpensRlUniverse;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\SimulatedTime;
use OGame\Factories\PlayerServiceFactory;

/**
 * Writes random missions answered by the host, the parity cases of the simulator's world module
 * (plan/rl/world-sim-spec.md): each is dispatched, arrives and returns through the host's own calls on the universe's AI
 * accounts. Everything drawn is rolled back, which the file's checksum before and after proves.
 *
 * DEV TOOLING: it opens only the file it is given and never touches the configured database.
 */
#[Description('Draw random missions answered by the host, for the world module parity test (plan/rl).')]
#[Signature('ai:rl-mission-cases
    {universe : A SQLite file made by ai:rl-universe}
    {out : The JSON file to write}
    {--n=500 : Number of missions}
    {--missions=transport,deploy,espionage,attack,recycle : Missions drawn in turn}
    {--max-level=12 : Highest research level drawn}
    {--seed=1 : Random seed}')]
class GenerateAiRlMissionCases extends Command
{
    use OpensRlUniverse;

    public function handle(): int
    {
        $universe = (string) $this->argument('universe');
        $missions = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('missions')))));
        $unknown = array_diff($missions, array_keys(DrawAiMissionCasesAction::MISSIONS));
        if ($missions === [] || $unknown !== []) {
            $this->error('Unknown mission(s): ' . implode(', ', $unknown) . '. Known: ' . implode(', ', array_keys(DrawAiMissionCasesAction::MISSIONS)));

            return self::FAILURE;
        }

        $checksum = hash_file('sha256', $universe);
        if ($checksum === false) {
            $this->error("Cannot read {$universe}.");

            return self::FAILURE;
        }
        $this->openRlUniverse($universe);
        // Mission processing takes a destination lock; an in-process store keeps it off any configured cache server.
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
        SimulatedTime::freezeAt(Date::createFromTimestamp((int) (DB::table('planets')->max('time_last_update') ?: Date::now()->getTimestamp())));
        $cases = app(DrawAiMissionCasesAction::class)->handle($accounts, max(1, (int) $this->option('n')), $missions, max(0, (int) $this->option('max-level')), (int) $this->option('seed'));
        DB::disconnect('ai_rl_universe');

        if (hash_file('sha256', $universe) !== $checksum) {
            $this->error('The universe file changed; nothing is written.');

            return self::FAILURE;
        }
        // Shortest round-trip form: the default precision would round a fractional stock, and a stock a hair off an
        // integer changes the truncation of a loot share by one.
        ini_set('serialize_precision', '-1');
        file_put_contents((string) $this->argument('out'), json_encode($cases, JSON_THROW_ON_ERROR));

        $accepted = count(array_filter($cases['cases'], static fn (array $case): bool => $case['dispatch']['accepted']));
        $this->info(sprintf('RL-MISSION-CASES: %s, %d mission(s), %d accepted, universe unchanged (sha256 %s)', $this->argument('out'), count($cases['cases']), $accepted, substr($checksum, 0, 12)));

        return self::SUCCESS;
    }
}
