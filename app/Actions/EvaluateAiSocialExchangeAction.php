<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\SocialExchangeContext;
use Modules\AI\Enums\AiCommitmentState;
use Modules\AI\Enums\AiSocialExchangeState;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Models\AiCommitment;
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
            $evaluation = app(SocialCognition::class)->evaluateSocialExchange(new SocialExchangeContext(
                $exchange->id,
                $exchange->type,
                $exchange->terms,
                (float) $relationship?->trust,
                (float) $relationship?->affinity,
                (float) $relationship?->threat,
                $outstandingCommitments,
                max(0, $availableAmount),
                $evaluatedAt,
            ));

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
            );
            $commitment = app(AcceptAiCommitmentAction::class)->handle($commitment->id);
            $exchange->update(['commitment_id' => $commitment?->id]);

            return $exchange->refresh();
        });
    }

    private function responseCreatesCommitment(AiSocialExchange $exchange): bool
    {
        return $exchange->type === AiSocialExchangeType::HelpRequest;
    }
}
