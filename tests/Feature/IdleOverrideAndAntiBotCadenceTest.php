<?php

use Carbon\CarbonImmutable;
use Modules\AI\Contracts\ArchetypePolicyResolver;
use Modules\AI\Domain\Decision\DecisionEngine;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicy;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicyRegistry;
use Modules\AI\Domain\Decision\Policies\CasualPolicy;
use Modules\AI\Domain\Decision\Policies\FleeterPolicy;
use Modules\AI\Domain\Decision\Policies\MinerPolicy;
use Modules\AI\Domain\Decision\Policies\TraderPolicy;
use Modules\AI\Domain\Decision\Policies\TurtlePolicy;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Domain\Routine\SessionPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiCapability;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SystemAiClock;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    $this->app->bind(AiClock::class, SystemAiClock::class);
    $this->app->tag([MinerPolicy::class, TurtlePolicy::class, FleeterPolicy::class, TraderPolicy::class, CasualPolicy::class], ArchetypePolicy::class);
    $this->app->singleton(ArchetypePolicyResolver::class, fn ($app): ArchetypePolicyRegistry => $app->makeWith(ArchetypePolicyRegistry::class, [
        'policies' => $app->tagged(ArchetypePolicy::class),
    ]));
});

test('a rare seeded draw selects DoNothing even when a real action won', function (): void {
    $this->app->bind(RandomSource::class, fn (): RandomSource => idleRandomSource());
    $profile = idleProfile($this->currentUserId);

    $trace = app(DecisionEngine::class)->decide($profile, idleSnapshot($this->currentUserId, $this->currentPlanetId), 'idle:override');

    expect($trace->selected->candidate->type)->toBe(AiCandidateActionType::DoNothing);
});

test('the no-op override never suppresses a fleetsave', function (): void {
    $this->app->bind(RandomSource::class, fn (): RandomSource => idleRandomSource());
    $profile = idleProfile($this->currentUserId);

    $trace = app(DecisionEngine::class)->decide(
        $profile,
        idleSnapshot($this->currentUserId, $this->currentPlanetId, fleetsaveEligible: true),
        'idle:save',
    );

    expect($trace->selected->candidate->type)->toBe(AiCandidateActionType::FleetSave);
});

test('the no-op override never suppresses a reaction wake', function (): void {
    $this->app->bind(RandomSource::class, fn (): RandomSource => idleRandomSource());
    $profile = idleProfile($this->currentUserId);

    $trace = app(DecisionEngine::class)->decide(
        $profile,
        idleSnapshot($this->currentUserId, $this->currentPlanetId, reactionWakeAt: 1_700_000_000),
        'idle:reaction',
    );

    expect($trace->selected->candidate->type)->not->toBe(AiCandidateActionType::DoNothing);
});

test('the waking window never spans the host round-the-clock departure threshold', function (): void {
    $profile = idleProfile($this->currentUserId);
    $planner = app(SessionPlanner::class);
    $day = CarbonImmutable::create(2026, 9, 11, 0, 0, 0, 'UTC');

    $awakeHours = 0;
    for ($hour = 0; $hour < 24; $hour++) {
        if ($planner->isAwake($profile, $day->addHours($hour))) {
            $awakeHours++;
        }
    }

    $threshold = (int) app(SettingsService::class)->get('bot_detection_active_hours', 18);

    expect($awakeHours)->toBeLessThan($threshold);
});

function idleProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
        'enabled' => true,
    ]);
}

function idleSnapshot(int $playerId, int $planetId, bool $fleetsaveEligible = false, ?int $reactionWakeAt = null): PerceptionSnapshot
{
    return app()->makeWith(PerceptionSnapshot::class, [
        'playerId' => $playerId,
        'observedAt' => CarbonImmutable::create(2026, 9, 11, 8, 0, 0, 'UTC'),
        'planets' => [['id' => $planetId, 'resources' => ['metal' => 5_000, 'crystal' => 5_000, 'deuterium' => 5_000]]],
        'targetReports' => [],
        'availableActions' => [AiCapability::Build->value => true] + array_fill_keys(array_map(static fn (AiCapability $capability): string => $capability->value, AiCapability::cases()), false),
        'fleetsaveEligible' => $fleetsaveEligible,
        'recoveryFactor' => 0.1,
        'sourceTimestamps' => [],
        'fleetSlotsFree' => 2,
        'reactionWakeAt' => $reactionWakeAt,
    ]);
}

/** The idle draw always fires; every other draw is a neutral half. */
function idleRandomSource(): RandomSource
{
    return new class () implements RandomSource {
        public function unitInterval(int $seed, string $context): float
        {
            return str_ends_with($context, ':idle') ? 0.0 : 0.5;
        }
    };
}
