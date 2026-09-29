<?php

use Illuminate\Foundation\Application;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

/**
 * The plan filed this check under tests/Unit, where this module's harness never collects it and the
 * documented acceptance command cannot see it fail. It lives in tests/Feature so the guard over the
 * spec note actually runs.
 *
 * No scenario fixture ships with the note: the position concept produces no decision, so a scenario
 * would lock in whatever the engine answers today rather than the rule this page states.
 */
class PositionConceptSpecTestCase extends IsolatedAccountTestCase
{
    public const SPEC_PATH = 'plan/specs/position-concept.md';

    // The range is the one the plan's acceptance line states — a single slot digit, or the lower
    // teens. A literal above that range is outside what this guard claims to catch.
    public const SLOT_LITERAL_PATTERN = '/\b(1[0-5]|[1-9])\b/';

    private string $statusesFile = '';

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

        // The slot registry is static, so a test that registered the module's nav link would leak
        // it into the next one.
        ModuleSlotService::resetSlots();

        parent::tearDown();
    }

    public static function specPath(): string
    {
        return dirname(__DIR__, 2) . '/' . self::SPEC_PATH;
    }
}

uses(PositionConceptSpecTestCase::class);

it('ships the position concept note under plan/specs', function () {
    $path = PositionConceptSpecTestCase::specPath();

    expect(is_file($path))->toBeTrue();

    expect((string) file_get_contents($path))->not->toBe('');
});

it('carries the WIK-193 provenance line', function () {
    $contents = (string) file_get_contents(PositionConceptSpecTestCase::specPath());

    expect($contents)->toContain('WIK-193');
    expect($contents)->toMatch('/^## Provenance$/m');
});

it('states no slot literal anywhere in the note', function () {
    $contents = (string) file_get_contents(PositionConceptSpecTestCase::specPath());

    expect(preg_match(PositionConceptSpecTestCase::SLOT_LITERAL_PATTERN, $contents))->toBe(0);
});

it('bites when a slot literal is written into the note', function () {
    expect(preg_match(PositionConceptSpecTestCase::SLOT_LITERAL_PATTERN, 'planet slot 7'))->toBe(1);
    expect(preg_match(PositionConceptSpecTestCase::SLOT_LITERAL_PATTERN, 'slot 15, the outer edge'))->toBe(1);
    expect(preg_match(PositionConceptSpecTestCase::SLOT_LITERAL_PATTERN, 'WIK-193 provenance'))->toBe(0);
});
