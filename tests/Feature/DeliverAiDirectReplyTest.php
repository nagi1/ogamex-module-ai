<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\DeliverAiDirectReplyAction;
use Modules\AI\Actions\DeliverAiSealedReplyAction;
use Modules\AI\Actions\QueueAiAuthoredReplyAction;
use Modules\AI\Actions\SealAiAuthoredReplyAction;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiConversationReplyState;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiConversationReply;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\FixtureAiClock;
use OGame\Models\ChatMessage;
use OGame\Models\IgnoredPlayer;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/FixtureAiClock.php';

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, [
        'now' => CarbonImmutable::parse('2024-01-01 00:00:00 UTC'),
    ]));
});

test('an enabled AI sends a bounded direct reply only within its reply conversation', function (): void {
    $recipient = $this->createUser();
    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 1, 'enabled' => true]);
    $source = ChatMessage::create(['sender_id' => $recipient->id, 'recipient_id' => $this->currentUserId, 'message' => 'Need help']);

    $delivered = app(DeliverAiDirectReplyAction::class)->handle($this->currentUserId, $recipient->id, ' I can help. ', $source->id);

    expect($delivered?->sender_id)->toBe($this->currentUserId)
        ->and($delivered?->recipient_id)->toBe($recipient->id)
        ->and($delivered?->message)->toBe('I can help.')
        ->and($delivered?->reply_to_id)->toBe($source->id);
});

test('delivery rejects disabled, blocked, invalid, and cross-conversation replies', function (): void {
    $recipient = $this->createUser();
    $other = $this->createUser();
    $unrelated = $this->createUser();
    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 1, 'enabled' => true]);
    $otherConversation = ChatMessage::create(['sender_id' => $other->id, 'recipient_id' => $this->currentUserId, 'message' => 'Private']);
    $unrelatedConversation = ChatMessage::create(['sender_id' => $unrelated->id, 'recipient_id' => $recipient->id, 'message' => 'Unrelated private message']);
    IgnoredPlayer::create(['user_id' => $recipient->id, 'ignored_user_id' => $this->currentUserId]);

    expect(app(DeliverAiDirectReplyAction::class)->handle($this->currentUserId, $recipient->id, 'blocked'))->toBeNull()
        ->and(app(DeliverAiDirectReplyAction::class)->handle($this->currentUserId, $other->id, 'wrong reply', $otherConversation->id))->not->toBeNull()
        ->and(app(DeliverAiDirectReplyAction::class)->handle($this->currentUserId, $other->id, 'unrelated reply', $unrelatedConversation->id))->toBeNull()
        ->and(app(DeliverAiDirectReplyAction::class)->handle($this->currentUserId, $recipient->id, str_repeat('x', 2001)))->toBeNull();

    AiProfile::query()->where('player_id', $this->currentUserId)->update(['enabled' => false]);

    expect(app(DeliverAiDirectReplyAction::class)->handle($this->currentUserId, $other->id, 'disabled'))->toBeNull();
});

test('an authored reply coalesces pending conversation sources before it is sealed', function (): void {
    $recipient = $this->createUser();
    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 1, 'enabled' => true]);
    $firstSource = ChatMessage::create(['sender_id' => $recipient->id, 'recipient_id' => $this->currentUserId, 'message' => 'First question']);
    $secondSource = ChatMessage::create(['sender_id' => $recipient->id, 'recipient_id' => $this->currentUserId, 'message' => 'Second question']);
    $expiry = CarbonImmutable::parse('2024-01-01 01:00:00 UTC');

    $first = app(QueueAiAuthoredReplyAction::class)->handle($this->currentUserId, $recipient->id, $firstSource->id, 'First answer', $expiry);
    $coalesced = app(QueueAiAuthoredReplyAction::class)->handle($this->currentUserId, $recipient->id, $secondSource->id, 'Second answer', $expiry);

    expect($coalesced?->id)->toBe($first?->id)
        ->and($coalesced?->source_first_message_id)->toBe($firstSource->id)
        ->and($coalesced?->source_last_message_id)->toBe($secondSource->id)
        ->and($coalesced?->message)->toBe('Second answer')
        ->and($coalesced?->revision)->toBe(2)
        ->and(AiConversationReply::query()->count())->toBe(1);

    $sealed = app(SealAiAuthoredReplyAction::class)->handle($first?->id ?? 0);
    $nextSource = ChatMessage::create(['sender_id' => $recipient->id, 'recipient_id' => $this->currentUserId, 'message' => 'Third question']);
    $next = app(QueueAiAuthoredReplyAction::class)->handle($this->currentUserId, $recipient->id, $nextSource->id, 'Third answer', $expiry);

    expect($sealed?->state)->toBe(AiConversationReplyState::Sealed)
        ->and(app(SealAiAuthoredReplyAction::class)->handle($sealed?->id ?? 0)?->state)->toBe(AiConversationReplyState::Sealed)
        ->and(app(SealAiAuthoredReplyAction::class)->handle(PHP_INT_MAX))->toBeNull()
        ->and($next?->id)->not->toBe($sealed?->id)
        ->and(AiConversationReply::query()->count())->toBe(2);
});

