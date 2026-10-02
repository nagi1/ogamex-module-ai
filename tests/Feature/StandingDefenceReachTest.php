<?php

use Modules\AI\Domain\Decision\DefenseNeed;
use Modules\AI\Domain\Decision\DefenseNeedEvaluator;
use Modules\AI\Domain\Decision\QueueableUnit;
use Modules\AI\Domain\Decision\QueueableUnitPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Symfony\Component\Yaml\Yaml;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// QUAL-003: the account held a wall on one planet and left its siblings naked. A colony's own output
// is near zero, and need was derived from exposure alone, so the exposure clock never asked it for a
// first unit. The floor from defence-doctrines.yaml now answers a planet with nothing at all.

test('a planet at zero defence is offered the minimum deterrent even with no production', function (): void {
    standingReachProfile($this->currentUserId);
    $colony = standingReachBare($this->secondPlanetService);

    $need = app(DefenseNeedEvaluator::class)->evaluate(standingReachPlayer($this->currentUserId), $colony);

    expect($need)->toBeInstanceOf(DefenseNeed::class)
        ->and($need->defenceValue)->toBe(standingReachFloor())
        ->and($need->reason)->toBe('defense:need:minimum-deterrent');
});

test('one unit of defence ends the floor and exposure decides again', function (): void {
    standingReachProfile($this->currentUserId);
    $colony = standingReachBare($this->secondPlanetService);
    $colony->addUnit('rocket_launcher', 1);

    // The floor is only for a planet with nothing, so a planet that already holds one unit is
    // weighed by what it stands to lose, which on a silent colony is nil.
    expect(app(DefenseNeedEvaluator::class)->evaluate(standingReachPlayer($this->currentUserId), $colony))->toBeNull();
});

test('the naked colony beside a walled homeworld gets the standing order', function (): void {
    standingReachProfile($this->currentUserId);

    $this->planetAddResources(new Resources(50_000_000, 50_000_000, 50_000_000));
    $this->planetAddUnit('gauss_cannon', 5);
    standingReachOpeningFleet($this->planetService);

    $colony = standingReachBare($this->secondPlanetService);
    $colony->addResources(new Resources(50_000_000, 50_000_000, 50_000_000));
    standingReachOpeningFleet($colony);

    $plan = app(QueueableUnitPlanner::class)->plan($this->currentUserId);

    expect($plan)->toBeInstanceOf(QueueableUnit::class)
        ->and($plan->reason)->toContain('role:defense:standing')
        ->and($plan->planetId)->toBe($colony->getPlanetId());
});

function standingReachProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 17_000 + $playerId,
        'enabled' => true,
    ]);
}

function standingReachPlayer(int $playerId): PlayerService
{
    return app(PlayerServiceFactory::class)->make($playerId, true);
}

/** The floor the behaviour file states, so the test reads the policy instead of restating it. */
function standingReachFloor(): float
{
    $parsed = Yaml::parseFile(module_path('AI', 'resources/behavior/defence-doctrines.yaml'));

    return (float) $parsed['minimum_deterrent']['value'];
}

/**
 * A colony that mines nothing and stands no defence: its own exposure is zero, which is exactly the
 * planet the cohort read found naked beside a walled sibling. Mining levels are taken to zero and the
 * production columns refreshed, because the planner reads the persisted figures.
 */
function standingReachBare(?PlanetService $colony): PlanetService
{
    $colony = $colony ?? throw new LogicException('the account must own a second planet for this story.');

    // A speed-1 universe, the one the cohort read was taken on: the test case sets economy_speed 8,
    // where the host's base income alone exposes a silent colony above the floor (5712 vs 4000).
    resolve(SettingsService::class)->set('economy_speed', 1);

    foreach (['metal_mine', 'crystal_mine', 'deuterium_synthesizer'] as $machineName) {
        $colony->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, 0, true);
    }

    $colony->setObjectLevel(ObjectService::getObjectByMachineName('solar_plant')->id, 30, true);
    $colony->setObjectLevel(ObjectService::getObjectByMachineName('shipyard')->id, 4, true);
    $colony->updateResourceProductionStats();
    $colony->updateResourceStorageStats();

    return $colony;
}

/** A planet whose opening roles are satisfied, so the standing wall is what the planner is left with. */
function standingReachOpeningFleet(PlanetService $planet): void
{
    $planet->addUnit('small_cargo', 1);
    $planet->addUnit('colony_ship', 1);
    $planet->addUnit('espionage_probe', 1);
}
