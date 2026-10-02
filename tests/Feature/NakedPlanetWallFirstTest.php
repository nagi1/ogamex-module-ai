<?php

use Modules\AI\Actions\ScheduleAiIntentAction;
use Modules\AI\Domain\Decision\CandidateAction;
use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// QUAL-003: the cohort read kept finding one planet at zero defence beside a sibling holding a wall. The
// wall order is priced against the planet's balance at decision time, but the session's building steps run
// first and spend it, so the host refused the order and the planet was naked again on every login. The
// first wall of a bare planet is placed before those steps; every other unit order keeps waiting for them.

test('the first wall of a bare planet beside a walled sibling is marked ahead of the building steps', function (): void {
    $profile = nakedWallFirstProfile($this->currentUserId);
    nakedWallFirstHome($this->planetService);
    $colony = nakedWallFirstColony($this->secondPlanetService);

    $plan = app(QueueableUnitPlanner::class)->plan($profile->player_id);

    expect($plan)->not->toBeNull()
        ->and($plan->planetId)->toBe($colony->getPlanetId())
        ->and($plan->reason)->toContain('role:defense:standing')
        ->and($plan->aheadOfEconomy)->toBeTrue();
});

// The marker is specific to a planet that stands nothing: a wall on a planet that already holds
// units is an ordinary order and keeps its place in the login, after the building steps.
test('a wall on a planet that already stands defence is not marked ahead of the building steps', function (): void {
    $profile = nakedWallFirstProfile($this->currentUserId);
    // Every planet already stands a wall, so neither sibling is bare; the tall sibling's wall covers
    // what it produces and the thin one's does not, which is the only planet left wanting.
    nakedWallFirstWalledPlanet($this->planetService, 1);
    nakedWallFirstWalledPlanet($this->secondPlanetService, 1_500);

    $plan = app(QueueableUnitPlanner::class)->plan($profile->player_id);

    expect($plan)->not->toBeNull()
        ->and($plan->reason)->toContain('role:defense:standing')
        ->and($plan->aheadOfEconomy)->toBeFalse();
});

test('a marked wall is placed before the session\'s building steps', function (): void {
    $profile = nakedWallFirstProfile($this->currentUserId);
    nakedWallFirstHome($this->planetService);
    $colony = nakedWallFirstColony($this->secondPlanetService);

    nakedWallFirstSchedule($profile);

    $units = AiWorkItem::query()->where('player_id', $profile->player_id)->where('kind', AiWorkKind::QueueUnits)->first();
    $builds = AiWorkItem::query()->where('player_id', $profile->player_id)->where('kind', AiWorkKind::BuildFirstBuilding)->get();

    expect($units)->not->toBeNull()
        ->and($units->payload['planet_id'])->toBe($colony->getPlanetId())
        ->and($builds)->not->toBeEmpty()
        // Same instant, so the worker's own due-then-id order is what places the wall first.
        ->and($units->id)->toBeLessThan($builds->min('id'));
});

test('an unmarked unit order still waits for the building steps', function (): void {
    $profile = nakedWallFirstProfile($this->currentUserId);
    nakedWallFirstWalledPlanet($this->planetService, 1);
    nakedWallFirstWalledPlanet($this->secondPlanetService, 1_500);

    nakedWallFirstSchedule($profile);

    $units = AiWorkItem::query()->where('player_id', $profile->player_id)->where('kind', AiWorkKind::QueueUnits)->first();
    $builds = AiWorkItem::query()->where('player_id', $profile->player_id)->where('kind', AiWorkKind::BuildFirstBuilding)->get();

    expect($units)->not->toBeNull()
        ->and($builds)->not->toBeEmpty()
        ->and($units->due_at->greaterThanOrEqualTo($builds->max('due_at')))->toBeTrue('expected the unit order after the building steps');
});

/** Runs the real schedule step for a session that chose the yard. */
function nakedWallFirstSchedule(AiProfile $profile): void
{
    $session = AiWorkItem::create([
        'player_id' => $profile->player_id,
        'kind' => AiWorkKind::RunSession,
        'due_at' => now(),
        'idempotency_key' => 'naked-wall-first:' . $profile->player_id,
        'state' => AiWorkState::Pending,
    ]);

    app(ScheduleAiIntentAction::class)->handle($profile, $session, nakedWallFirstTrace($profile->player_id));
}

function nakedWallFirstProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 41_000 + $playerId,
        'enabled' => true,
    ]);
}

/** A homeworld that already holds a wall and every opening role, so the sibling's floor is what is left. */
function nakedWallFirstHome(PlanetService $home): void
{
    $home->setObjectLevel(ObjectService::getObjectByMachineName('robot_factory')->id, 2, true);
    $home->setObjectLevel(ObjectService::getObjectByMachineName('shipyard')->id, 4, true);
    $home->setObjectLevel(ObjectService::getObjectByMachineName('solar_plant')->id, 30, true);
    $home->updateResourceProductionStats();
    $home->updateResourceStorageStats();
    $home->addUnit('rocket_launcher', 1_500);
    nakedWallFirstOpeningFleet($home);
    $home->addResources(new Resources(50_000_000, 50_000_000, 50_000_000));
}

/** A colony that mines nothing and stands no defence: the planet the cohort read found naked. */
function nakedWallFirstColony(?PlanetService $colony): PlanetService
{
    $colony = $colony ?? throw new LogicException('the account must own a second planet for this story.');

    foreach (['metal_mine', 'crystal_mine', 'deuterium_synthesizer'] as $machineName) {
        $colony->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, 0, true);
    }

    $colony->setObjectLevel(ObjectService::getObjectByMachineName('solar_plant')->id, 30, true);
    $colony->setObjectLevel(ObjectService::getObjectByMachineName('shipyard')->id, 4, true);
    $colony->updateResourceProductionStats();
    $colony->updateResourceStorageStats();
    $colony->addResources(new Resources(50_000_000, 50_000_000, 50_000_000));
    nakedWallFirstOpeningFleet($colony);

    return $colony;
}

/**
 * A planet that already stands a wall and whose economy produces: a big one covers what it produces, a
 * one-unit one does not. Both planets of the account walled means no sibling is naked, so the pass above
 * has nothing to do and only the planet still short of its wall wants an order.
 */
function nakedWallFirstWalledPlanet(PlanetService $planet, int $wall): void
{
    foreach (['robot_factory' => 2, 'shipyard' => 4, 'solar_plant' => 30, 'metal_mine' => 20, 'crystal_mine' => 18, 'deuterium_synthesizer' => 12] as $machineName => $level) {
        $planet->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, $level, true);
    }

    $planet->updateResourceProductionStats();
    $planet->updateResourceStorageStats();
    $planet->addUnit('rocket_launcher', $wall);
    nakedWallFirstOpeningFleet($planet);
    $planet->addResources(new Resources(5_000_000_000, 5_000_000_000, 5_000_000_000));
}

function nakedWallFirstOpeningFleet(PlanetService $planet): void
{
    $planet->addUnit('small_cargo', 1);
    $planet->addUnit('colony_ship', 1);
    $planet->addUnit('espionage_probe', 1);
}

/** A trace whose session chose the yard, with the economy step still on offer behind it. */
function nakedWallFirstTrace(int $playerId): DecisionTrace
{
    $selected = nakedWallFirstCandidate(AiCandidateActionType::QueueUnits);
    $build = nakedWallFirstCandidate(AiCandidateActionType::Build);

    return app()->makeWith(DecisionTrace::class, [
        'perception' => app()->makeWith(PerceptionSnapshot::class, [
            'playerId' => $playerId,
            'observedAt' => Carbon\CarbonImmutable::instance(now()),
            'planets' => [],
            'targetReports' => [],
            'availableActions' => [],
            'fleetsaveEligible' => false,
            'recoveryFactor' => 0.0,
            'sourceTimestamps' => [],
            'inboundFleets' => [],
        ]),
        'candidates' => [$selected, $build],
        'selected' => $selected,
        'rejections' => [],
        'inputHash' => 'naked-wall-first-fixture',
    ]);
}

function nakedWallFirstCandidate(AiCandidateActionType $type): ScoredCandidate
{
    return app()->makeWith(ScoredCandidate::class, [
        'candidate' => app()->makeWith(CandidateAction::class, [
            'type' => $type,
            'reason' => 'naked-wall-first-fixture',
            'parameters' => [],
            'features' => ['resource_need' => 0.0, 'safety' => 0.0, 'target_confidence' => 0.0, 'travel_cost' => 0.0, 'recovery' => 0.0],
            'sourceTimestamps' => [],
        ]),
        'score' => 1.0,
        'components' => [],
    ]);
}
