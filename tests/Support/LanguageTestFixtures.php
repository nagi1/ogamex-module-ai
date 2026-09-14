<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\QueueAiAuthoredReplyAction;
use Modules\AI\Actions\RecordObservedChatMessageAction;
use Modules\AI\Actions\SealAiAuthoredReplyAction;
use Modules\AI\Contracts\LanguageGateway;
use Modules\AI\Domain\Conversation\ConversationContext;
use Modules\AI\Domain\Conversation\LanguageProposal;
use Modules\AI\Domain\Conversation\LanguageRequest;
use Modules\AI\Domain\Conversation\LanguageResult;
use Modules\AI\Domain\Language\AiProviderLadder;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiLanguageProposalType;
use Modules\AI\Enums\AiLanguageRequestState;
use Modules\AI\Enums\AiLanguageResultStatus;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiSocialResource;
use Modules\AI\Enums\AiUsageReservationState;
use Modules\AI\Models\AiConversationReply;
use Modules\AI\Models\AiLanguageRequest;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiUsageReservation;
use Modules\AI\Tests\Support\FixedLanguageGateway;
use OGame\Models\ChatMessage;

function languageRequest(): LanguageRequest
{
    return app()->makeWith(LanguageRequest::class, [
        'replyId' => 1,
        'playerId' => 1,
        'counterpartyPlayerId' => 2,
        'requestKey' => 'language-test',
        'context' => app()->makeWith(ConversationContext::class, ['sections' => [], 'serialized' => '{"safe":true}', 'protectedContentFits' => true]),
        'authorizedSourceMessageIds' => [1],
        'ladder' => languageLadder(),
        'timeoutSeconds' => 17,
        'maximumReplyCharacters' => 1_200,
    ]);
}

/**
 * A fixture names its rung outright, so no gateway test depends on which vendors this environment
 * happens to hold a key for.
 */
function languageLadder(string $provider = 'openai', string $model = 'gpt-5-mini'): AiProviderLadder
{
    return app()->makeWith(AiProviderLadder::class, ['rungs' => [['provider' => $provider, 'model' => $model]]]);
}

function languageResult(AiLanguageResultStatus $status, string|null $text, int $inputTokens, int $outputTokens): LanguageResult
{
    return app()->makeWith(LanguageResult::class, [
        'status' => $status,
        'text' => $text,
        'interpretation' => null,
        'proposals' => [],
        'inputTokens' => $inputTokens,
        'outputTokens' => $outputTokens,
        'providerRequestId' => null,
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
    ]);
}

function bindFixedLanguageResult(LanguageResult $result, Closure|null $beforeResult = null): void
{
    app()->bind(LanguageGateway::class, fn (): FixedLanguageGateway => app()->makeWith(FixedLanguageGateway::class, [
        'result' => $result,
        'beforeResult' => $beforeResult,
    ]));
}

function languageProposal(AiLanguageProposalType $type, int $sourceMessageId, AiSocialResource|null $resource, int|null $amount, CarbonImmutable|null $dueAt = null): LanguageProposal
{
    return app()->makeWith(LanguageProposal::class, ['type' => $type, 'sourceMessageId' => $sourceMessageId, 'resource' => $resource, 'amount' => $amount, 'dueAt' => $dueAt]);
}

function languageRequestRecord(AiConversationReply $reply, AiLanguageRequestState $state): AiLanguageRequest
{
    $reservation = AiUsageReservation::query()->create([
        'universe_scope' => 'default',
        'player_id' => $reply->player_id,
        'conversation_key' => $reply->player_id . ':' . $reply->counterparty_player_id,
        'request_key' => 'test-reservation:' . $reply->id,
        'reserved_for' => '2024-01-01',
        'reserved_input_tokens' => 2_000,
        'reserved_output_tokens' => 320,
        'state' => AiUsageReservationState::Reserved,
    ]);

    return AiLanguageRequest::query()->create([
        'conversation_reply_id' => $reply->id,
        'usage_reservation_id' => $reservation->id,
        'request_key' => 'test-language:' . $reply->id,
        'state' => $state,
        'context_hash' => hash('sha256', 'test-language:' . $reply->id),
    ]);
}

/** @return array{0: AiConversationReply, 1: ChatMessage} */
function sealedLanguageReply(Closure $createUser, int $playerId, string $sourceText, bool $counterpartyIsAi = false): array
{
    $counterparty = $createUser();
    AiProfile::query()->firstOrCreate(['player_id' => $playerId], [
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 7,
        'enabled' => true,
    ]);

    if ($counterpartyIsAi) {
        AiProfile::create([
            'player_id' => $counterparty->id,
            'archetype' => AiArchetype::Trader,
            'skill_band' => AiSkillBand::Standard,
            'random_seed' => 8,
            'enabled' => true,
        ]);
    }

    $source = ChatMessage::create([
        'sender_id' => $counterparty->id,
        'recipient_id' => $playerId,
        'message' => $sourceText,
    ]);
    app(RecordObservedChatMessageAction::class)->handle($source->id);
    $reply = app(QueueAiAuthoredReplyAction::class)->handle(
        $playerId,
        $counterparty->id,
        $source->id,
        'Authored fallback.',
        CarbonImmutable::now()->addHour(),
    );
    $sealed = app(SealAiAuthoredReplyAction::class)->handle($reply?->id ?? 0);

    return [$sealed, $source];
}
