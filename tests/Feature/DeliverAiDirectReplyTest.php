<?php

use Modules\AI\Actions\DeliverAiDirectReplyAction;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\ChatMessage;
use OGame\Models\IgnoredPlayer;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

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
    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 1, 'enabled' => true]);
    $otherConversation = ChatMessage::create(['sender_id' => $other->id, 'recipient_id' => $this->currentUserId, 'message' => 'Private']);
    IgnoredPlayer::create(['user_id' => $recipient->id, 'ignored_user_id' => $this->currentUserId]);

    expect(app(DeliverAiDirectReplyAction::class)->handle($this->currentUserId, $recipient->id, 'blocked'))->toBeNull()
        ->and(app(DeliverAiDirectReplyAction::class)->handle($this->currentUserId, $other->id, 'wrong reply', $otherConversation->id))->not->toBeNull()
        ->and(app(DeliverAiDirectReplyAction::class)->handle($this->currentUserId, $recipient->id, str_repeat('x', 2001)))->toBeNull();

    AiProfile::query()->where('player_id', $this->currentUserId)->update(['enabled' => false]);

    expect(app(DeliverAiDirectReplyAction::class)->handle($this->currentUserId, $other->id, 'disabled'))->toBeNull();
});
