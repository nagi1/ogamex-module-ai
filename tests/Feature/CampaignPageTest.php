<?php

use Modules\AI\Actions\DeclareAiCampaignObjectiveAction;
use Modules\AI\Actions\OpenAiCampaignAction;
use Modules\AI\Actions\SummarizeAiCampaignAction;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiCampaignObjective;
use Modules\AI\Models\AiProfile;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;
use OGame\Models\Highscore;

require_once __DIR__ . '/../Support/AiQueueModuleTestCase.php';

uses(AiQueueModuleTestCase::class);

test('the campaign summary is empty when no campaign exists', function (): void {
    expect(app(SummarizeAiCampaignAction::class)->handle())->toBeNull();
});

test('the summary reports progress, momentum and faction losses', function (): void {
    $campaign = app(OpenAiCampaignAction::class)->handle(now()->subHour()->toImmutable(), now()->addDay()->toImmutable());
    app(DeclareAiCampaignObjectiveAction::class)->handle($campaign->id, $this->currentPlanetId);
    $campaign->update(['faction_momentum' => 1]);

    AiProfile::create([
        'player_id' => $this->currentUserId,
        'archetype' => AiArchetype::Casual,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
        'enabled' => true,
    ]);
    Highscore::unguarded(fn () => Highscore::updateOrCreate(
        ['player_id' => $this->currentUserId],
        ['general' => 1000, 'general_rank' => 1, 'economy' => 500, 'research' => 500, 'military_lost' => 750],
    ));

    $summary = app(SummarizeAiCampaignAction::class)->handle();

    expect($summary)->not->toBeNull()
        ->and($summary['total'])->toBe(1)
        ->and($summary['completed'])->toBe(0)
        ->and($summary['factionMomentum'])->toBe(1)
        ->and($summary['factionLosses'])->toBe(750)
        ->and($summary['strongholds'][0]['completed'])->toBeFalse();
});

test('the campaign page renders for any logged-in player', function (): void {
    $campaign = app(OpenAiCampaignAction::class)->handle(now()->subHour()->toImmutable(), now()->addDay()->toImmutable());
    app(DeclareAiCampaignObjectiveAction::class)->handle($campaign->id, $this->currentPlanetId);

    $response = $this->get('/campaign');

    expect($response->status())->toBe(200)
        ->and($response->getContent())->toContain('Coalition campaign');
});

test('an objective whose planet is gone renders a placeholder coordinate', function (): void {
    $campaign = app(OpenAiCampaignAction::class)->handle(now()->subHour()->toImmutable(), now()->addDay()->toImmutable());
    AiCampaignObjective::unguarded(fn () => AiCampaignObjective::create([
        'campaign_id' => $campaign->id,
        'planet_id' => 999_999,
    ]));

    $summary = app(SummarizeAiCampaignAction::class)->handle();

    expect($summary['strongholds'][0]['coordinates'])->toBe('—');
});
