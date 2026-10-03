<?php

use Illuminate\Support\Facades\DB;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// PERS-008: an archetype states how an account expects to grow, never whether it grows. Every
// growth archetype carries a build preference, so a raider or a fleeter keeps a rising economy
// while pressing its own errand harder. The weights live in
// resources/behavior/archetype-preferences.yaml and these stories read the account's own session.

test('a raider with stock fills its build queue on a login', function (): void {
    $situation = Situation::of($this)->archetype(AiArchetype::Raider)->resources(1_000_000, 1_000_000, 1_000_000)->session();

    $situation->expectWork(AiWorkKind::BuildFirstBuilding)->expectBuildingQueueBusy();
});

// Trader and Casual are kept for stored profiles and never renumbered, so a profile still holding
// one has to play: it grows like the archetype its behaviour was migrated into, which is the
// economic role for a trader and the activity band for a casual.
test('a stored casual profile grows like the hybrid its behaviour migrated into', function (): void {
    Situation::of($this)->archetype(AiArchetype::Casual)->resources(1_000_000, 1_000_000, 1_000_000)->session()
        ->expectWork(AiWorkKind::BuildFirstBuilding);
});

test('a stored trader profile grows like the miner its behaviour migrated into', function (): void {
    Situation::of($this)->archetype(AiArchetype::Trader)->resources(1_000_000, 1_000_000, 1_000_000)->session()
        ->expectWork(AiWorkKind::BuildFirstBuilding);
});

test('a raider weights the mine below a miner and not at zero', function (): void {
    // One account, two logins: the profile changes archetype between them, so the weight difference
    // is the archetype's alone and the account's own stock and planets stay the same.
    $situation = Situation::of($this)->resources(1_000_000, 1_000_000, 1_000_000)->session();
    $miner = archetypePreference($this->currentUserId, 'Build');

    $raider = $situation->archetype(AiArchetype::Raider)->session();
    $raiderPreference = archetypePreference($this->currentUserId, 'Build');

    expect($raiderPreference)->toBeGreaterThan(0.0, 'expected the raider to weight building at all; ' . $raider->account())
        ->and($raiderPreference)->toBeLessThan($miner, 'expected the raider to weight building below the miner; ' . $raider->account());
});

/** The archetype weight the account's last recorded decision gave one action, 0.0 when it ranked none. */
function archetypePreference(int $playerId, string $action): float
{
    $ranked = json_decode((string) DB::table('ai_decision_traces')->where('player_id', $playerId)->orderByDesc('id')->value('candidates'), true) ?: [];

    foreach ($ranked as $candidate) {
        if (($candidate['action'] ?? '') === $action) {
            return (float) ($candidate['components']['archetype_preference'] ?? 0.0);
        }
    }

    return 0.0;
}
