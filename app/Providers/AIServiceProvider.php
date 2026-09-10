<?php

namespace Modules\AI\Providers;

use Modules\AI\Console\Commands\RunDueAiWork;
use Modules\AI\Domain\Decision\BuildingScoringPolicy;
use Modules\AI\Domain\Decision\SeededBuildingScoringPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AIServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'AI';

    protected string $nameLower = 'ai';

    protected array $providers = [
        RouteServiceProvider::class,
    ];

    protected array $commands = [
        RunDueAiWork::class,
    ];

    public function boot(): void
    {
        // parent::boot() loads the module's routes, views, config, migrations,
        // commands, and schedules through Laravel Modules.
        parent::boot();
    }

    public function register(): void
    {
        parent::register();

        $this->app->bind(BuildingScoringPolicy::class, SeededBuildingScoringPolicy::class);
    }
}
