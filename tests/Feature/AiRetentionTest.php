<?php

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiCandidateActionType;
use Modules\AI\Enums\AiConversationReplyState;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Enums\AiSocialExchangeState;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiConversationReply;
use Modules\AI\Models\AiDecisionTrace;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiSocialExchange;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

/**
 * The pilot's volumes are what sizes these windows -- roughly a work item, half
 * a trace and a fifth of a receipt per account per hour, against a module that
 * deleted nothing -- and what makes a declared window real is a sweep that runs.
 */
test('it deletes bookkeeping past its window and keeps everything inside it', function () {
    $now = app(AiClock::class)->now();
    $inside = [
        aiRetentionWorkItem($now->subDays(89)),
        aiRetentionTrace($now->subDays(29)),
        aiRetentionReceipt($now->subDays(89)),
        aiRetentionObservation($now->subDays(29)),
        aiRetentionReply($now->subDays(29)),
    ];
    $past = [
        aiRetentionWorkItem($now->subDays(91)),
        aiRetentionTrace($now->subDays(31)),
        aiRetentionReceipt($now->subDays(91)),
        aiRetentionObservation($now->subDays(31)),
        aiRetentionReply($now->subDays(31)),
    ];

    $this->artisan('ai:prune')->assertSuccessful();

    foreach ($inside as $model) {
        expect($model->newQuery()->whereKey($model->getKey())->exists())->toBeTrue();
    }

    foreach ($past as $model) {
        expect($model->newQuery()->whereKey($model->getKey())->exists())->toBeFalse();
    }
});

// What is deleted is the log, never the memory: an exchange is what the account
// knows about someone, and a trace is only what it happened to write down.
test('it never deletes what the account remembers', function () {
    $exchange = AiSocialExchange::create([
        'player_id' => $this->currentUserId,
        'counterparty_player_id' => $this->currentUserId + 1,
        'source_observation_id' => 1,
        'type' => AiSocialExchangeType::Greeting,
        'terms' => [],
        'state' => AiSocialExchangeState::Proposed,
        'revision' => 1,
    ]);
    aiRetentionAged($exchange, app(AiClock::class)->now()->subYear());

    $this->artisan('ai:prune')->assertSuccessful();

    expect(AiSocialExchange::query()->whereKey($exchange->getKey())->exists())->toBeTrue();
});

function aiRetentionWorkItem(CarbonImmutable $createdAt): AiWorkItem
{
    return aiRetentionAged(AiWorkItem::create([
        'player_id' => 999_000,
        'kind' => AiWorkKind::RunSession,
        'due_at' => $createdAt,
        'idempotency_key' => aiRetentionKey($createdAt, 'work'),
        'state' => AiWorkState::Completed,
    ]), $createdAt);
}

function aiRetentionTrace(CarbonImmutable $createdAt): AiDecisionTrace
{
    return aiRetentionAged(AiDecisionTrace::create([
        'player_id' => 999_000,
        'work_item_id' => 999_000,
        'selected_action' => AiCandidateActionType::DoNothing,
        'selected_reason' => 'retention',
        'candidates' => [],
        'score_components' => [],
        'source_timestamps' => [],
        'input_hash' => aiRetentionKey($createdAt, 'trace'),
        'observed_at' => $createdAt,
        'expires_at' => $createdAt->addDays(30),
    ]), $createdAt);
}

function aiRetentionReceipt(CarbonImmutable $createdAt): AiActionReceipt
{
    return aiRetentionAged(AiActionReceipt::create([
        'player_id' => 999_000,
        'idempotency_key' => aiRetentionKey($createdAt, 'receipt'),
        'action_type' => AiActionType::QueueBuilding,
        'state' => AiReceiptState::Accepted,
    ]), $createdAt);
}

function aiRetentionObservation(CarbonImmutable $createdAt): AiObservation
{
    return aiRetentionAged(AiObservation::create([
        'player_id' => 999_000,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => random_int(1, PHP_INT_MAX),
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'source_time' => $createdAt,
        'observed_at' => $createdAt,
    ]), $createdAt);
}

function aiRetentionReply(CarbonImmutable $createdAt): AiConversationReply
{
    return aiRetentionAged(AiConversationReply::create([
        'player_id' => 999_000,
        'counterparty_player_id' => 999_001,
        'source_first_message_id' => 1,
        'source_last_message_id' => 1,
        'message' => 'retention',
        'state' => AiConversationReplyState::Sealed,
        'expires_at' => $createdAt->addHours(3),
        'revision' => 1,
    ]), $createdAt);
}

function aiRetentionKey(CarbonImmutable $createdAt, string $suffix): string
{
    return 'retention:' . $suffix . ':' . $createdAt->getTimestamp() . ':' . random_int(1, PHP_INT_MAX);
}

/** Moves a row's creation timestamp into the past, which a create cannot do. */
function aiRetentionAged(object $model, CarbonImmutable $createdAt): object
{
    $model->newQuery()->whereKey($model->getKey())->update(['created_at' => $createdAt]);

    return $model;
}
