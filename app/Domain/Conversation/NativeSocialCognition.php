<?php

namespace Modules\AI\Domain\Conversation;

use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialResource;
use Modules\AI\Enums\AiSocialResponse;

class NativeSocialCognition implements SocialCognition
{
    private const MAX_OUTSTANDING_COMMITMENTS = 3;

    public function evaluateSocialExchange(SocialExchangeContext $exchange): SocialExchangeEvaluation
    {
        return match ($exchange->type) {
            AiSocialExchangeType::HelpRequest => $this->evaluateHelpRequest($exchange),
            AiSocialExchangeType::Apology => $this->evaluateApology($exchange),
            AiSocialExchangeType::Greeting, AiSocialExchangeType::Thanks => new SocialExchangeEvaluation(AiSocialResponse::Accept, 'routine_acknowledgement'),
            AiSocialExchangeType::TradeOffer => $this->evaluateTradeOffer($exchange),
            AiSocialExchangeType::CeasefireRequest => $this->evaluateCeasefireRequest($exchange),
            AiSocialExchangeType::Warning => $this->evaluateWarning($exchange),
            AiSocialExchangeType::CooperationRequest => $this->evaluateCooperationRequest($exchange),
            AiSocialExchangeType::CompensationOffer => $this->evaluateCompensationOffer($exchange),
        };
    }

    private function evaluateHelpRequest(SocialExchangeContext $exchange): SocialExchangeEvaluation
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

        $cooperation = $this->cooperationScore($exchange);

        if ($cooperation >= 0.75) {
            return new SocialExchangeEvaluation(AiSocialResponse::Accept, 'trusted_and_safe');
        }

        if ($cooperation <= 0.25) {
            return new SocialExchangeEvaluation(AiSocialResponse::Reject, 'insufficient_trust');
        }

        return new SocialExchangeEvaluation(AiSocialResponse::Clarify, 'terms_need_confirmation');
    }

    private function evaluateApology(SocialExchangeContext $exchange): SocialExchangeEvaluation
    {
        if (($exchange->terms['acknowledges_harm'] ?? false) !== true) {
            return new SocialExchangeEvaluation(AiSocialResponse::Clarify, 'harm_not_acknowledged');
        }

        if ($exchange->threat >= 0.75) {
            return new SocialExchangeEvaluation(AiSocialResponse::Reject, 'harm_not_repaired');
        }

        if ($exchange->trust + $exchange->affinity + (($exchange->respect + $exchange->socialImportance) / 2) >= 0.5) {
            return new SocialExchangeEvaluation(AiSocialResponse::Accept, 'apology_acknowledged');
        }

        return new SocialExchangeEvaluation(AiSocialResponse::Counter, 'compensation_needed', ['repair' => 'compensation']);
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

    private function evaluateTradeOffer(SocialExchangeContext $exchange): SocialExchangeEvaluation
    {
        if (!$this->hasTradeTerms($exchange)) {
            return new SocialExchangeEvaluation(AiSocialResponse::Clarify, 'missing_or_invalid_trade_terms');
        }

        return new SocialExchangeEvaluation(AiSocialResponse::Reject, 'transport_capability_unavailable');
    }

    private function evaluateCeasefireRequest(SocialExchangeContext $exchange): SocialExchangeEvaluation
    {
        if ($exchange->dueAt === null) {
            return new SocialExchangeEvaluation(AiSocialResponse::Clarify, 'missing_ceasefire_expiry');
        }

        if ($exchange->threat >= 0.75) {
            return new SocialExchangeEvaluation(AiSocialResponse::Reject, 'unsafe_ceasefire_request');
        }

        return new SocialExchangeEvaluation(AiSocialResponse::Clarify, 'ceasefire_enforcement_unavailable');
    }

    private function evaluateWarning(SocialExchangeContext $exchange): SocialExchangeEvaluation
    {
        if (($exchange->terms['coercive'] ?? false) === true) {
            return new SocialExchangeEvaluation(AiSocialResponse::Reject, 'coercive_warning');
        }

        return new SocialExchangeEvaluation(AiSocialResponse::Clarify, 'warning_acknowledged_without_commitment');
    }

    private function evaluateCooperationRequest(SocialExchangeContext $exchange): SocialExchangeEvaluation
    {
        $scope = $exchange->terms['scope'] ?? null;

        if (!is_string($scope) || trim($scope) === '') {
            return new SocialExchangeEvaluation(AiSocialResponse::Clarify, 'missing_cooperation_scope');
        }

        if ($this->cooperationScore($exchange) <= 0.25) {
            return new SocialExchangeEvaluation(AiSocialResponse::Reject, 'insufficient_trust');
        }

        return new SocialExchangeEvaluation(AiSocialResponse::Clarify, 'cooperation_capability_unavailable');
    }

    private function evaluateCompensationOffer(SocialExchangeContext $exchange): SocialExchangeEvaluation
    {
        if (($exchange->terms['acknowledges_harm'] ?? false) !== true) {
            return new SocialExchangeEvaluation(AiSocialResponse::Clarify, 'harm_not_acknowledged');
        }

        if ($exchange->dueAt === null) {
            return new SocialExchangeEvaluation(AiSocialResponse::Clarify, 'missing_compensation_due_at');
        }

        if (!$this->hasResourceAmount($exchange->terms)) {
            return new SocialExchangeEvaluation(AiSocialResponse::Clarify, 'missing_or_invalid_compensation_terms');
        }

        if ($exchange->threat >= 0.75) {
            return new SocialExchangeEvaluation(AiSocialResponse::Reject, 'harm_not_repaired');
        }

        return new SocialExchangeEvaluation(AiSocialResponse::Accept, 'compensation_recorded');
    }

    private function cooperationScore(SocialExchangeContext $exchange): float
    {
        return $exchange->trust
            + $exchange->affinity
            + (($exchange->respect + $exchange->socialImportance) / 2)
            - $exchange->threat;
    }

    private function hasTradeTerms(SocialExchangeContext $exchange): bool
    {
        return $this->hasResourceAmount([
            'resource' => $exchange->terms['offered_resource'] ?? null,
            'amount' => $exchange->terms['offered_amount'] ?? null,
        ]) && $this->hasResourceAmount([
            'resource' => $exchange->terms['requested_resource'] ?? null,
            'amount' => $exchange->terms['requested_amount'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $terms
     */
    private function hasResourceAmount(array $terms): bool
    {
        $resource = $terms['resource'] ?? null;
        $amount = $terms['amount'] ?? null;

        if (!is_string($resource) || AiSocialResource::tryFrom($resource) === null) {
            return false;
        }

        return (is_int($amount) || is_float($amount)) && $amount > 0;
    }
}
