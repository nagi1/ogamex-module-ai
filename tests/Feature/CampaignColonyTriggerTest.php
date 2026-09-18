<?php

use Modules\AI\Actions\OpenAiCampaignAction;
use Modules\AI\Actions\RecordAiColonyCampaignSignalAction;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCampaignConsultationTrigger;
use Modules\AI\Enums\AiCampaignState;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiCampaign;
use Modules\AI\Models\AiCampaignConsultationSignal;
use Modules\AI\Models\AiProfile;
use OGame\Events\Game\PlanetCreated;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

function colonyTriggerProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 99_000 + $playerId,
        'enabled' => true,
    ]);
}

function colonyTriggerCampaign(AiCampaignState $state): AiCampaign
{
    $campaign = app(OpenAiCampaignAction::class)->handle(now()->subHour()->toImmutable(), now()->addDay()->toImmutable());
    $campaign->state = $state;
    $campaign->save();

    return $campaign;
}

test('a coalition colony signals every active campaign', function (): void {
    colonyTriggerCampaign(AiCampaignState::Active);
    colonyTriggerProfile($this->currentUserId);

    app(RecordAiColonyCampaignSignalAction::class)->handle(new PlanetCreated(
        $this->secondPlanetService->getPlanetId(),
        $this->currentUserId,
        PlanetType::Planet->value,
    ));

    expect(AiCampaignConsultationSignal::query()
        ->where('trigger', AiCampaignConsultationTrigger::NewColony->value)
        ->count())->toBe(1);
});

test('a homeworld is not a colony', function (): void {
    colonyTriggerCampaign(AiCampaignState::Active);
    colonyTriggerProfile($this->currentUserId);
    // Leave the account with its single homeworld only.
    Planet::query()->where('user_id', $this->currentUserId)->where('id', '!=', $this->currentPlanetId)->update(['destroyed' => 1]);

    app(RecordAiColonyCampaignSignalAction::class)->handle(new PlanetCreated(
        $this->currentPlanetId,
        $this->currentUserId,
        PlanetType::Planet->value,
    ));

    expect(AiCampaignConsultationSignal::query()->count())->toBe(0);
});

test('a moon never signals a colony', function (): void {
    colonyTriggerCampaign(AiCampaignState::Active);
    colonyTriggerProfile($this->currentUserId);

    app(RecordAiColonyCampaignSignalAction::class)->handle(new PlanetCreated(
        $this->secondPlanetService->getPlanetId(),
        $this->currentUserId,
        PlanetType::Moon->value,
    ));

    expect(AiCampaignConsultationSignal::query()->count())->toBe(0);
});

test('a non-member colony does not signal', function (): void {
    colonyTriggerCampaign(AiCampaignState::Active);
    // No profile: the account is not in the coalition.

    app(RecordAiColonyCampaignSignalAction::class)->handle(new PlanetCreated(
        $this->secondPlanetService->getPlanetId(),
        $this->currentUserId,
        PlanetType::Planet->value,
    ));

    expect(AiCampaignConsultationSignal::query()->count())->toBe(0);
});

test('a preparing campaign is not signalled', function (): void {
    colonyTriggerCampaign(AiCampaignState::Preparing);
    colonyTriggerProfile($this->currentUserId);

    app(RecordAiColonyCampaignSignalAction::class)->handle(new PlanetCreated(
        $this->secondPlanetService->getPlanetId(),
        $this->currentUserId,
        PlanetType::Planet->value,
    ));

    expect(AiCampaignConsultationSignal::query()->count())->toBe(0);
});
