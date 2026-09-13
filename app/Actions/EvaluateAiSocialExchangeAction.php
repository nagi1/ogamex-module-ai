<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\SocialExchangeContext;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Enums\AiCommitmentDirection;
use Modules\AI\Enums\AiCommitmentState;
use Modules\AI\Enums\AiSocialExchangeState;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Models\AiCommitment;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Models\AiSocialExchange;

class EvaluateAiSocialExchangeAction
{
    public function handle(int $exchangeId, float $availableAmount, CarbonImmutable $evaluatedAt): AiSocialExchange|null
    {
        return DB::transaction(function () use ($exchangeId, $availableAmount, $evaluatedAt): AiSocialExchange|null {
            $exchange = AiSocialExchange::query()->lockForUpdate()->find($exchangeId);

            if ($exchange === null) {
                return null;
            }

            if ($exchange->state !== AiSocialExchangeState::Proposed) {
                return $exchange;
            }

            if ($exchange->due_at?->lessThan($evaluatedAt)) {
                $exchange->update([
                    'state' => AiSocialExchangeState::Expired,
                    'revision' => $exchange->revision + 1,
                ]);

                return $exchange->refresh();
            }

            $relationship = AiRelationship::query()
                ->where('player_id', $exchange->player_id)
                ->where('other_player_id', $exchange->counterparty_player_id)
                ->first();
            $outstandingCommitments = AiCommitment::query()
                ->where('player_id', $exchange->player_id)
                ->where('counterparty_player_id', $exchange->counterparty_player_id)
                ->where('state', AiCommitmentState::Accepted)
                ->count();
            $context = app()->makeWith(SocialExchangeContext::class, [
                'exchangeId' => $exchange->id,
                'type' => $exchange->type,
                'terms' => $exchange->terms,
                'trust' => (float) $relationship?->trust,
                'affinity' => (float) $relationship?->affinity,
                'threat' => (float) $relationship?->threat,
                'outstandingCommitments' => $outstandingCommitments,
                'availableAmount' => max(0, $availableAmount),
                'evaluatedAt' => $evaluatedAt,
                'dueAt' => $exchange->due_at === null ? null : CarbonImmutable::instance($exchange->due_at),
                'respect' => (float) $relationship?->respect,
                'socialImportance' => (float) $relationship?->social_importance,
                // Transient state, read at the moment of evaluation and decayed on the way, so
                // an apology is weighed against the anger the AI actually holds right now
                // rather than against what it felt when the harm happened.
                'anger' => app(CurrentAiAffectIntensityAction::class)->handle(
                    $exchange->player_id,
                    AiAffectEmotion::Anger,
                    $evaluatedAt,
                ),
                // An external cognition driver addresses a specific character state and
                // counterparty; the native engine ignores both.
                'archetype' => AiProfile::query()->where('player_id', $exchange->player_id)->first()?->archetype,
                'counterpartyPlayerId' => $exchange->counterparty_player_id,
            ]);
            $evaluation = app(SocialCognition::class)->evaluateSocialExchange($context);

            $exchange->update([
                'state' => AiSocialExchangeState::Responded,
                'response' => $evaluation->response,
                'response_terms' => $evaluation->counterTerms,
                'response_reason' => $evaluation->reason,
                'responded_at' => $evaluatedAt,
                'revision' => $exchange->revision + 1,
            ]);

            if ($evaluation->response !== AiSocialResponse::Accept || !$this->responseCreatesCommitment($exchange)) {
                return $exchange->refresh();
            }

            $commitment = app(RecordAiCommitmentAction::class)->handle(
                $exchange->player_id,
                $exchange->counterparty_player_id,
                $exchange->terms,
                $exchange->source_observation_id,
                $exchange->due_at === null ? null : CarbonImmutable::instance($exchange->due_at),
                $this->commitmentDirection($exchange),
            );
            $commitment = app(AcceptAiCommitmentAction::class)->handle($commitment->id);
            $exchange->update(['commitment_id' => $commitment?->id]);

            return $exchange->refresh();
        });
    }

    private function responseCreatesCommitment(AiSocialExchange $exchange): bool
    {
        return in_array($exchange->type, [AiSocialExchangeType::HelpRequest, AiSocialExchangeType::CompensationOffer], true);
    }

    private function commitmentDirection(AiSocialExchange $exchange): AiCommitmentDirection
    {
        return match ($exchange->type) {
            AiSocialExchangeType::HelpRequest => AiCommitmentDirection::PromisedByPlayer,
            AiSocialExchangeType::CompensationOffer => AiCommitmentDirection::ExpectedFromCounterparty,
            default => throw app()->makeWith(LogicException::class, ['message' => 'Only commitment-producing exchanges have a direction.']),
        };
    }
}
