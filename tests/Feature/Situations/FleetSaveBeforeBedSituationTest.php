<?php

use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// FLEET-001: an experienced player moves a fleet worth losing out of reach before a long night offline.
// The reactive save needs an attack to land inside a session; this is the save the cohort should make
// every night, and the cohort made one in its lifetime.
test('a fleet worth losing is saved at the last login before the night', function (): void {
    Situation::of($this)
        ->archetype(AiArchetype::Fleeter)
        ->resources(500_000, 300_000, 200_000)
        ->ships('light_fighter', 400)
        ->ships('cruiser', 60)
        ->crowd(10)
        ->colony()
        ->beforeBed()
        ->session()
        ->expectWork(AiWorkKind::FleetSave);
});

test('a login in the middle of the day does not save the fleet', function (): void {
    Situation::of($this)
        ->archetype(AiArchetype::Fleeter)
        ->resources(500_000, 300_000, 200_000)
        ->ships('light_fighter', 400)
        ->ships('cruiser', 60)
        ->crowd(10)
        ->colony()
        ->awake()
        ->session()
        ->expectNoWork(AiWorkKind::FleetSave);
});

// The aspect without the wait: a whole simulated day of the account's own logins, and the night in it
// is when the fleet is saved. No live cohort, no hour of real play.
test('an account that plays a whole day saves its fleet before its night', function (): void {
    Situation::of($this)
        ->archetype(AiArchetype::Fleeter)
        ->resources(500_000, 300_000, 200_000)
        ->ships('light_fighter', 400)
        ->ships('cruiser', 60)
        ->crowd(10)
        ->colony()
        ->day(24)
        ->expectWork(AiWorkKind::FleetSave);
});
