<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\RecordObservedChatMessageAction;
use Modules\AI\Actions\RunAiConversationCycleAction;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\NativeSocialCognition;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\SystemAiClock;
use OGame\Models\Alliance;
use OGame\Models\AllianceMember;
use OGame\Models\ChatMessage;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    config(['ai.language.enabled' => false]);
    app()->bind(AiClock::class, SystemAiClock::class);
    app()->bind(SocialCognition::class, NativeSocialCognition::class);
});

function chatProfile(int $playerId): void
{
    AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Casual,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
        'enabled' => true,
    ]);
}

function chatAlliance(int $founderId): Alliance
{
    return app(AllianceService::class)->createAlliance($founderId, 'CHAT', 'Chat Alliance');
}

function chatMember(int $userId, int $allianceId): void
{
    AllianceMember::unguarded(fn () => AllianceMember::create([
        'alliance_id' => $allianceId,
        'user_id' => $userId,
        'rank_id' => null,
        'joined_at' => now(),
    ]));
    User::whereKey($userId)->update(['alliance_id' => $allianceId]);
}

function postAllianceMessage(int $senderId, int $allianceId, string $text): ChatMessage
{
    return ChatMessage::withoutEvents(fn () => ChatMessage::unguarded(fn () => ChatMessage::create([
        'sender_id' => $senderId,
        'recipient_id' => null,
        'alliance_id' => $allianceId,
        'message' => $text,
    ])));
}

test('an alliance message is observed by each enabled co-member but not the sender', function (): void {
    chatProfile($this->currentUserId);
    $alliance = chatAlliance($this->currentUserId);
    $member = User::factory()->create();
    chatProfile($member->id);
    chatMember($member->id, $alliance->id);

    $message = postAllianceMessage($this->currentUserId, $alliance->id, 'hello everyone');

    expect(app(RecordObservedChatMessageAction::class)->handleAlliance($message->id))->toBe(1)
        ->and(AiObservation::query()
            ->where('player_id', $member->id)
            ->where('kind', AiObservationKind::AllianceChatMessageReceived)
            ->where('source_id', $message->id)
            ->exists())->toBeTrue()
        ->and(AiObservation::query()
            ->where('player_id', $this->currentUserId)
            ->where('kind', AiObservationKind::AllianceChatMessageReceived)
            ->exists())->toBeFalse();
});

test('a member answers a recognised alliance message in the channel', function (): void {
    chatProfile($this->currentUserId);
    $alliance = chatAlliance($this->currentUserId);
    $member = User::factory()->create();
    chatProfile($member->id);
    chatMember($member->id, $alliance->id);

    $message = postAllianceMessage($this->currentUserId, $alliance->id, 'hello');
    app(RecordObservedChatMessageAction::class)->handleAlliance($message->id);

    expect(app(RunAiConversationCycleAction::class)->handle($member->id, CarbonImmutable::now()))->toBe(1)
        ->and(ChatMessage::query()
            ->where('alliance_id', $alliance->id)
            ->where('sender_id', $member->id)
            ->where('id', '!=', $message->id)
            ->exists())->toBeTrue();
});

test('a member never answers its own alliance message', function (): void {
    chatProfile($this->currentUserId);
    $alliance = chatAlliance($this->currentUserId);

    $message = postAllianceMessage($this->currentUserId, $alliance->id, 'hello');

    app(RunAiConversationCycleAction::class)->handle($this->currentUserId, CarbonImmutable::now());

    expect(ChatMessage::query()->where('alliance_id', $alliance->id)->where('id', '!=', $message->id)->exists())->toBeFalse();
});

test('an unrecognised alliance message is left silent', function (): void {
    chatProfile($this->currentUserId);
    $alliance = chatAlliance($this->currentUserId);
    $member = User::factory()->create();
    chatProfile($member->id);
    chatMember($member->id, $alliance->id);

    $message = postAllianceMessage($this->currentUserId, $alliance->id, 'asdfqwer zxcv nonsense');
    app(RecordObservedChatMessageAction::class)->handleAlliance($message->id);

    expect(app(RunAiConversationCycleAction::class)->handle($member->id, CarbonImmutable::now()))->toBe(0)
        ->and(ChatMessage::query()->where('alliance_id', $alliance->id)->where('id', '!=', $message->id)->exists())->toBeFalse();
});
