<?php

use Modules\AI\Actions\ReviewAiBuddyRequestsAction;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
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
