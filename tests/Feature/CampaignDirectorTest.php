<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\AdvanceAiCampaignStateAction;
use Modules\AI\Actions\DeclareAiCampaignObjectiveAction;
use Modules\AI\Actions\OpenAiCampaignAction;
use Modules\AI\Enums\AiCampaignState;
use Modules\AI\Models\AiCampaign;
use Modules\AI\Models\AiCampaignObjective;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The director owns only the campaign's published lifecycle: the window opens, an on-time
 * completion of the whole announced set resolves it, and a deadline with work still open
 * fails it. Terminal states are final, so a later pass never rewrites a recorded outcome.
 */
function campaign(CarbonImmutable $startsAt, CarbonImmutable $endsAt): AiCampaign
{
    return app(OpenAiCampaignAction::class)->handle($startsAt, $endsAt);
}

function objective(int $campaignId, int $planetId): AiCampaignObjective
{
    return app(DeclareAiCampaignObjectiveAction::class)->handle($campaignId, $planetId);
}

function advance(): int
{
    return app(AdvanceAiCampaignStateAction::class)->handle();
}

test('a campaign stays preparing before its window opens', function (): void {
    $campaign = campaign(now()->addHour()->toImmutable(), now()->addDay()->toImmutable());

    expect(advance())->toBe(0)
        ->and($campaign->refresh()->state)->toBe(AiCampaignState::Preparing);
});

test('a campaign becomes active when its window opens', function (): void {
    $campaign = campaign(now()->addHour()->toImmutable(), now()->addDay()->toImmutable());

    $this->travelTo(now()->addHour());

    expect(advance())->toBe(1)
        ->and($campaign->refresh()->state)->toBe(AiCampaignState::Active);
});

test('an active campaign with every stronghold completed on time resolves', function (): void {
    $campaign = campaign(now()->subHour()->toImmutable(), now()->addHour()->toImmutable());
    objective($campaign->id, $this->currentPlanetId)->update(['completed_at' => now()]);

    expect(advance())->toBe(1)
        ->and($campaign->refresh()->state)->toBe(AiCampaignState::Resolved);
});

test('a campaign with a stronghold still standing stays active', function (): void {
    $campaign = campaign(now()->subHour()->toImmutable(), now()->addHour()->toImmutable());
    objective($campaign->id, $this->currentPlanetId);

    expect(advance())->toBe(1)
        ->and($campaign->refresh()->state)->toBe(AiCampaignState::Active);
});

test('a campaign fails at its deadline with a stronghold still standing', function (): void {
    $campaign = campaign(now()->subHour()->toImmutable(), now()->addHour()->toImmutable());
    objective($campaign->id, $this->currentPlanetId);

    $this->travelTo(now()->addHour()->addMinute());

    expect(advance())->toBe(1)
        ->and($campaign->refresh()->state)->toBe(AiCampaignState::Failed);
});

test('a campaign with no stronghold fails at its deadline', function (): void {
    $campaign = campaign(now()->subHour()->toImmutable(), now()->addHour()->toImmutable());

    $this->travelTo(now()->addHour()->addMinute());

    expect(advance())->toBe(1)
        ->and($campaign->refresh()->state)->toBe(AiCampaignState::Failed);
});

test('a stronghold completed after the deadline does not resolve the campaign', function (): void {
    $campaign = campaign(now()->subHour()->toImmutable(), now()->addHour()->toImmutable());
    objective($campaign->id, $this->currentPlanetId)->update(['completed_at' => now()->addDay()]);

    $this->travelTo(now()->addHour()->addMinute());

    expect(advance())->toBe(1)
        ->and($campaign->refresh()->state)->toBe(AiCampaignState::Failed);
});

test('a resolved campaign is never advanced again', function (): void {
    $campaign = campaign(now()->subHour()->toImmutable(), now()->addHour()->toImmutable());
    objective($campaign->id, $this->currentPlanetId)->update(['completed_at' => now()]);

    advance();

    expect(advance())->toBe(0)
        ->and($campaign->refresh()->state)->toBe(AiCampaignState::Resolved);
});

test('the advance command reports the campaigns it advanced', function (): void {
    $this->artisan('ai:advance-campaigns')->assertSuccessful();
});
