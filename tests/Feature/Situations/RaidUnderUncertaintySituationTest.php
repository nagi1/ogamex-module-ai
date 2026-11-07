<?php

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Tests\Support\RaidStory;
use Modules\AI\Tests\Support\Situation;
use OGame\Models\Highscore;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';
require_once __DIR__ . '/../../Support/RaidStory.php';

uses(IsolatedAccountTestCase::class);

// War world (WORK-ORDER §3, PHP 5): attack under uncertainty. A report too thin to read the fleet and the defence is
// not a refusal: the hidden sections are estimated from the owner's public military points the report does not
// explain (ReportedPlanet::estimated), the fight is forecast on that estimate, and the raider's loss tolerance decides.

/**
 * A raider with a war fleet, and an active rival away from the keyboard holding stock, whose public military points are
 * $militaryPoints, with the account's own report that read neither its fleet nor its defence.
 *
 * @return array{0: Situation, 1: PlanetService}
 */
function uncertaintyStory(object $test, int $militaryPoints, AiArchetype $archetype = AiArchetype::Raider): array
{
    $situation = Situation::of($test)
        ->archetype($archetype)
        ->research('combustion_drive', 8)
        ->research('weapon_technology', 6)
        ->research('shielding_technology', 6)
        ->research('armor_technology', 6)
        ->ships('large_cargo', 60)
        ->ships('light_fighter', 300)
        ->ships('cruiser', 60)
        ->resources(500_000, 500_000, 1_000_000)
        ->colony();
    Highscore::query()->updateOrCreate(['player_id' => (fn (): int => $this->currentUserId)->call($test)], ['general' => 2_000_000, 'military_built' => 1_500]);
    $rival = RaidStory::farm($test, daysQuiet: 2, metal: 600_000, crystal: 300_000);
    Highscore::query()->updateOrCreate(['player_id' => $rival->getPlayer()->getId()], ['general' => 600_000, 'military_built' => $militaryPoints]);
    RaidStory::report($test, $rival, hideShips: true);

    return [$situation, $rival];
}

test('a thin report on a rival with few military points is raided on the estimate', function (): void {
    [$situation] = uncertaintyStory($this, militaryPoints: 20);

    $situation->session()->expectMission('Attack');
});

test('a thin report on a rival whose military points dwarf the fleet is not raided, though its planet stands empty', function (): void {
    [$situation] = uncertaintyStory($this, militaryPoints: 200_000);

    $situation->session()->expectNoMission('Attack');
});

// The estimate leaves a fight the fleet wins at a cost: the raider takes the bad tail for the loot, a miner does not
// (RaidPlanner::lossTolerance). The points sit between the two tolerances on this fleet.
test('on an estimate that costs ships a raider attacks and a miner holds back', function (AiArchetype $archetype, bool $attacks): void {
    [$situation] = uncertaintyStory($this, militaryPoints: 1_300, archetype: $archetype);

    $attacks ? $situation->session()->expectMission('Attack') : $situation->session()->expectNoMission('Attack');
})->with([
    'raider' => [AiArchetype::Raider, true],
    'miner' => [AiArchetype::Miner, false],
]);

// No cheating (rule 10): what stands on the rival's planet is exactly what the report did not read. A wall the account
// cannot see does not stop the raid it would fly on the estimate; it meets the wall at the fight.
test('a hidden wall the report did not read leaves the raid decision unchanged', function (): void {
    [$situation, $rival] = uncertaintyStory($this, militaryPoints: 20);
    $rival->addUnit('plasma_turret', 200);
    $rival->addUnit('gauss_cannon', 500);

    $situation->session()->expectMission('Attack');
});
