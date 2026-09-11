<?php

namespace Modules\AI\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\AI\Support\HorizonConfiguration;

/**
 * Applies the module's Horizon lanes while the module is enabled.
 *
 * Kept separate from AIServiceProvider so the module's Horizon plan lives in
 * config/horizon.php and the main provider stays free of queue configuration.
 */
class HorizonServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(HorizonConfiguration::class)->contribute();
    }
}
