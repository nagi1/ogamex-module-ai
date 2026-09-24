<?php

use Illuminate\Support\Facades\Http;
use Modules\AI\Actions\ReviewAiBuddyRequestsAction;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCognitionDriver;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Infrastructure\Cognition\PsychSimClient;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\DriverCircuitBreaker;
use Modules\AI\Support\SystemAiClock;
use OGame\Models\BuddyRequest;
use OGame\Models\User;
use OGame\Services\BuddyService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

function buddyProfile(int $playerId): void
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Casual,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
        'enabled' => true,
    ]);
}

function buddyKnownContact(int $playerId, int $contactId): void
{
    AiRelationship::unguarded(fn () => AiRelationship::create([
        'player_id' => $playerId,
        'other_player_id' => $contactId,
        'revision' => 0,
    ]));
}

function enableBuddyPsychSim(): void
{
    app()->bind(AiClock::class, SystemAiClock::class);
    config(['ai.cognition.mode' => 'external']);
    config(['ai.cognition.driver' => AiCognitionDriver::PsychSim->value]);
    app()->when(PsychSimClient::class)
        ->needs(DriverCircuitBreaker::class)
        ->give(fn (): DriverCircuitBreaker => app()->makeWith(DriverCircuitBreaker::class, [
            'driver' => AiCognitionDriver::PsychSim->value,
        ]));
}

test('a buddy request from a known contact is accepted', function (): void {
    buddyProfile($this->currentUserId);
    $contact = User::factory()->create();
    buddyKnownContact($this->currentUserId, $contact->id);

    $request = app(BuddyService::class)->sendRequest($contact->id, $this->currentUserId);

    expect(app(ReviewAiBuddyRequestsAction::class)->handle())->toBe(1)
        ->and($request->fresh()->status)->toBe(BuddyRequest::STATUS_ACCEPTED);
});

test('a buddy request from a stranger is left pending', function (): void {
    buddyProfile($this->currentUserId);
    $stranger = User::factory()->create();

    $request = app(BuddyService::class)->sendRequest($stranger->id, $this->currentUserId);

    expect(app(ReviewAiBuddyRequestsAction::class)->handle())->toBe(0)
        ->and($request->fresh()->status)->toBe(BuddyRequest::STATUS_PENDING);
});

test('a known contact the theory-of-mind read models as exploitative is left pending', function (): void {
    buddyProfile($this->currentUserId);
    $contact = User::factory()->create();
    buddyKnownContact($this->currentUserId, $contact->id);
    AiRelationship::unguarded(fn () => AiRelationship::query()
        ->where('player_id', $this->currentUserId)
        ->where('other_player_id', $contact->id)
        ->update(['threat' => '0.7500']));

    enableBuddyPsychSim();
    Http::fake(['*/evaluate' => Http::response(['decision' => 'defect'])]);

    $request = app(BuddyService::class)->sendRequest($contact->id, $this->currentUserId);

    expect(app(ReviewAiBuddyRequestsAction::class)->handle())->toBe(0)
        ->and($request->fresh()->status)->toBe(BuddyRequest::STATUS_PENDING);
});

test('a cooperate read accepts the same known contact', function (): void {
    buddyProfile($this->currentUserId);
    $contact = User::factory()->create();
    buddyKnownContact($this->currentUserId, $contact->id);
    AiRelationship::unguarded(fn () => AiRelationship::query()
        ->where('player_id', $this->currentUserId)
        ->where('other_player_id', $contact->id)
        ->update(['threat' => '0.7500']));

    enableBuddyPsychSim();
    Http::fake(['*/evaluate' => Http::response(['decision' => 'cooperate'])]);

    $request = app(BuddyService::class)->sendRequest($contact->id, $this->currentUserId);

    expect(app(ReviewAiBuddyRequestsAction::class)->handle())->toBe(1)
        ->and($request->fresh()->status)->toBe(BuddyRequest::STATUS_ACCEPTED);
});
