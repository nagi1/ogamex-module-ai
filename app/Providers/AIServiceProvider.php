<?php

namespace Modules\AI\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Modules\AI\Actions\QueueAiBuildingAction;
use Modules\AI\Actions\QueueAiColonyAction;
use Modules\AI\Actions\QueueAiDefendAction;
use Modules\AI\Actions\QueueAiJumpGateAction;
use Modules\AI\Actions\QueueAiMissileAction;
use Modules\AI\Actions\QueueAiExpeditionAction;
use Modules\AI\Actions\QueueAiFleetSaveAction;
use Modules\AI\Actions\QueueAiMinePercentAction;
use Modules\AI\Actions\QueueAiPhalanxAction;
use Modules\AI\Actions\QueueAiRaidAction;
use Modules\AI\Actions\QueueAiRecallAction;
use Modules\AI\Actions\QueueAiRecycleAction;
use Modules\AI\Actions\QueueAiRelocationAction;
use Modules\AI\Actions\QueueAiResearchAction;
use Modules\AI\Actions\QueueAiSpyAction;
use Modules\AI\Actions\QueueAiTradeAction;
use Modules\AI\Actions\QueueAiTransferAction;
use Modules\AI\Actions\QueueAiUnitsAction;
use Modules\AI\Actions\RunAiSessionAction;
use Modules\AI\Console\Commands\AdvanceAiAllianceLife;
use Modules\AI\Console\Commands\AdvanceAiCampaigns;
use Modules\AI\Console\Commands\BondExistingAllianceMembers;
use Modules\AI\Console\Commands\CreateAiRlUniverse;
use Modules\AI\Console\Commands\ExplainAiDecision;
use Modules\AI\Console\Commands\PruneAiRecords;
use Modules\AI\Console\Commands\ReconcileLanguageRequests;
use Modules\AI\Console\Commands\RecordAiScoreSamples;
use Modules\AI\Console\Commands\ReplayAiScenario;
use Modules\AI\Console\Commands\ReportAiPilot;
use Modules\AI\Console\Commands\RunAiCampaign;
use Modules\AI\Console\Commands\RunCognitionConformance;
use Modules\AI\Console\Commands\RunDueAiWork;
use Modules\AI\Console\Commands\RunLanguageConformance;
use Modules\AI\Console\Commands\SeedAiTestUniverse;
use Modules\AI\Console\Commands\SeedGrandTest;
use Modules\AI\Console\Commands\SimulateAiTime;
use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Contracts\ArchetypePolicyResolver;
use Modules\AI\Contracts\CampaignConsultationGateway;
use Modules\AI\Contracts\ChoicePolicy;
use Modules\AI\Contracts\ContextBuilder;
use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Contracts\LanguageGateway;
use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Contracts\QueueAiBuilding;
use Modules\AI\Contracts\QueueAiColony;
use Modules\AI\Contracts\QueueAiDefend;
use Modules\AI\Contracts\QueueAiJumpGate;
use Modules\AI\Contracts\QueueAiMissile;
use Modules\AI\Contracts\QueueAiExpedition;
use Modules\AI\Contracts\QueueAiFleetSave;
use Modules\AI\Contracts\QueueAiMinePercent;
use Modules\AI\Contracts\QueueAiPhalanx;
use Modules\AI\Contracts\QueueAiRaid;
use Modules\AI\Contracts\QueueAiRecall;
use Modules\AI\Contracts\QueueAiRecycle;
use Modules\AI\Contracts\QueueAiRelocation;
use Modules\AI\Contracts\QueueAiResearch;
use Modules\AI\Contracts\QueueAiSpy;
use Modules\AI\Contracts\QueueAiTrade;
use Modules\AI\Contracts\QueueAiTransfer;
use Modules\AI\Contracts\QueueAiUnits;
use Modules\AI\Contracts\RunAiSession;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Choice\EpsilonChoicePolicy;
use Modules\AI\Domain\Choice\SocketChoicePolicy;
use Modules\AI\Domain\Choice\TeacherChoicePolicy;
use Modules\AI\Domain\Conversation\NativeContextBuilder;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicy;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicyRegistry;
use Modules\AI\Domain\Decision\Policies\FleeterPolicy;
use Modules\AI\Domain\Decision\Policies\HybridPolicy;
use Modules\AI\Domain\Decision\Policies\RaiderPolicy;
use Modules\AI\Domain\Decision\Policies\MinerPolicy;
use Modules\AI\Domain\Decision\Policies\TurtlePolicy;
use Modules\AI\Enums\AiCampaignConsultationMode;
use Modules\AI\Enums\AiCognitionDriver;
use Modules\AI\Infrastructure\Cognition\FatimaClient;
use Modules\AI\Infrastructure\Cognition\FatimaCognitionSession;
use Modules\AI\Infrastructure\Cognition\PsychSimClient;
use Modules\AI\Infrastructure\Language\LaravelAiCampaignConsultationGateway;
use Modules\AI\Infrastructure\Language\LaravelAiLanguageGateway;
use Modules\AI\Infrastructure\Language\NullCampaignConsultationGateway;
use Modules\AI\Infrastructure\Language\NullLanguageGateway;
use Modules\AI\Listeners\RecordAiBuildingCompletionExperience;
use Modules\AI\Listeners\RecordAiColonyCampaignSignal;
use Modules\AI\Observers\ObserveCommittedAllianceMembership;
use Modules\AI\Observers\ObserveCommittedBattleReport;
use Modules\AI\Observers\ObserveCommittedChatMessage;
use Modules\AI\Observers\ObserveCommittedFleetMessage;
use Modules\AI\Observers\RedactDeletedChatMemory;
use Modules\AI\Support\AffectEngineSelector;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\AiRuntimeSettings;
use Modules\AI\Support\AiSettings;
use Modules\AI\Support\CooperativeHostilityPolicy;
use Modules\AI\Support\DriverCircuitBreaker;
use Modules\AI\Support\ExperienceEngineSelector;
use Modules\AI\Support\FatimaScenarioTemplate;
use Modules\AI\Support\LongTermMemorySelector;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SeededRandomSource;
use Modules\AI\Support\SocialCognitionSelector;
use Modules\AI\Support\SimulatedTime;
use Modules\AI\Support\SystemAiClock;
use Nwidart\Modules\Support\ModuleServiceProvider;
use OGame\Console\Commands\Scheduler\GenerateAllianceHighscores;
use OGame\Console\Commands\Scheduler\GenerateHighscoreRanks;
use OGame\Console\Commands\Scheduler\GenerateHighscores;
use OGame\Events\Game\BuildingCompleted;
use OGame\Events\Game\PlanetCreated;
use OGame\Models\AllianceMember;
use OGame\Models\BattleReport;
use OGame\Models\ChatMessage;
use OGame\Models\Message;
use OGame\Services\HostilityGuard;
use OGame\Services\ModuleSlotService;
use OGame\Services\SettingsService;

class AIServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'AI';

    protected string $nameLower = 'ai';

    protected array $providers = [
        RouteServiceProvider::class,
        HorizonServiceProvider::class,
    ];

    protected array $commands = [
        AdvanceAiCampaigns::class,
        RunAiCampaign::class,
        AdvanceAiAllianceLife::class,
        BondExistingAllianceMembers::class,
        ExplainAiDecision::class,
        PruneAiRecords::class,
        ReconcileLanguageRequests::class,
        RecordAiScoreSamples::class,
        ReplayAiScenario::class,
        ReportAiPilot::class,
        RunCognitionConformance::class,
        RunDueAiWork::class,
        RunLanguageConformance::class,
        SeedAiTestUniverse::class,
        SeedGrandTest::class,
        SimulateAiTime::class,
        CreateAiRlUniverse::class,
    ];

    public function boot(): void
    {
        // parent::boot() loads the module's routes, views, config, migrations,
        // commands, and schedules through Laravel Modules.
        parent::boot();

        // `AI_SIM_NOW` stands any script, tinker run or command at one instant (dev tooling; unset in production).
        SimulatedTime::freezeFromEnvironment();

        BattleReport::observe(ObserveCommittedBattleReport::class);
        ChatMessage::observe(ObserveCommittedChatMessage::class);
        ChatMessage::observe(RedactDeletedChatMemory::class);
        AllianceMember::observe(ObserveCommittedAllianceMembership::class);
        Message::observe(ObserveCommittedFleetMessage::class);
        Event::listen(BuildingCompleted::class, RecordAiBuildingCompletionExperience::class);
        Event::listen(PlanetCreated::class, RecordAiColonyCampaignSignal::class);

        // The cooperative policy is a read-only answer the host guard consults at hostile
        // dispatch; the host enforces it. Registering here means a disabled module registers
        // nothing, and the guard's fail-closed path blocks hostility instead of unlocking PvP.
        app(HostilityGuard::class)->register(app(CooperativeHostilityPolicy::class));

        // The module's operator page is reachable from the host's Modules admin page through
        // the documented slot, so the module adds a link there instead of cluttering the top bar.
        ModuleSlotService::register('admin.modules', static function (array $data): string {
            return view('ai::partials.admin-nav')->render();
        });
    }

    /**
     * Dispatch due AI work every minute. The command only leases and enqueues; every
     * decision still runs inside its leased, idempotent job on the AI Horizon lane.
     *
     * The score sample is hourly and off the session path: it exists so the growth curve can be
     * read back at all (the host keeps no score history), and the command itself decides whether
     * the review collection is switched on, so a manual run behaves exactly like this one.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $sessionInterval = (int) config('ai.population.session_interval_seconds', 0);
        $dueWork = $schedule->command('ai:run-due-work');
        if ($sessionInterval > 0 && $sessionInterval <= 10) {
            $dueWork->everyTenSeconds();
        }
        if ($sessionInterval <= 0 || $sessionInterval > 10) {
            $dueWork->everyMinute();
        }
        $dueWork->withoutOverlapping(5);
        $schedule->command('ai:run-campaign')->everyFifteenMinutes()->withoutOverlapping(5);
        $schedule->command('ai:advance-campaigns')->everyMinute()->withoutOverlapping(5);
        // These three used to be every-ten-minutes and hourly, and stopped firing altogether: the
        // container's scheduler loop is `schedule:run; sleep 60`, so its phase drifts and an event
        // whose cron names a minute (0, 10, 20 ...) is simply never due when the loop lands. The
        // growth curve was dead for a day on grand and four on pve before anyone could see it
        // (found 30 Sep 2026). Each of them is idempotent by state -- the sampler buckets on
        // `startOfHour()` and updates that hour's row, alliance life skips an account that is
        // already engaged or already applied, and reconciliation only settles reservations -- so
        // running them when the loop happens to tick costs nothing and misses nothing. The root
        // cause stays the entrypoint's loop: see HARNESS-003.
        $schedule->command('ai:advance-alliance-life')->everyMinute()->withoutOverlapping(5);
        $schedule->command('ai:reconcile-language-requests')->everyMinute()->withoutOverlapping(5);
        $schedule->command('ai:record-score-samples')->everyMinute()->withoutOverlapping(5);
        // The host schedules player highscores, alliance highscores and rank generation
        // everyFiveMinutes(), which the drifting `schedule:run; sleep 60` loop almost never
        // lands on, so ranks stayed unwritten and alliance choice/review had nothing to read.
        // Run the same three, in the host's order, on the first tick of each five-minute window;
        // Cache::add makes it once per window however many ticks arrive. Mitigation only: the
        // host entrypoint now runs schedule:work.
        $schedule->call(static function (): void {
            $window = intdiv(now()->getTimestamp(), 300);
            if (! Cache::add('ai:highscore-window:'.$window, 1, 600)) {
                return;
            }
            foreach ([GenerateHighscores::class, GenerateAllianceHighscores::class, GenerateHighscoreRanks::class] as $command) {
                Artisan::call($command);
            }
        })->name('ai:highscore-tick')->everyMinute()->withoutOverlapping(5);
        // Retention is enforced on a quiet hour rather than at the moment a row
        // expires: a nightly sweep is one delete per table instead of a job per
        // row, and the windows are measured in days.
        $schedule->command('ai:prune')->dailyAt('03:30')->withoutOverlapping(30);
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(AiSettings::class, static fn (): AiSettings => AiSettings::resolve());
        // Resolve the host settings service explicitly so the module reads and writes the same
        // cached instance the host seeded at boot, rather than a second copy of the table.
        $this->app->singleton(AiRuntimeSettings::class, static fn ($app): AiRuntimeSettings => new AiRuntimeSettings($app->make(SettingsService::class)));

        $this->app->bind(RunAiSession::class, RunAiSessionAction::class);
        // One login's claims on ships and fleet slots; reset at the start of every schedule pass.
        $this->app->singleton(\Modules\AI\Domain\Login\LoginReservations::class);
        $this->app->bind(AffectEngine::class, fn (): AffectEngine => app(AffectEngineSelector::class)->resolve());
        $this->app->bind(ExperienceEngine::class, fn (): ExperienceEngine => app(ExperienceEngineSelector::class)->resolve());
        $this->app->bind(ContextBuilder::class, NativeContextBuilder::class);
        $this->app->bind(LongTermMemory::class, fn (): LongTermMemory => app(LongTermMemorySelector::class)->resolve());
        $this->app->bind(LanguageGateway::class, fn (): LanguageGateway => app(AiRuntimeSettings::class)->languageEnabled()
            ? app(LaravelAiLanguageGateway::class)
            : app(NullLanguageGateway::class));
        $this->app->bind(CampaignConsultationGateway::class, fn (): CampaignConsultationGateway => app(AiRuntimeSettings::class)->campaignMode() !== AiCampaignConsultationMode::Off
            ? app(LaravelAiCampaignConsultationGateway::class)
            : app(NullCampaignConsultationGateway::class));
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
        $this->app->when(PsychSimClient::class)
            ->needs(DriverCircuitBreaker::class)
            ->give(fn (): DriverCircuitBreaker => $this->app->makeWith(DriverCircuitBreaker::class, [
                'driver' => AiCognitionDriver::PsychSim->value,
            ]));
        $this->app->singleton(FatimaScenarioTemplate::class);
        $this->app->singleton(FatimaCognitionSession::class);
        $this->app->bind(QueueAiBuilding::class, QueueAiBuildingAction::class);
        $this->app->bind(QueueAiResearch::class, QueueAiResearchAction::class);
        $this->app->bind(QueueAiUnits::class, QueueAiUnitsAction::class);
        $this->app->bind(QueueAiColony::class, QueueAiColonyAction::class);
        $this->app->bind(QueueAiExpedition::class, QueueAiExpeditionAction::class);
        $this->app->bind(QueueAiFleetSave::class, QueueAiFleetSaveAction::class);
        $this->app->bind(QueueAiRecall::class, QueueAiRecallAction::class);
        $this->app->bind(QueueAiRaid::class, QueueAiRaidAction::class);
        $this->app->bind(QueueAiPhalanx::class, QueueAiPhalanxAction::class);
        $this->app->bind(QueueAiRecycle::class, QueueAiRecycleAction::class);
        $this->app->bind(QueueAiSpy::class, QueueAiSpyAction::class);
        $this->app->bind(QueueAiTransfer::class, QueueAiTransferAction::class);
        $this->app->bind(QueueAiDefend::class, QueueAiDefendAction::class);
        $this->app->bind(QueueAiTrade::class, QueueAiTradeAction::class);
        $this->app->bind(QueueAiRelocation::class, QueueAiRelocationAction::class);
        $this->app->bind(QueueAiJumpGate::class, QueueAiJumpGateAction::class);
        $this->app->bind(QueueAiMissile::class, QueueAiMissileAction::class);
        $this->app->bind(QueueAiMinePercent::class, QueueAiMinePercentAction::class);
        $this->app->bind(AiClock::class, SystemAiClock::class);
        $this->app->bind(RandomSource::class, SeededRandomSource::class);
        // Who answers an economy choice (plan/rl): the planner itself unless a run asks otherwise.
        $this->app->bind(ChoicePolicy::class, fn (): ChoicePolicy => match (config('ai.rl.policy', 'teacher')) {
            'epsilon' => app(EpsilonChoicePolicy::class),
            'socket' => app(SocketChoicePolicy::class),
            default => app(TeacherChoicePolicy::class),
        });
        $this->app->tag([
            MinerPolicy::class,
            TurtlePolicy::class,
            FleeterPolicy::class,
            RaiderPolicy::class,
            HybridPolicy::class,
        ], ArchetypePolicy::class);
        $this->app->singleton(ArchetypePolicyResolver::class, fn (): ArchetypePolicyRegistry => app()->makeWith(ArchetypePolicyRegistry::class, [
            'policies' => $this->app->tagged(ArchetypePolicy::class),
        ]));
    }
}
