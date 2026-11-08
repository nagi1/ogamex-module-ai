<?php

use Modules\AI\Tests\Support\Situation;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// Live 8 Nov 2026, account 7: a goal saver held its whole pile for a metal store it could not yet pay, so its mines ran
// at 65% all morning for want of an 854-metal solar plant. A saver still feeds its mines: every hour they run slow delays
// the goal itself.
test('a goal saver whose mines are short of energy builds the power plant before it saves', function (): void {
    // The play universe's speed: there a store fills inside one absence, so the saver's goal is the next store.
    app(SettingsService::class)->set('economy_speed', 1000);
    $situation = Situation::of($this)->goalSaver()
        ->level('metal_mine', 10)
        ->level('crystal_mine', 4)
        ->level('deuterium_synthesizer', 1)
        ->level('solar_plant', 6)
        ->level('metal_store', 9)
        ->level('crystal_store', 7)
        ->resources(210_000, 126_000, 20_000)
        ->session();

    expect($situation->queued())->toContain('solar_plant');
});
