<?php

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// DISPATCH_REFUSALS: a refused dispatch is not a mission, so until the first refusal of a batch lands
// nothing the host holds remembers the fleet already on its way to a target (measured 4 Oct 2026: one
// account's target refused seven times in six hours for being active). The raid lane is the one that
// plans per report, and a probe volley writes one report per probe: the farm with two reports is the
// story, and its coordinate has to hold one fleet however many reports name it.

/** The raid story the two cases share: an undefended inactive, two reports on it, cargo and a kill ship. */
function oneRaidPerTargetSeries(object $test): Situation
{
    return Situation::of($test)
        ->archetype(AiArchetype::Fleeter)
        ->research('combustion_drive', 6)
        ->ships('large_cargo', 20)
        ->ships('light_fighter', 10)
        ->resources(200_000, 200_000, 500_000)
        ->inactiveNeighbour(daysQuiet: 10, metal: 400_000, crystal: 200_000)
        ->spyReport()
        ->spyReport();
}

test('two reports of one neighbour are one raid, not a queue of fleets', function (): void {
    // The login decides and nothing has flown yet, so an intent is the only trace of the fleet it placed.
    oneRaidPerTargetSeries($this)->session(rounds: 0);

    $raidTargets = AiWorkItem::query()
        ->where('player_id', $this->currentUserId)
        ->where('kind', AiWorkKind::Raid)
        ->get(['payload'])
        ->map(static fn (AiWorkItem $item): string => ($item->payload['target_galaxy'] ?? '?') . ':' . ($item->payload['target_system'] ?? '?') . ':' . ($item->payload['target_position'] ?? '?'));

    expect($raidTargets)->toHaveCount(1);
});

// At the bound from the other side: holding the target must not park the fleet, so the raid the login
// decided still leaves when its intent comes due.
test('the raid the login decided still flies once its intent is executed', function (): void {
    oneRaidPerTargetSeries($this)->session(rounds: 0)->session()->expectMission('Attack');
});
