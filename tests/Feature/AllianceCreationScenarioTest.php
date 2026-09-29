<?php

use Illuminate\Foundation\Application;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class AllianceCreationScenarioTestCase extends IsolatedAccountTestCase
{
    private string $statusesFile;

    public function createApplication(): Application
    {
        $trackedFile = dirname(__DIR__, 4) . '/modules_statuses.json';
        $statuses = json_decode((string) file_get_contents($trackedFile), true);
        $statuses['AI'] = true;
        $this->statusesFile = sys_get_temp_dir() . '/modules_statuses_' . uniqid('', true) . '.json';
        file_put_contents($this->statusesFile, json_encode($statuses, JSON_PRETTY_PRINT));
        putenv('MODULES_STATUSES_FILE=' . $this->statusesFile);

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        if (is_file($this->statusesFile)) {
            unlink($this->statusesFile);
        }

        putenv('MODULES_STATUSES_FILE');

        // The slot registry is static, so a test that registered the module's nav link would
        // leak it into the next one.
        ModuleSlotService::resetSlots();

        parent::tearDown();
    }
}

uses(AllianceCreationScenarioTestCase::class);

it('keeps the alliance creation checklist unverified and free of numbers', function () {
    $scenarioPath = dirname(__DIR__, 2) . '/resources/scenarios/alliance-creation.json';

    expect(is_file($scenarioPath))->toBeTrue();

    $scenario = json_decode((string) file_get_contents($scenarioPath), true, flags: JSON_THROW_ON_ERROR);

    expect($scenario)->toBeArray();

    // Walks every leaf so a threshold smuggled anywhere in the document is reported by path.
    $violations = function (array $data, string $prefix = '') use (&$violations): array {
        $found = [];

        foreach ($data as $key => $value) {
            $field = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (preg_match('/(^|_)id$/i', (string) $key) === 1) {
                $found[] = $field;

                continue;
            }

            if (is_array($value)) {
                $found = array_merge($found, $violations($value, $field));

                continue;
            }

            if (is_int($value) || is_float($value) || preg_match('/\d/', (string) $value) === 1) {
                $found[] = $field;
            }
        }

        return $found;
    };

    // The scanner has to bite, or the assertions below would pass on any file at all.
    expect($violations(['founding_cost' => 100]))->toBe(['founding_cost']);
    expect($violations(['player_id' => 'any']))->toBe(['player_id']);
    expect($violations(['question' => 'wait two days']))->toBe([]);

    expect($violations($scenario))->toBe([]);

    expect($scenario['name'] ?? null)->toBe('alliance-creation');
    expect($scenario['situation'] ?? '')->toBeString()->not->toBe('');
    expect($scenario['status'] ?? null)->toBe('unverified');
    expect($scenario['checklist'] ?? [])->toBeArray()->not->toBeEmpty();

    $statuses = [];

    foreach ($scenario['checklist'] as $index => $entry) {
        expect($entry)->toBeArray();
        expect($entry['topic'] ?? '')->toBeString()->not->toBe('');
        expect($entry['question'] ?? '')->toBeString()->not->toBe('');
        expect($violations($entry))->toBe([], 'checklist entry ' . $index . ' carries a number or an identifier');

        $statuses[] = $entry['status'] ?? null;
    }

    expect(array_unique($statuses))->toBe(['unverified']);
});
