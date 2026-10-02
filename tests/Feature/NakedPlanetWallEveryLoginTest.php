<?php

use Modules\AI\Actions\ScheduleAiIntentAction;
use Modules\AI\Domain\Decision\CandidateAction;
use Modules\AI\Domain\Decision\DecisionTrace;
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

// QUAL-003: one planet at zero defence stayed beside a sibling's wall on the live cohort. The wall is
// only one of a dozen candidates, so the logins that chose the economy's habit never placed it. The first
// wall of a bare planet beside a walled sibling is placed on every login, whichever action the engine
// chose; an ordinary wall order still waits for the login that chose the yard.

test('a session that chose to build still places the bare planet\'s wall', function (): void {
    $profile = wallEveryLoginProfile($this->currentUserId);
    wallEveryLoginHome($this->planetService);
    $colony = wallEveryLoginColony($this->secondPlanetService);

    wallEveryLoginSchedule($profile, AiCandidateActionType::Build);

    $units = AiWorkItem::query()->where('player_id', $profile->player_id)->where('kind', AiWorkKind::QueueUnits)->first();

    expect($units)->not->toBeNull()
        ->and($units->payload['planet_id'])->toBe($colony->getPlanetId())
        ->and($units->payload['reason'])->toContain('role:defense:standing');
});

test('a session that chose to build places no wall when the planet already stands defence', function (): void {
    $profile = wallEveryLoginProfile($this->currentUserId);
    // Both planets stand a wall, so no sibling is bare: only a planet still short of its wall wants an
    // order, and that order is not the one whose placement decides whether it happens.
    wallEveryLoginWalledPlanet($this->planetService, 1);
    wallEveryLoginWalledPlanet($this->secondPlanetService, 1_500);

    wallEveryLoginSchedule($profile, AiCandidateActionType::Build);

    expect(AiWorkItem::query()->where('player_id', $profile->player_id)->where('kind', AiWorkKind::QueueUnits)->exists())->toBeFalse();
});

/** Runs the real schedule step for a session that chose $type. */
function wallEveryLoginSchedule(AiProfile $profile, AiCandidateActionType $type): void
{
    $session = AiWorkItem::create([
        'player_id' => $profile->player_id,
        'kind' => AiWorkKind::RunSession,
        'due_at' => now(),
        'idempotency_key' => 'wall-every-login:' . $profile->player_id . ':' . $type->value,
        'state' => AiWorkState::Pending,
    ]);

    app(ScheduleAiIntentAction::class)->handle($profile, $session, wallEveryLoginTrace($profile->player_id, $type));
}

function wallEveryLoginProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 51_000 + $playerId,
        'enabled' => true,
    ]);
}

/** A homeworld that already holds a wall and every opening role, so the bare sibling is what is left. */
function wallEveryLoginHome(PlanetService $home): void
{
    $home->setObjectLevel(ObjectService::getObjectByMachineName('robot_factory')->id, 2, true);
    $home->setObjectLevel(ObjectService::getObjectByMachineName('shipyard')->id, 4, true);
    $home->setObjectLevel(ObjectService::getObjectByMachineName('solar_plant')->id, 30, true);
    $home->updateResourceProductionStats();
    $home->updateResourceStorageStats();
    $home->addUnit('rocket_launcher', 1_500);
    wallEveryLoginOpeningFleet($home);
    $home->addResources(new Resources(50_000_000, 50_000_000, 50_000_000));
}

/** A colony that mines nothing and stands no defence: the planet the cohort read found naked. */
function wallEveryLoginColony(?PlanetService $colony): PlanetService
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
    wallEveryLoginOpeningFleet($colony);

    return $colony;
}

function wallEveryLoginWalledPlanet(PlanetService $planet, int $wall): void
{
    foreach (['robot_factory' => 2, 'shipyard' => 4, 'solar_plant' => 30, 'metal_mine' => 20, 'crystal_mine' => 18, 'deuterium_synthesizer' => 12] as $machineName => $level) {
        $planet->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, $level, true);
    }

    $planet->updateResourceProductionStats();
    $planet->updateResourceStorageStats();
    $planet->addUnit('rocket_launcher', $wall);
    wallEveryLoginOpeningFleet($planet);
    $planet->addResources(new Resources(5_000_000_000, 5_000_000_000, 5_000_000_000));
}

function wallEveryLoginOpeningFleet(PlanetService $planet): void
{
    $planet->addUnit('small_cargo', 1);
    $planet->addUnit('colony_ship', 1);
    $planet->addUnit('espionage_probe', 1);
}

/** A trace whose session chose $type, with the other candidates the engine ranked beside it. */
function wallEveryLoginTrace(int $playerId, AiCandidateActionType $type): DecisionTrace
{
    $selected = wallEveryLoginCandidate($type);
    $other = wallEveryLoginCandidate($type === AiCandidateActionType::Build ? AiCandidateActionType::QueueUnits : AiCandidateActionType::Build);

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
        'candidates' => [$selected, $other],
        'selected' => $selected,
        'rejections' => [],
        'inputHash' => 'wall-every-login-fixture',
    ]);
}

function wallEveryLoginCandidate(AiCandidateActionType $type): ScoredCandidate
{
    return app()->makeWith(ScoredCandidate::class, [
        'candidate' => app()->makeWith(CandidateAction::class, [
            'type' => $type,
            'reason' => 'wall-every-login-fixture',
            'parameters' => [],
            'features' => ['resource_need' => 0.0, 'safety' => 0.0, 'target_confidence' => 0.0, 'travel_cost' => 0.0, 'recovery' => 0.0],
            'sourceTimestamps' => [],
        ]),
        'score' => 1.0,
        'components' => [],
    ]);
}
