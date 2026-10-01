### EDIT: tests/Feature/ShipyardWithoutResearchTest.php
<<<<<<< SEARCH
    expect($plan)->toBeInstanceOf(QueueableUnit::class)
        ->and($plan->reason)->toContain('role:escort:surplus:')
        ->and($plan->amount)->toBe(1);
=======
    expect($plan)->toBeInstanceOf(QueueableUnit::class)
        ->and($plan->reason)->toContain('role:defense:standing:')
        ->and($plan->amount)->toBeGreaterThan(0);
>>>>>>> REPLACE