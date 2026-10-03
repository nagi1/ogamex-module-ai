<?php

use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * ARCH-INTEL (architecture step 7): a galaxy threat map and per-target priority counters.
 *
 * The raid blacklist is the only adaptation today: nothing remembers that a target paid well or cost
 * ships, and no manager reads a per-system threat/opportunity score (colony placement, save
 * destinations and probe batches all decide without one). This is the red spec for the two pieces.
 *
 * RED until `IntelBook` and `GalaxyMap` exist.
 */
test('a profitable raid raises a target priority and a loss lowers it', function (): void {
    $book = app(\Modules\AI\Domain\Intel\IntelBook::class);

    $before = $book->priority($this->currentUserId, '1:1:9');
    $book->recordOutcome($this->currentUserId, '1:1:9', true);
    $afterWin = $book->priority($this->currentUserId, '1:1:9');
    $book->recordOutcome($this->currentUserId, '1:1:9', false);
    $afterLoss = $book->priority($this->currentUserId, '1:1:9');

    expect($afterWin)->toBeGreaterThan($before)
        ->and($afterLoss)->toBeLessThan($afterWin);
});

test('the galaxy map scores a system for threat and opportunity', function (): void {
    $map = app(\Modules\AI\Domain\Galaxy\GalaxyMap::class);

    expect($map->opportunity($this->currentUserId, 1, 1))->toBeGreaterThanOrEqual(0)
        ->and($map->threat($this->currentUserId, 1, 1))->toBeGreaterThanOrEqual(0);
});
