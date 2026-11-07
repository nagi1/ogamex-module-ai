<?php

use Illuminate\Support\Facades\Cache;
use Modules\AI\Domain\Advisor\AdvisorAgent;
use Modules\AI\Domain\Advisor\AdvisorReceipt;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Tests\Support\RaidStory;
use Modules\AI\Tests\Support\Situation;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';
require_once __DIR__ . '/../../Support/RaidStory.php';

uses(IsolatedAccountTestCase::class);

// Area 4 through RaidPlanner's hook with the real advisor (only the provider is faked, and it always says "wait"):
// off asks nothing and the raid flies; observe records the wait beside the rule's "go" and the raid still flies;
// advice holds the fleet.

test('a costly raid under AI_ADVISOR_COSTLY_MODE', function (string $mode, bool $flies, bool $asked): void {
    Cache::flush();
    app(SettingsService::class)->set('ai_advisor_costly_mode', $mode);
    config(['ai.campaign-consultation.advisor.costly_share' => 0.0]);
    AdvisorAgent::fake(fn (): array => ['choice' => 'wait', 'reason' => 'the fleet is most of the account', 'evidence_ids' => [], 'text' => '']);

    $situation = Situation::of($this)
        ->archetype(AiArchetype::Raider)
        ->research('combustion_drive', 8)
        ->ships('large_cargo', 60)
        ->ships('light_fighter', 60)
        ->resources(500_000, 500_000, 1_000_000)
        ->colony();
    RaidStory::report($this, RaidStory::farm($this));
    $session = $situation->session();
    $flies ? $session->expectMission('Attack') : $session->expectNoMission('Attack');

    $receipts = AdvisorReceipt::query()->where('area', 'costly')->get();
    expect($receipts->isNotEmpty())->toBe($asked)
        ->and($receipts->every(fn (AdvisorReceipt $receipt): bool => $receipt->rule_choice === 'go' && $receipt->advice === 'wait' && $receipt->applied === ($mode === 'advice')))->toBeTrue();
    if (!$asked) {
        AdvisorAgent::assertNeverPrompted();
    }
})->with([
    'off' => ['off', true, false],
    'observe' => ['observe', true, true],
    'advice' => ['advice', false, true],
]);
