<?php

namespace Modules\AI\Actions;

use Illuminate\Support\Facades\DB;
use Modules\AI\Contracts\ContextBuilder;
use Modules\AI\Contracts\LanguageGateway;
use Modules\AI\Domain\Conversation\ConversationContextSection;
use Modules\AI\Domain\Conversation\LanguageRequest;
use Modules\AI\Domain\Conversation\LanguageResult;
use Modules\AI\Domain\Conversation\UsageBudgetLimit;
use Modules\AI\Domain\Conversation\UsageBudgetLimits;
use Modules\AI\Domain\Conversation\UsageReservationRequest;
use Modules\AI\Enums\AiConversationReplyState;
use Modules\AI\Enums\AiLanguageRequestState;
use Modules\AI\Enums\AiLanguageResultStatus;
use Modules\AI\Models\AiConversationReply;
use Modules\AI\Models\AiLanguageRequest;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use OGame\Models\ChatMessage;

class GenerateAiReplyAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(int $replyId): ChatMessage|null
    {
        $reply = AiConversationReply::query()->find($replyId);

        if ($reply === null || $reply->state !== AiConversationReplyState::Sealed) {
            return null;
        }

        if (!(bool) config('ai.language.enabled', false)) {
            return app(DeliverAiSealedReplyAction::class)->handle($reply->id);
        }

        $existing = AiLanguageRequest::query()->where('conversation_reply_id', $reply->id)->first();

        if ($existing !== null) {
            return $this->deliverTerminalReply($reply, $existing);
        }

        $request = $this->buildRequest($reply);

        if ($request === null) {
            return app(DeliverAiSealedReplyAction::class)->handle($reply->id);
        }

        $languageRequest = $this->acquireRequest($reply, $request);

        if ($languageRequest === null) {
            return app(DeliverAiSealedReplyAction::class)->handle($reply->id);
        }

        $startedAt = hrtime(true);
        $result = app(LanguageGateway::class)->generateConversationReply($request);
        $this->finalizeRequest($languageRequest->id, $reply->id, $result, intdiv(hrtime(true) - $startedAt, 1_000_000));

        return app(DeliverAiSealedReplyAction::class)->handle($reply->id);
    }

    private function deliverTerminalReply(AiConversationReply $reply, AiLanguageRequest $request): ChatMessage|null
    {
        if ($request->state === AiLanguageRequestState::Generating) {
            return null;
        }

        return app(DeliverAiSealedReplyAction::class)->handle($reply->id);
    }

    private function buildRequest(AiConversationReply $reply): LanguageRequest|null
    {
        if (AiProfile::query()->where('player_id', $reply->counterparty_player_id)->where('enabled', true)->exists()) {
            return null;
        }

        $profile = AiProfile::query()->where('player_id', $reply->player_id)->where('enabled', true)->first();

        if ($profile === null) {
            return null;
        }

        $messages = ChatMessage::query()
            ->whereBetween('id', [$reply->source_first_message_id, $reply->source_last_message_id])
            ->where('sender_id', $reply->counterparty_player_id)
            ->where('recipient_id', $reply->player_id)
            ->whereNull('alliance_id')
            ->orderBy('id')
            ->limit(4)
            ->get(['id', 'message']);

        if ($messages->isEmpty()) {
            return null;
        }

        $sections = [
            app()->makeWith(ConversationContextSection::class, [
                'name' => 'constraints',
                'value' => [
                    'reply_to_player_id' => $reply->counterparty_player_id,
                    'authorized_source_message_ids' => $messages->pluck('id')->all(),
                    'maximum_reply_characters' => (int) config('ai.language.maximum_reply_characters', 1_200),
                    'no_tools' => true,
                ],
                'isProtected' => true,
            ]),
            app()->makeWith(ConversationContextSection::class, [
                'name' => 'persona',
                'value' => ['archetype' => $profile->archetype->value, 'skill_band' => $profile->skill_band->value],
                'isProtected' => true,
            ]),
            app()->makeWith(ConversationContextSection::class, [
                'name' => 'messages',
                'value' => $messages->map(fn (ChatMessage $message): array => ['source_message_id' => $message->id, 'text' => $message->message])->all(),
                'isProtected' => true,
            ]),
        ];
        $context = app(ContextBuilder::class)->buildConversationContext($sections, (int) config('ai.language.context_characters', 8_000));

        if (!$context->protectedContentFits) {
            return null;
        }

        return app()->makeWith(LanguageRequest::class, [
            'replyId' => $reply->id,
            'playerId' => $reply->player_id,
            'counterpartyPlayerId' => $reply->counterparty_player_id,
            'requestKey' => 'language-reply:' . $reply->id . ':' . $reply->revision,
            'context' => $context,
            'authorizedSourceMessageIds' => $messages->pluck('id')->all(),
            'provider' => (string) config('ai.language.provider', 'openai'),
            'model' => (string) config('ai.language.model', 'gpt-5-mini'),
            'timeoutSeconds' => (int) config('ai.language.timeout_seconds', 20),
            'maximumReplyCharacters' => (int) config('ai.language.maximum_reply_characters', 1_200),
        ]);
    }

    private function acquireRequest(AiConversationReply $reply, LanguageRequest $request): AiLanguageRequest|null
    {
        return DB::transaction(function () use ($reply, $request): AiLanguageRequest|null {
            $existing = AiLanguageRequest::query()
                ->where('conversation_reply_id', $reply->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return null;
            }

            $reservation = app(ReserveAiUsageAction::class)->handle(
                app()->makeWith(UsageReservationRequest::class, [
                    'universeScope' => (string) config('ai.language.universe_scope', 'default'),
                    'playerId' => $reply->player_id,
                    'conversationKey' => $reply->player_id . ':' . $reply->counterparty_player_id,
                    'requestKey' => $request->requestKey,
                    'inputTokens' => (int) config('ai.language.maximum_input_tokens', 2_000),
                    'outputTokens' => (int) config('ai.language.maximum_output_tokens', 320),
                    'reservedAt' => $this->clock->now(),
                ]),
                $this->usageLimits(),
            );

            if ($reservation === null) {
                return null;
            }

            return AiLanguageRequest::query()->create([
                'conversation_reply_id' => $reply->id,
                'usage_reservation_id' => $reservation->id,
                'request_key' => $request->requestKey,
                'state' => AiLanguageRequestState::Generating,
                'provider' => $request->provider,
                'model' => $request->model,
                'context_hash' => hash('sha256', $request->context->serialized),
            ]);
        });
    }

    private function finalizeRequest(int $languageRequestId, int $replyId, LanguageResult $result, int $latencyMilliseconds): void
    {
        DB::transaction(function () use ($languageRequestId, $replyId, $result, $latencyMilliseconds): void {
            $request = AiLanguageRequest::query()->lockForUpdate()->find($languageRequestId);
            $reply = AiConversationReply::query()->lockForUpdate()->find($replyId);

            if ($request === null || $reply === null || $request->state !== AiLanguageRequestState::Generating) {
                return;
            }

            $state = $this->resolveState($request, $result);
            $this->updateRequest($request, $state, $result, $latencyMilliseconds);

            if ($state !== AiLanguageRequestState::Completed) {
                return;
            }

            $reply->update(['message' => $result->text, 'revision' => $reply->revision + 1]);

            collect($result->proposals)->each(fn ($proposal) => app(RecordAiLanguageProposalAction::class)->handle($request, $reply->refresh(), $proposal));
        });
    }

    /**
     * A timed-out provider call may still complete remotely, so its attempt stays reserved
     * for the reconciliation command instead of being charged against reported zero usage
     * or resent; every other outcome settles its attempt in this transaction.
     */
    private function resolveState(AiLanguageRequest $request, LanguageResult $result): AiLanguageRequestState
    {
        if ($result->status === AiLanguageResultStatus::TimedOut) {
            return AiLanguageRequestState::Uncertain;
        }

        if (!$this->settleKnownUsage($request, $result)) {
            return AiLanguageRequestState::Uncertain;
        }

        return $this->settledState($result->status);
    }

    private function settledState(AiLanguageResultStatus $status): AiLanguageRequestState
    {
        return match ($status) {
            AiLanguageResultStatus::Completed => AiLanguageRequestState::Completed,
            AiLanguageResultStatus::Invalid => AiLanguageRequestState::Invalid,
            // A disabled gateway produced no text, yet it consumed its reserved attempt.
            AiLanguageResultStatus::Disabled, AiLanguageResultStatus::Failed => AiLanguageRequestState::Failed,
            AiLanguageResultStatus::TimedOut => AiLanguageRequestState::Uncertain,
        };
    }

    private function settleKnownUsage(AiLanguageRequest $request, LanguageResult $result): bool
    {
        return app(SettleAiUsageReservationAction::class)->handle(
            $request->usage_reservation_id,
            $result->inputTokens,
            $result->outputTokens,
            $this->clock->now(),
        ) !== null;
    }

    private function updateRequest(AiLanguageRequest $request, AiLanguageRequestState $state, LanguageResult $result, int $latencyMilliseconds): void
    {
        $request->update([
            'state' => $state,
            'provider' => $result->provider,
            'model' => $result->model,
            'provider_request_id' => $result->providerRequestId,
            'interpretation' => $result->interpretation,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'latency_milliseconds' => max(0, $latencyMilliseconds),
        ]);
    }

    private function usageLimits(): UsageBudgetLimits
    {
        return app()->makeWith(UsageBudgetLimits::class, [
            'universe' => $this->usageLimit('universe'),
            'player' => $this->usageLimit('player'),
            'conversation' => $this->usageLimit('conversation'),
        ]);
    }

    private function usageLimit(string $scope): UsageBudgetLimit
    {
        return app()->makeWith(UsageBudgetLimit::class, [
            'attempts' => (int) config('ai.language.daily_limits.' . $scope . '.attempts'),
            'inputTokens' => (int) config('ai.language.daily_limits.' . $scope . '.input_tokens'),
            'outputTokens' => (int) config('ai.language.daily_limits.' . $scope . '.output_tokens'),
        ]);
    }
}
