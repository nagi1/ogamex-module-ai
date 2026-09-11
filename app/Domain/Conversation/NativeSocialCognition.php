<?php

namespace Modules\AI\Domain\Conversation;

use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Enums\AiSocialResponse;

class NativeSocialCognition implements SocialCognition
{
    private const MAX_OUTSTANDING_COMMITMENTS = 3;

    public function evaluateSocialExchange(SocialExchangeContext $exchange): SocialExchangeEvaluation
    {
        $amount = $this->requestedAmount($exchange);

        if ($amount === null) {
            return new SocialExchangeEvaluation(AiSocialResponse::Clarify, 'missing_or_invalid_amount');
        }

        if ($exchange->outstandingCommitments >= self::MAX_OUTSTANDING_COMMITMENTS) {
            return new SocialExchangeEvaluation(AiSocialResponse::Reject, 'too_many_outstanding_commitments');
        }

        if ($amount > $exchange->availableAmount) {
            return new SocialExchangeEvaluation(AiSocialResponse::Counter, 'insufficient_available_amount', ['amount' => $exchange->availableAmount]);
        }

        $cooperation = $exchange->trust + $exchange->affinity - $exchange->threat;

        if ($cooperation >= 0.75) {
            return new SocialExchangeEvaluation(AiSocialResponse::Accept, 'trusted_and_safe');
        }

        if ($cooperation <= 0.25) {
            return new SocialExchangeEvaluation(AiSocialResponse::Reject, 'insufficient_trust');
        }

        return new SocialExchangeEvaluation(AiSocialResponse::Clarify, 'terms_need_confirmation');
    }

    private function requestedAmount(SocialExchangeContext $exchange): float|null
    {
        $amount = $exchange->terms['amount'] ?? null;

        if (!is_int($amount) && !is_float($amount)) {
            return null;
        }

        if ($amount <= 0) {
            return null;
        }

        return (float) $amount;
    }
}
