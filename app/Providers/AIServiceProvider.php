<?php

namespace Modules\AI\Providers;

use Modules\AI\Actions\QueueAiBuildingAction;
use Modules\AI\Actions\RunAiSessionAction;
use Modules\AI\Console\Commands\RunDueAiWork;
use Modules\AI\Contracts\QueueAiBuilding;
use Modules\AI\Contracts\RunAiSession;
use Modules\AI\Domain\Decision\BuildingScoringPolicy;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicy;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicyRegistry;
use Modules\AI\Domain\Decision\Policies\CasualPolicy;
use Modules\AI\Domain\Decision\Policies\FleeterPolicy;
use Modules\AI\Domain\Decision\Policies\MinerPolicy;
use Modules\AI\Domain\Decision\Policies\TraderPolicy;
use Modules\AI\Domain\Decision\Policies\TurtlePolicy;
use Modules\AI\Domain\Decision\SeededBuildingScoringPolicy;
use Modules\AI\Observers\ObserveCommittedChatMessage;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SeededRandomSource;
use Modules\AI\Support\SystemAiClock;
use Nwidart\Modules\Support\ModuleServiceProvider;
use OGame\Models\ChatMessage;

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

        ChatMessage::observe(ObserveCommittedChatMessage::class);
    }

    public function register(): void
    {
        parent::register();

        $this->app->bind(RunAiSession::class, RunAiSessionAction::class);
        $this->app->bind(QueueAiBuilding::class, QueueAiBuildingAction::class);
        $this->app->bind(BuildingScoringPolicy::class, SeededBuildingScoringPolicy::class);
        $this->app->bind(AiClock::class, SystemAiClock::class);
        $this->app->bind(RandomSource::class, SeededRandomSource::class);
        $this->app->tag([
            MinerPolicy::class,
            TurtlePolicy::class,
            FleeterPolicy::class,
            TraderPolicy::class,
            CasualPolicy::class,
        ], ArchetypePolicy::class);
        $this->app->singleton(ArchetypePolicyRegistry::class, fn ($app): ArchetypePolicyRegistry => new ArchetypePolicyRegistry($app->tagged(ArchetypePolicy::class)));
    }
}
