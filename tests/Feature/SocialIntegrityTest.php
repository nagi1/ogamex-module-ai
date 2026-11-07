<?php

use Modules\AI\Actions\AdvanceAiAlliancePoliticsAction;
use Modules\AI\Actions\CheckSocialIntegrityAction;
use Modules\AI\Enums\AiAllianceStance;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiAllianceRelation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use OGame\Models\ChatMessage;
use OGame\Models\FleetMission;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// Deed matches speech (SOCIAL-WORLD-DRAFT 16.3, phase E): what AI leaders announce stands on the treaty record, what they
// do on it was announced, and no hostile fleet flies at a pact partner.

function integrityClubs(int $leaderId): array
{
    AiProfile::create(['player_id' => $leaderId, 'archetype' => AiArchetype::Fleeter, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 1, 'enabled' => true]);
    $ours = app(AllianceService::class)->createAlliance($leaderId, 'OURS', 'Ours Club');
    $theirs = app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'THEM', 'Them Club');

    return [$ours, $theirs];
}

test('a war the politics declared and announced passes', function (): void {
    [$ours, $theirs] = integrityClubs($this->currentUserId);
    $raider = User::factory()->create();
    User::query()->whereKey($raider->id)->update(['alliance_id' => $theirs->id]);
    AiRelationship::unguarded(fn () => AiRelationship::create(['player_id' => $this->currentUserId, 'other_player_id' => $raider->id, 'threat' => '1.0000', 'trust' => '-1.0000', 'revision' => 0]));

    expect(app(AdvanceAiAlliancePoliticsAction::class)->handle())->toBe(1);
    $report = app(CheckSocialIntegrityAction::class)->handle();

    expect($report['acts']['wars'])->toBe(1)
        ->and($report['speech']['war'] ?? 0)->toBeGreaterThan(0)
        ->and($report['unmatched_speech'])->toBe([])
        ->and($report['silent_acts'])->toBe([])
        ->and($report['ok'])->toBeTrue();
});

test('a war nobody announced and an announced war nobody declared both fail', function (): void {
    [$ours, $theirs] = integrityClubs($this->currentUserId);
    AiAllianceRelation::query()->create(['alliance_id' => $ours->id, 'other_alliance_id' => $theirs->id, 'stance' => AiAllianceStance::War, 'cause' => 'grievance', 'started_at' => now()]);
    $report = app(CheckSocialIntegrityAction::class)->handle();
    expect($report['silent_acts'])->toHaveCount(1)->and($report['silent_acts'][0]['act'])->toBe('war')->and($report['ok'])->toBeFalse();

    AiAllianceRelation::query()->delete();
    ChatMessage::query()->create(['sender_id' => $this->currentUserId, 'recipient_id' => $theirs->founder_user_id, 'message' => '[THEM]: we are done being farmed by your people. This is war.']);
    $report = app(CheckSocialIntegrityAction::class)->handle();
    expect($report['unmatched_speech'])->toHaveCount(1)->and($report['unmatched_speech'][0]['line'])->toBe('war_to_leader');
});

test('an attack on a pact partner is caught, an espionage probe only counted', function (): void {
    [$ours] = integrityClubs($this->currentUserId);
    $target = $this->createForeignPlanet();
    $partner = app(AllianceService::class)->createAlliance($target->getPlayer()->getId(), 'PART', 'Partner Club');
    AiAllianceRelation::query()->create(['alliance_id' => $ours->id, 'other_alliance_id' => $partner->id, 'stance' => AiAllianceStance::Nap, 'cause' => 'flank', 'started_at' => now()->subHour()]);

    foreach ([1, 6] as $type) {
        $mission = new FleetMission();
        $mission->user_id = $this->currentUserId;
        $mission->planet_id_to = $target->getPlanetId();
        $mission->mission_type = $type;
        $mission->galaxy_to = $target->getPlanetCoordinates()->galaxy;
        $mission->system_to = $target->getPlanetCoordinates()->system;
        $mission->position_to = $target->getPlanetCoordinates()->position;
        $mission->time_departure = now()->timestamp;
        $mission->time_arrival = now()->addHour()->timestamp;
        $mission->time_arrival_ms = 0;
        $mission->processed = 0;
        $mission->canceled = 0;
        $mission->save();
    }

    $fleets = app(CheckSocialIntegrityAction::class)->handle()['fleets'];
    expect($fleets['hostile_at_partner'])->toHaveCount(1)->and($fleets['hostile_at_partner'][0]['type'])->toBe(1)
        ->and($fleets['espionage_at_partner'])->toBe(1);
});
