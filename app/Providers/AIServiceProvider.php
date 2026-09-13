<?php

namespace Modules\AI\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Modules\AI\Actions\QueueAiBuildingAction;
use Modules\AI\Actions\RunAiSessionAction;
use Modules\AI\Console\Commands\ReconcileLanguageRequests;
use Modules\AI\Console\Commands\RunCognitionConformance;
use Modules\AI\Console\Commands\RunDueAiWork;
use Modules\AI\Console\Commands\RunLanguageConformance;
use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Contracts\ArchetypePolicyResolver;
use Modules\AI\Contracts\ContextBuilder;
use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Contracts\LanguageGateway;
use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Contracts\QueueAiBuilding;
use Modules\AI\Contracts\RunAiSession;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\NativeContextBuilder;
use Modules\AI\Domain\Decision\BuildingScoringPolicy;
use Modules\AI\Domain\Decision\ExperienceInformedBuildingScoringPolicy;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicy;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicyRegistry;
use Modules\AI\Domain\Decision\Policies\CasualPolicy;
use Modules\AI\Domain\Decision\Policies\FleeterPolicy;
use Modules\AI\Domain\Decision\Policies\MinerPolicy;
use Modules\AI\Domain\Decision\Policies\TraderPolicy;
use Modules\AI\Domain\Decision\Policies\TurtlePolicy;
use Modules\AI\Domain\Decision\SeededBuildingScoringPolicy;
use Modules\AI\Enums\AiCognitionDriver;
use Modules\AI\Infrastructure\Cognition\FatimaClient;
use Modules\AI\Infrastructure\Cognition\FatimaCognitionSession;
use Modules\AI\Infrastructure\Language\LaravelAiLanguageGateway;
use Modules\AI\Infrastructure\Language\NullLanguageGateway;
use Modules\AI\Listeners\RecordAiBuildingCompletionExperience;
use Modules\AI\Observers\ObserveCommittedAllianceMembership;
use Modules\AI\Observers\ObserveCommittedBattleReport;
use Modules\AI\Observers\ObserveCommittedChatMessage;
use Modules\AI\Observers\RedactDeletedChatMemory;
use Modules\AI\Support\AffectEngineSelector;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\DriverCircuitBreaker;
use Modules\AI\Support\ExperienceEngineSelector;
use Modules\AI\Support\FatimaScenarioTemplate;
use Modules\AI\Support\LongTermMemorySelector;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SeededRandomSource;
use Modules\AI\Support\SocialCognitionSelector;
use Modules\AI\Support\SystemAiClock;
use Nwidart\Modules\Support\ModuleServiceProvider;
use OGame\Events\Game\BuildingCompleted;
use OGame\Models\AllianceMember;
use OGame\Models\BattleReport;
use OGame\Models\ChatMessage;

class AIServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'AI';

    protected string $nameLower = 'ai';

    protected array $providers = [
        RouteServiceProvider::class,
        HorizonServiceProvider::class,
    ];

    protected array $commands = [
        ReconcileLanguageRequests::class,
        RunCognitionConformance::class,
        RunDueAiWork::class,
        RunLanguageConformance::class,
    ];

    public function boot(): void
    {
        // parent::boot() loads the module's routes, views, config, migrations,
        // commands, and schedules through Laravel Modules.
        parent::boot();

        BattleReport::observe(ObserveCommittedBattleReport::class);
        ChatMessage::observe(ObserveCommittedChatMessage::class);
        ChatMessage::observe(RedactDeletedChatMemory::class);
        AllianceMember::observe(ObserveCommittedAllianceMembership::class);
        Event::listen(BuildingCompleted::class, RecordAiBuildingCompletionExperience::class);
    }

    /**
     * Dispatch due AI work every minute. The command only leases and enqueues; every
     * decision still runs inside its leased, idempotent job on the AI Horizon lane.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('ai:run-due-work')->everyMinute()->withoutOverlapping(5);
        $schedule->command('ai:reconcile-language-requests')->everyTenMinutes()->withoutOverlapping(5);
    }

    public function register(): void
    {
        parent::register();

        $this->app->bind(RunAiSession::class, RunAiSessionAction::class);
        $this->app->bind(AffectEngine::class, fn (): AffectEngine => app(AffectEngineSelector::class)->resolve());
        $this->app->bind(ExperienceEngine::class, fn (): ExperienceEngine => app(ExperienceEngineSelector::class)->resolve());
        $this->app->bind(ContextBuilder::class, NativeContextBuilder::class);
        $this->app->bind(LongTermMemory::class, fn (): LongTermMemory => app(LongTermMemorySelector::class)->resolve());
        $this->app->bind(LanguageGateway::class, fn (): LanguageGateway => (bool) config('ai.language.enabled', false)
            ? app(LaravelAiLanguageGateway::class)
            : app(NullLanguageGateway::class));
        $this->app->bind(SocialCognition::class, fn (): SocialCognition => app(SocialCognitionSelector::class)->resolve());
        // Affect and social cognition resolve the same session, so the module advances one
        // integrated character state rather than one per contract. The circuit breaker needs
        // the driver name, which a contextual binding supplies without the client having to
        // resolve itself from inside its own binding.
        $this->app->when(FatimaClient::class)
            ->needs(DriverCircuitBreaker::class)
            ->give(fn (): DriverCircuitBreaker => $this->app->makeWith(DriverCircuitBreaker::class, [
                'driver' => AiCognitionDriver::Fatima->value,
            ]));
        $this->app->singleton(FatimaScenarioTemplate::class);
        $this->app->singleton(FatimaCognitionSession::class);
        $this->app->bind(QueueAiBuilding::class, QueueAiBuildingAction::class);
        // The seeded policy answers the persona preference; the decorator adds this AI's
        // finalized outcomes, so experience evidence reaches a real decision instead of
        // being ranked and never read.
        $this->app->bind(BuildingScoringPolicy::class, fn (): BuildingScoringPolicy => $this->app->makeWith(
            ExperienceInformedBuildingScoringPolicy::class,
            ['seeded' => $this->app->make(SeededBuildingScoringPolicy::class)],
        ));
        $this->app->bind(AiClock::class, SystemAiClock::class);
        $this->app->bind(RandomSource::class, SeededRandomSource::class);
        $this->app->tag([
            MinerPolicy::class,
            TurtlePolicy::class,
            FleeterPolicy::class,
            TraderPolicy::class,
            CasualPolicy::class,
        ], ArchetypePolicy::class);
        $this->app->singleton(ArchetypePolicyResolver::class, fn (): ArchetypePolicyRegistry => app()->makeWith(ArchetypePolicyRegistry::class, [
            'policies' => $this->app->tagged(ArchetypePolicy::class),
        ]));
    }
}
