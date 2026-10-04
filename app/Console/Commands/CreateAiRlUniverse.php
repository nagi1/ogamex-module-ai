<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\SeedAiTestUniverseAction;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\SimulatedTime;
use OGame\Actions\Fortify\CreateNewUser;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Services\SettingsService;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * A fresh universe for training (plan/rl): its own SQLite file, the host's migrations, one human account
 * (the host makes the first registration the administrator) and N AI accounts registered the normal way,
 * at a fixed instant and with all randomness seeded, so the same seed always builds the same universe.
 * Play it with `ai:sim --in-memory` with DB_CONNECTION=sqlite and DB_DATABASE pointing at the file.
 *
 * DEV TOOLING: it writes only the file it is given and never touches the configured database.
 */
#[Description('Create a fresh, seeded training universe in a SQLite file (plan/rl).')]
#[Signature('ai:rl-universe
    {path : The SQLite file to create (overwritten)}
    {--accounts=24 : AI accounts to register}
    {--speed=8 : Economy, research and fleet speed}
    {--seed=1 : Seed for planet placement, persona seeds and every other draw}
    {--at=2026-10-05T00:00:00Z : The instant the universe is created at; start ai:sim --from here}')]
class CreateAiRlUniverse extends Command
{
    public function handle(): int
    {
        $path = (string) $this->argument('path');
        @mkdir(dirname($path), 0775, true);
        @unlink($path);
        touch($path);

        config(['database.connections.ai_rl_universe' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => false]]);
        config(['database.default' => 'ai_rl_universe']);
        DB::setDefaultConnection('ai_rl_universe');
        foreach ([SettingsService::class, PlayerServiceFactory::class, PlanetServiceFactory::class] as $cached) {
            app()->forgetInstance($cached);
        }

        $seed = (int) $this->option('seed');
        app()->instance(Randomizer::class, new Randomizer(new Xoshiro256StarStar($seed)));
        mt_srand($seed);
        SimulatedTime::freezeAt((string) $this->option('at'));

        Artisan::call('migrate', ['--force' => true, '--database' => 'ai_rl_universe']);

        $settings = app(SettingsService::class);
        $speed = max(1, (int) $this->option('speed'));
        foreach (['economy_speed', 'research_speed', 'fleet_speed', 'fleet_speed_war', 'fleet_speed_peaceful', 'fleet_speed_holding'] as $key) {
            $settings->set($key, $speed);
        }

        app(CreateNewUser::class)->create(['email' => 'admin-' . $seed . '@rl.invalid', 'password' => 'rl-universe-' . $seed . '-Aa1!']);
        $accounts = app(SeedAiTestUniverseAction::class)->handle(max(1, (int) $this->option('accounts')));

        // The seeding action derives persona seeds from the account's index, which would make every
        // universe's accounts take the same decisions; the universe seed makes them its own.
        foreach (AiProfile::query()->get() as $profile) {
            $profile->update(['random_seed' => (int) sprintf('%u', crc32('rl-universe:' . $seed . ':' . $profile->player_id))]);
        }

        $this->info(sprintf('RL-UNIVERSE: %s, seed %d, %d AI account(s), speed %d, at %s', $path, $seed, count($accounts), $speed, $this->option('at')));

        return self::SUCCESS;
    }
}
