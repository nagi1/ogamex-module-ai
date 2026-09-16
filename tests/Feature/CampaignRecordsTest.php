<?php

use Modules\AI\Actions\DeclareAiCampaignObjectiveAction;
use Modules\AI\Actions\OpenAiCampaignAction;
use Modules\AI\Actions\RecordAiCampaignContributionAction;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCampaignContributionKind;
use Modules\AI\Enums\AiCampaignState;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiCampaignContribution;
use Modules\AI\Models\AiCampaignObjective;
use Modules\AI\Models\AiProfile;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The campaign board's own records: a published window, declared host planets and
 * source-deduplicated contributions. No object is named by the module — the strongholds
 * are host planet ids read at runtime, and the contribution source is the host's own
 * operation identity.
 */
test('a campaign opens preparing with its published window', function (): void {
    $startsAt = now()->toImmutable();
    $endsAt = now()->addHours(48)->toImmutable();

    $campaign = app(OpenAiCampaignAction::class)->handle($startsAt, $endsAt);

    expect($campaign->state)->toBe(AiCampaignState::Preparing)
        ->and($campaign->starts_at->toIso8601String())->toBe($startsAt->toIso8601String())
        ->and($campaign->ends_at->toIso8601String())->toBe($endsAt->toIso8601String());
});

test('a reversed campaign window is refused', function (): void {
    expect(fn (): mixed => app(OpenAiCampaignAction::class)->handle(now()->addHour()->toImmutable(), now()->toImmutable()))
        ->toThrow(InvalidArgumentException::class);
});

test('a stronghold objective is declared once per campaign and planet', function (): void {
    $campaign = app(OpenAiCampaignAction::class)->handle(now()->toImmutable(), now()->addDay()->toImmutable());

    $first = app(DeclareAiCampaignObjectiveAction::class)->handle($campaign->id, $this->currentPlanetId);
    $second = app(DeclareAiCampaignObjectiveAction::class)->handle($campaign->id, $this->currentPlanetId);

    expect($first)->not->toBeNull()
        ->and($second->id)->toBe($first->id)
        ->and(AiCampaignObjective::query()->count())->toBe(1);
});

test('an objective for a missing campaign or planet is not declared', function (): void {
    $campaign = app(OpenAiCampaignAction::class)->handle(now()->toImmutable(), now()->addDay()->toImmutable());

    expect(app(DeclareAiCampaignObjectiveAction::class)->handle(999_999_999, $this->currentPlanetId))->toBeNull()
        ->and(app(DeclareAiCampaignObjectiveAction::class)->handle($campaign->id, 999_999_999))->toBeNull();
});

test('a contribution is credited once per source operation', function (): void {
    $campaign = app(OpenAiCampaignAction::class)->handle(now()->toImmutable(), now()->addDay()->toImmutable());

    $first = app(RecordAiCampaignContributionAction::class)->handle(
        $campaign->id,
        $this->currentUserId,
        AiCampaignContributionKind::Scout,
        'espionage_report',
        7,
    );
    $second = app(RecordAiCampaignContributionAction::class)->handle(
        $campaign->id,
        $this->currentUserId,
        AiCampaignContributionKind::Scout,
        'espionage_report',
        7,
    );

    expect($first)->not->toBeNull()
        ->and($second->id)->toBe($first->id)
        ->and(AiCampaignContribution::query()->count())->toBe(1);
});

test('a contribution for a missing campaign is not recorded', function (): void {
    expect(app(RecordAiCampaignContributionAction::class)->handle(
        999_999_999,
        $this->currentUserId,
        AiCampaignContributionKind::Supply,
        'fleet_mission',
        1,
    ))->toBeNull();
});

test('an AI-faction account is never credited for its own campaign work', function (): void {
    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Fleeter,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 1,
        'enabled' => true,
    ]);

    $campaign = app(OpenAiCampaignAction::class)->handle(now()->toImmutable(), now()->addDay()->toImmutable());

    expect(app(RecordAiCampaignContributionAction::class)->handle(
        $campaign->id,
        $this->currentUserId,
        AiCampaignContributionKind::Defend,
        'acs_defend',
        2,
    ))->toBeNull()
        ->and(AiCampaignContribution::query()->count())->toBe(0);
});