test('a sealed authored reply persists one host message and idempotent delivery receipt', function (): void {
    $recipient = $this->createUser();
    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 1, 'enabled' => true]);
    $source = ChatMessage::create(['sender_id' => $recipient->id, 'recipient_id' => $this->currentUserId, 'message' => 'Need an answer']);
    $reply = app(QueueAiAuthoredReplyAction::class)->handle($this->currentUserId, $recipient->id, $source->id, 'Here is the answer.', CarbonImmutable::parse('2024-01-01 01:00:00 UTC'));
    $sealed = app(SealAiAuthoredReplyAction::class)->handle($reply?->id ?? 0);

    $firstDelivery = app(DeliverAiSealedReplyAction::class)->handle($sealed?->id ?? 0);
    $secondDelivery = app(DeliverAiSealedReplyAction::class)->handle($sealed?->id ?? 0);
    $storedReply = AiConversationReply::query()->findOrFail($sealed?->id ?? 0);

    expect($firstDelivery?->id)->toBe($secondDelivery?->id)
        ->and($firstDelivery?->reply_to_id)->toBe($source->id)
        ->and($storedReply->state)->toBe(AiConversationReplyState::Delivered)
        ->and($storedReply->delivery_key)->toBe('authored-reply:' . $storedReply->id . ':1')
        ->and($storedReply->delivered_chat_message_id)->toBe($firstDelivery?->id)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->where('recipient_id', $recipient->id)->count())->toBe(1);
});

test('a stale or expired sealed reply is never sent', function (): void {
    $recipient = $this->createUser();
    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 1, 'enabled' => true]);
    $source = ChatMessage::create(['sender_id' => $recipient->id, 'recipient_id' => $this->currentUserId, 'message' => 'Please reply']);
    $reply = app(QueueAiAuthoredReplyAction::class)->handle($this->currentUserId, $recipient->id, $source->id, 'Queued reply', CarbonImmutable::parse('2024-01-01 01:00:00 UTC'));
    $sealed = app(SealAiAuthoredReplyAction::class)->handle($reply?->id ?? 0);
    $source->delete();

    expect(app(DeliverAiSealedReplyAction::class)->handle($sealed?->id ?? 0))->toBeNull()
        ->and(AiConversationReply::query()->findOrFail($sealed?->id ?? 0)->state)->toBe(AiConversationReplyState::Rejected);

    $expired = AiConversationReply::create([
        'player_id' => $this->currentUserId,
        'counterparty_player_id' => $recipient->id,
        'source_first_message_id' => $source->id,
        'source_last_message_id' => $source->id,
        'message' => 'Expired reply',
        'state' => AiConversationReplyState::Pending,
        'expires_at' => CarbonImmutable::parse('2023-12-31 23:59:59 UTC'),
        'revision' => 1,
    ]);

    expect(app(SealAiAuthoredReplyAction::class)->handle($expired->id)?->state)->toBe(AiConversationReplyState::Expired);
});

test('reply queue and delivery reject invalid or no-longer-permitted work', function (): void {
    $recipient = $this->createUser();
    $other = $this->createUser();
    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 1, 'enabled' => true]);
    $source = ChatMessage::create(['sender_id' => $recipient->id, 'recipient_id' => $this->currentUserId, 'message' => 'Need a reply']);
    $wrongSource = ChatMessage::create(['sender_id' => $other->id, 'recipient_id' => $this->currentUserId, 'message' => 'Other reply']);
    $expiresAt = CarbonImmutable::parse('2024-01-01 01:00:00 UTC');

    expect(app(QueueAiAuthoredReplyAction::class)->handle($this->currentUserId, $recipient->id, $source->id, ' ', $expiresAt))->toBeNull()
        ->and(app(QueueAiAuthoredReplyAction::class)->handle($this->currentUserId, $recipient->id, $source->id, 'Expired', CarbonImmutable::parse('2023-12-31 23:59:59 UTC')))->toBeNull();

    AiProfile::query()->where('player_id', $this->currentUserId)->update(['enabled' => false]);

    expect(app(QueueAiAuthoredReplyAction::class)->handle($this->currentUserId, $recipient->id, $source->id, 'Disabled', $expiresAt))->toBeNull();

    AiProfile::query()->where('player_id', $this->currentUserId)->update(['enabled' => true]);

    expect(app(QueueAiAuthoredReplyAction::class)->handle($this->currentUserId, $recipient->id, $wrongSource->id, 'Wrong conversation', $expiresAt))->toBeNull()
        ->and(app(DeliverAiSealedReplyAction::class)->handle(PHP_INT_MAX))->toBeNull();

    $pending = app(QueueAiAuthoredReplyAction::class)->handle($this->currentUserId, $recipient->id, $source->id, 'Pending reply', $expiresAt);

    expect(app(DeliverAiSealedReplyAction::class)->handle($pending?->id ?? 0))->toBeNull();

    $sealed = app(SealAiAuthoredReplyAction::class)->handle($pending?->id ?? 0);
    IgnoredPlayer::create(['user_id' => $recipient->id, 'ignored_user_id' => $this->currentUserId]);

    expect(app(DeliverAiSealedReplyAction::class)->handle($sealed?->id ?? 0))->toBeNull()
        ->and(AiConversationReply::query()->findOrFail($sealed?->id ?? 0)->state)->toBe(AiConversationReplyState::Rejected);

    $expiringSource = ChatMessage::create(['sender_id' => $other->id, 'recipient_id' => $this->currentUserId, 'message' => 'Expire this reply']);
    $expiringReply = AiConversationReply::create([
        'player_id' => $this->currentUserId,
        'counterparty_player_id' => $other->id,
        'source_first_message_id' => $expiringSource->id,
        'source_last_message_id' => $expiringSource->id,
        'message' => 'Expired sealed reply',
        'state' => AiConversationReplyState::Sealed,
        'delivery_key' => 'expired-sealed:' . $expiringSource->id,
        'expires_at' => CarbonImmutable::parse('2023-12-31 23:59:59 UTC'),
        'sealed_at' => CarbonImmutable::parse('2023-12-31 23:00:00 UTC'),
        'revision' => 1,
    ]);

    expect(app(DeliverAiSealedReplyAction::class)->handle($expiringReply->id))->toBeNull()
        ->and(AiConversationReply::query()->findOrFail($expiringReply->id)->state)->toBe(AiConversationReplyState::Expired);
});
