<?php

/**
 * WIK-019: the def-turtle vault scenario is the single place the slot-8 claim is recorded, and it
 * is recorded as a non-actionable record. Modules/AI has no scenario loader yet (plan OPEN
 * QUESTION), so the loader contract is fixed here: only `documented` reaches the planner, and only
 * entries that carry a rule body can become build orders.
 */

function defTurtleVaultScenarioPath(): string
{
    return __DIR__ . '/../../resources/scenarios/def-turtle-vault.json';
}

function defTurtleVaultScenario(): array
{
    $contents = file_get_contents(defTurtleVaultScenarioPath());

    expect($contents)->not->toBeFalse();

    $scenario = json_decode((string) $contents, true, 512, JSON_THROW_ON_ERROR);

    expect($scenario)->toBeArray();

    return $scenario;
}

/**
 * A slot-8 reference at any depth, so a claim leaking out of `contested` is found wherever it is
 * nested rather than only at a key the test happens to know about.
 */
function referencesSlotEight(mixed $value): bool
{
    if (is_array($value)) {
        foreach ($value as $key => $nested) {
            if (referencesSlotEight($key) || referencesSlotEight($nested)) {
                return true;
            }
        }

        return false;
    }

    if (! is_string($value)) {
        return false;
    }

    return preg_match('/slot[\s_-]*8\b/i', $value) === 1;
}

it('documents the vault definition and the mine-efficiency rule', function (): void {
    $scenario = defTurtleVaultScenario();

    expect($scenario['documented']['vault']['definition'] ?? null)
        ->toBeString()
        ->not->toBe('');

    expect($scenario['documented']['mine_efficiency']['rule'] ?? null)
        ->toBeString()
        ->not->toBe('');
});

it('keeps the slot-8 item under contested with its confidence and no rule body', function (): void {
    $scenario = defTurtleVaultScenario();

    $sectionsWithSlotEight = array_values(array_filter(
        array_keys($scenario),
        fn (string $section): bool => referencesSlotEight($scenario[$section]),
    ));

    expect($sectionsWithSlotEight)->toBe(['contested']);

    $items = array_values(array_filter(
        $scenario['contested'],
        fn (array $item): bool => ($item['id'] ?? null) === 'slot-8',
    ));

    expect($items)->toHaveCount(1);

    expect($items[0]['confidence'] ?? null)->toBe('low');
    expect($items[0])->not->toHaveKey('rule');
    expect($items[0])->not->toHaveKey('build_order');
    expect($items[0]['actionable'] ?? null)->toBeFalse();
});

it('derives no slot-8 build order from the scenario', function (): void {
    $scenario = defTurtleVaultScenario();

    // The scan is not vacuous: the very same check does flag the contested claim.
    expect(referencesSlotEight($scenario['contested']))->toBeTrue();
    expect(referencesSlotEight($scenario['documented']))->toBeFalse();

    $buildOrders = array_values(array_filter(
        $scenario['documented'],
        fn (array $entry): bool => array_key_exists('rule', $entry),
    ));

    expect($buildOrders)->not->toBeEmpty();

    foreach ($buildOrders as $buildOrder) {
        expect(referencesSlotEight($buildOrder))->toBeFalse();
    }
});

it('does not treat a contested claim without a rule body as a build order', function (): void {
    $scenario = defTurtleVaultScenario();

    $actionable = array_filter(
        $scenario['contested'],
        fn (array $item): bool => array_key_exists('rule', $item),
    );

    expect(array_values($actionable))->toBe([]);
});

it('flags slot 8 itself and not neighbouring slot numbers', function (): void {
    expect(referencesSlotEight('slot-8'))->toBeTrue();
    expect(referencesSlotEight('slot 8'))->toBeTrue();
    expect(referencesSlotEight('slot8'))->toBeTrue();
    expect(referencesSlotEight(['id' => 'Slot_8']))->toBeTrue();

    expect(referencesSlotEight('slot 18'))->toBeFalse();
    expect(referencesSlotEight('slot 80'))->toBeFalse();
    expect(referencesSlotEight('slot 3'))->toBeFalse();
    expect(referencesSlotEight(8))->toBeFalse();
});
