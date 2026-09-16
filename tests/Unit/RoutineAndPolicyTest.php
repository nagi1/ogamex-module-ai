<?php

use Carbon\CarbonImmutable;
use Modules\AI\Contracts\ArchetypePolicyResolver;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicy;
use Modules\AI\Domain\Decision\Policies\ArchetypePolicyRegistry;
use Modules\AI\Domain\Decision\Policies\CasualPolicy;
use Modules\AI\Domain\Decision\Policies\FleeterPolicy;
use Modules\AI\Domain\Decision\Policies\MinerPolicy;
use Modules\AI\Domain\Decision\Policies\TraderPolicy;
use Modules\AI\Domain\Decision\Policies\TurtlePolicy;
use Modules\AI\Domain\Routine\RoutineProfile;
use Modules\AI\Domain\Routine\SessionPlan;
use Modules\AI\Domain\Routine\SessionPlanner;
use Modules\AI\Domain\Scheduling\NextDueTimeCalculator;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiProfileSettings;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SeededRandomSource;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    $this->app->bind(RandomSource::class, SeededRandomSource::class);
    $this->app->tag([MinerPolicy::class, TurtlePolicy::class, FleeterPolicy::class, TraderPolicy::class, CasualPolicy::class], ArchetypePolicy::class);
    $this->app->singleton(ArchetypePolicyResolver::class, fn ($app): ArchetypePolicyRegistry => $app->makeWith(ArchetypePolicyRegistry::class, [
        'policies' => $app->tagged(ArchetypePolicy::class),
    ]));
});

test('routine profile uses safe defaults for invalid or too small settings', function () {
    $profile = aiRoutineProfile([
        AiProfileSettings::TIMEZONE => 'not/a-timezone',
        AiProfileSettings::SESSION_MINUTES => 0,
    ]);

    $routine = RoutineProfile::fromAiProfile($profile);

    expect($routine->timezone)->toBe(AiProfileSettings::DEFAULT_TIMEZONE);
    expect($routine->sessionMinutes)->toBe(1);
});

test('each archetype keeps a routine the others do not', function (AiArchetype $archetype) {
    $routine = RoutineProfile::fromAiProfile(aiRoutineProfile([], $archetype));

    expect($routine->sessionsPerDay)->toBeGreaterThanOrEqual(2);
})->with([
    'miner' => [AiArchetype::Miner],
    'turtle' => [AiArchetype::Turtle],
    'fleeter' => [AiArchetype::Fleeter],
    'trader' => [AiArchetype::Trader],
    'casual' => [AiArchetype::Casual],
]);

test('a casual player looks in less often than a fleeter', function () {
    $casual = RoutineProfile::fromAiProfile(aiRoutineProfile([], AiArchetype::Casual));
    $fleeter = RoutineProfile::fromAiProfile(aiRoutineProfile([], AiArchetype::Fleeter));

    expect($casual->sessionsPerDay)->toBeLessThan($fleeter->sessionsPerDay);
});

test('session plans are seeded and keep due work in the future', function () {
    $now = CarbonImmutable::create(2026, 9, 11, 8, 0, 0, 'UTC') ?? throw app()->makeWith(LogicException::class, ['message' => 'Unable to create fixed session time.']);
    $profile = aiRoutineProfile([
        AiProfileSettings::TIMEZONE => 'Asia/Riyadh',
        AiProfileSettings::SESSION_MINUTES => 10,
    ]);
    $planner = app(SessionPlanner::class);

    $first = $planner->plan($profile, $now, 1);
    $second = $planner->plan($profile, $now, 1);

    expect($second)->toEqual($first);
    expect($first->nextDueAt->greaterThan($now))->toBeTrue();
    expect($first->nextDueAt->greaterThanOrEqualTo($first->sessionEndsAt))->toBeTrue();
});

test('the account is awake inside its window and asleep inside the dark period', function () {
    $planner = app(SessionPlanner::class);
    $profile = aiRoutineProfile([AiProfileSettings::TIMEZONE => 'UTC']);

    $noon = CarbonImmutable::create(2026, 9, 11, 12, 0, 0, 'UTC');
    $deepNight = CarbonImmutable::create(2026, 9, 11, 3, 0, 0, 'UTC');

    expect($planner->isAwake($profile, $noon))->toBeTrue()
        ->and($planner->isAwake($profile, $deepNight))->toBeFalse();
});

test('next due calculator handles future and elapsed session plans', function () {
    $now = CarbonImmutable::createFromTimestamp(1_789_012_345);
    $calculator = app(NextDueTimeCalculator::class);

    expect($calculator->fromSession(app()->makeWith(SessionPlan::class, [
        'sessionEndsAt' => $now,
        'nextDueAt' => $now->addMinutes(2),
    ]), $now))->toEqual($now->addMinutes(2));
    expect($calculator->fromSession(app()->makeWith(SessionPlan::class, [
        'sessionEndsAt' => $now,
        'nextDueAt' => $now,
    ]), $now))->toEqual($now->addMinute());
});

test('seeded random source is isolated and bounded', function () {
    $random = app(SeededRandomSource::class);
    $value = $random->unitInterval(42, 'candidate:raid');

    expect($random->unitInterval(42, 'candidate:raid'))->toBe($value);
    expect($value)->toBeGreaterThanOrEqual(0.0);
    expect($value)->toBeLessThan(1.0);
});

test('policies cover every profile and reject only declared raid profiles', function () {
    $registry = app(ArchetypePolicyResolver::class);

    foreach (AiArchetype::cases() as $archetype) {
        $policy = $registry->for($archetype);
        expect($policy->archetype())->toBe($archetype);
        expect($policy->allows(AiCandidateActionType::FleetSave))->toBeTrue();
    }

    expect($registry->for(AiArchetype::Miner)->preference(AiCandidateActionType::Colonize))->toBe(0.0);
    expect($registry->for(AiArchetype::Miner)->allows(AiCandidateActionType::Raid))->toBeFalse();
    expect($registry->for(AiArchetype::Turtle)->allows(AiCandidateActionType::Raid))->toBeFalse();
    expect($registry->for(AiArchetype::Trader)->allows(AiCandidateActionType::Raid))->toBeFalse();
    expect($registry->for(AiArchetype::Fleeter)->allows(AiCandidateActionType::Raid))->toBeTrue();
    expect($registry->for(AiArchetype::Casual)->allows(AiCandidateActionType::Raid))->toBeTrue();
});

test('registry fails closed when a profile policy is missing', function () {
    $registry = app()->makeWith(ArchetypePolicyRegistry::class, [
        'policies' => [app(MinerPolicy::class)],
    ]);

    expect(fn (): mixed => $registry->for(AiArchetype::Fleeter))->toThrow(LogicException::class);
});

/** @param array<string, mixed> $settings */
function aiRoutineProfile(array $settings, AiArchetype $archetype = AiArchetype::Miner): AiProfile
{
    return app()->makeWith(AiProfile::class, ['attributes' => [
        'id' => 1,
        'player_id' => 1,
        'archetype' => $archetype,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
        'settings' => $settings,
    ]]);
}
