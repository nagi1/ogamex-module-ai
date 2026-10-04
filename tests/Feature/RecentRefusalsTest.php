<?php

use Modules\AI\Domain\Decision\RecentRefusals;
use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Models\AiActionReceipt;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

function refuseDispatch(int $playerId, array $result, string $minutesAgo = '0 minutes'): void
{
    app(AiActionReceipt::class)->forceFill([
        'player_id' => $playerId,
        'idempotency_key' => 'test:' . uniqid(),
        'action_type' => AiActionType::DispatchFleet,
        'state' => AiReceiptState::Rejected,
        'result' => $result,
        'created_at' => now()->modify('-' . $minutesAgo),
        'updated_at' => now(),
    ])->save();
}

// A refused dispatch is not a mission, so the planners never saw it and offered the same target again (21 times in six hours).
test('a target the gate just refused is remembered for a while, only for that account and that target', function (): void {
    refuseDispatch($this->currentUserId, ['reason' => 'target_active_at_dispatch', 'planet_id' => 7, 'decision' => ['target_galaxy' => 1, 'target_system' => 22, 'target_position' => 4]]);
    $refusals = app(RecentRefusals::class);

    expect($refusals->target($this->currentUserId, 1, 22, 4))->toBeTrue()
        ->and($refusals->target($this->currentUserId, 1, 22, 5))->toBeFalse()
        ->and($refusals->target($this->currentUserId + 1, 1, 22, 4))->toBeFalse();
});

test('a refusal is forgotten once the window passes', function (): void {
    refuseDispatch($this->currentUserId, ['reason' => 'target_active_at_dispatch', 'planet_id' => 7, 'decision' => ['target_galaxy' => 1, 'target_system' => 22, 'target_position' => 4]], '10 hours');

    expect(app(RecentRefusals::class)->target($this->currentUserId, 1, 22, 4))->toBeFalse();
});

// DISPATCH_REFUSALS counts a repeat of the same account+target+reason inside six hours, so the cooling
// has to outlast that span: a refusal four hours old still says the target is not to be offered again.
test('a refusal inside the cooling the invariant measures is still remembered', function (): void {
    refuseDispatch($this->currentUserId, ['reason' => 'target_active_at_dispatch', 'planet_id' => 7, 'decision' => ['target_galaxy' => 1, 'target_system' => 22, 'target_position' => 4]], '4 hours');

    expect(app(RecentRefusals::class)->target($this->currentUserId, 1, 22, 4))->toBeTrue();
});

test('an origin the gate found short is remembered by planet and reason', function (): void {
    refuseDispatch($this->currentUserId, ['reason' => 'source_short_at_dispatch', 'planet_id' => 7, 'decision' => []]);
    $refusals = app(RecentRefusals::class);

    expect($refusals->origin($this->currentUserId, 7, 'source_short_at_dispatch'))->toBeTrue()
        ->and($refusals->origin($this->currentUserId, 8, 'source_short_at_dispatch'))->toBeFalse()
        ->and($refusals->origin($this->currentUserId, 7, 'no_disposable_fleet'))->toBeFalse();
});

// A queued decision runs hours after it was written, so the worker asks the memory once more before it asks the gate.
test('an intent naming a refused target or a refused origin is answered by the memory, a target-side refusal never blames the origin', function (): void {
    refuseDispatch($this->currentUserId, ['reason' => 'target_active_at_dispatch', 'planet_id' => 7, 'decision' => ['target_galaxy' => 1, 'target_system' => 22, 'target_position' => 4]]);
    refuseDispatch($this->currentUserId, ['reason' => 'source_short_at_dispatch', 'planet_id' => 9, 'decision' => []]);
    $refusals = app(RecentRefusals::class);

    expect($refusals->cools($this->currentUserId, 8, 1, 22, 4))->toBeTrue()
        ->and($refusals->cools($this->currentUserId, 9, 1, 30, 1))->toBeTrue()
        ->and($refusals->cools($this->currentUserId, 7, 1, 30, 1))->toBeFalse();
});
