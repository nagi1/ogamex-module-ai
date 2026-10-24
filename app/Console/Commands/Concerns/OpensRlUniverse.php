<?php

namespace Modules\AI\Console\Commands\Concerns;

use Illuminate\Support\Facades\DB;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Services\SettingsService;

/** Points the application at an `ai:rl-universe` SQLite file, so a training command never touches the configured database. */
trait OpensRlUniverse
{
    protected function openRlUniverse(string $path): void
    {
        config(['database.connections.ai_rl_universe' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => false]]);
        config(['database.default' => 'ai_rl_universe']);
        DB::setDefaultConnection('ai_rl_universe');
        foreach ([SettingsService::class, PlayerServiceFactory::class, PlanetServiceFactory::class] as $cached) {
            app()->forgetInstance($cached);
        }
    }
}
