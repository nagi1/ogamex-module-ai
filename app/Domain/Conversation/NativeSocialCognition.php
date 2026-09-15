<?php

namespace Modules\AI\Domain\Conversation;

use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Enums\AiMemoryPredicate;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialRepair;
use Modules\AI\Enums\AiSocialResource;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Enums\AiSocialResponseReason;
use Modules\AI\Enums\AiSocialTerm;

class NativeSocialCognition implements SocialCognition
{
    private const MAX_OUTSTANDING_COMMITMENTS = 3;

    /** How much an outstanding counterparty debt cools a new help request. */
    private const OUTSTANDING_DEBT_PENALTY = 0.5;

    public function evaluateSocialExchange(SocialExchangeContext $exchange): SocialExchangeEvaluation
    {
        return match ($exchange->type) {
            AiSocialExchangeType::HelpRequest => $this->evaluateHelpRequest($exchange),
            AiSocialExchangeType::Apology => $this->evaluateApology($exchange),
            AiSocialExchangeType::Greeting, AiSocialExchangeType::Thanks => app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Accept, 'reason' => AiSocialResponseReason::RoutineAcknowledgement]),
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
            return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Clarify, 'reason' => AiSocialResponseReason::MissingOrInvalidAmount]);
        }

        if ($exchange->outstandingCommitments >= self::MAX_OUTSTANDING_COMMITMENTS) {
            return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Reject, 'reason' => AiSocialResponseReason::TooManyOutstandingCommitments]);
        }

        if ($amount > $exchange->availableAmount) {
            return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Counter, 'reason' => AiSocialResponseReason::InsufficientAvailableAmount, 'counterTerms' => [AiSocialTerm::Amount->value => $exchange->availableAmount]]);
        }

        $cooperation = $this->cooperationScore($exchange) - $this->outstandingDebtPenalty($exchange);

        if ($cooperation >= 0.75) {
            return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Accept, 'reason' => AiSocialResponseReason::TrustedAndSafe]);
        }

        if ($cooperation <= 0.25) {
            return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Reject, 'reason' => AiSocialResponseReason::InsufficientTrust]);
        }

        return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Clarify, 'reason' => AiSocialResponseReason::TermsNeedConfirmation]);
    }

    private function evaluateApology(SocialExchangeContext $exchange): SocialExchangeEvaluation
    {
        if (($exchange->terms[AiSocialTerm::AcknowledgesHarm->value] ?? false) !== true) {
            return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Clarify, 'reason' => AiSocialResponseReason::HarmNotAcknowledged]);
        }

        if ($exchange->threat >= 0.75) {
            return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Reject, 'reason' => AiSocialResponseReason::HarmNotRepaired]);
        }

        if ($this->standingWeight($exchange) >= 0.5) {
            return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Accept, 'reason' => AiSocialResponseReason::ApologyAcknowledged]);
        }

        return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Counter, 'reason' => AiSocialResponseReason::CompensationNeeded, 'counterTerms' => [AiSocialTerm::Repair->value => AiSocialRepair::Compensation->value]]);
    }

    private function requestedAmount(SocialExchangeContext $exchange): float|null
    {
        $amount = $exchange->terms[AiSocialTerm::Amount->value] ?? null;

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
            return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Clarify, 'reason' => AiSocialResponseReason::MissingOrInvalidTradeTerms]);
        }

        return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Reject, 'reason' => AiSocialResponseReason::TransportCapabilityUnavailable]);
    }

    private function evaluateCeasefireRequest(SocialExchangeContext $exchange): SocialExchangeEvaluation
    {
        if ($exchange->dueAt === null) {
            return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Clarify, 'reason' => AiSocialResponseReason::MissingCeasefireExpiry]);
        }

        if ($exchange->threat >= 0.75) {
            return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Reject, 'reason' => AiSocialResponseReason::UnsafeCeasefireRequest]);
        }

        return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Clarify, 'reason' => AiSocialResponseReason::CeasefireEnforcementUnavailable]);
    }

    private function evaluateWarning(SocialExchangeContext $exchange): SocialExchangeEvaluation
    {
        if (($exchange->terms[AiSocialTerm::Coercive->value] ?? false) === true) {
            return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Reject, 'reason' => AiSocialResponseReason::CoerciveWarning]);
        }

        return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Clarify, 'reason' => AiSocialResponseReason::WarningAcknowledgedWithoutCommitment]);
    }

    private function evaluateCooperationRequest(SocialExchangeContext $exchange): SocialExchangeEvaluation
    {
        $scope = $exchange->terms[AiSocialTerm::Scope->value] ?? null;

        if (!is_string($scope) || trim($scope) === '') {
            return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Clarify, 'reason' => AiSocialResponseReason::MissingCooperationScope]);
        }

        if ($this->cooperationScore($exchange) <= 0.25) {
            return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Reject, 'reason' => AiSocialResponseReason::InsufficientTrust]);
        }

        return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Clarify, 'reason' => AiSocialResponseReason::CooperationCapabilityUnavailable]);
    }

    private function evaluateCompensationOffer(SocialExchangeContext $exchange): SocialExchangeEvaluation
    {
        if (($exchange->terms[AiSocialTerm::AcknowledgesHarm->value] ?? false) !== true) {
            return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Clarify, 'reason' => AiSocialResponseReason::HarmNotAcknowledged]);
        }

        if ($exchange->dueAt === null) {
            return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Clarify, 'reason' => AiSocialResponseReason::MissingCompensationDueAt]);
        }

        if (!$this->hasResourceAmount(
            $exchange->terms[AiSocialTerm::Resource->value] ?? null,
            $exchange->terms[AiSocialTerm::Amount->value] ?? null,
        )) {
            return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Clarify, 'reason' => AiSocialResponseReason::MissingOrInvalidCompensationTerms]);
        }

        if ($exchange->threat >= 0.75) {
            return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Reject, 'reason' => AiSocialResponseReason::HarmNotRepaired]);
        }

        return app()->makeWith(SocialExchangeEvaluation::class, ['response' => AiSocialResponse::Accept, 'reason' => AiSocialResponseReason::CompensationRecorded]);
    }

    /**
     * The standing a counterparty has earned with this AI, reduced by current anger.
     *
     * Anger is transient state and is deliberately a term here rather than a write: a grudge
     * changes how the same apology is answered without touching earned trust or an outstanding
     * obligation, which is what keeps an emotion from quietly settling a debt.
     */
    private function standingWeight(SocialExchangeContext $exchange): float
    {
        return $exchange->trust
            + $exchange->affinity
            + (($exchange->respect + $exchange->socialImportance) / 2)
            - $exchange->anger;
    }

    private function cooperationScore(SocialExchangeContext $exchange): float
    {
        return $this->standingWeight($exchange) - $exchange->threat;
    }

    /**
     * A counterparty who already owes this AI resources is met with less cooperation on a
     * new help request — the human play of not lending more to someone who has not repaid
     * the last debt. Only a current, valid debt fact counts: the recall has already filtered
     * expired, redacted and ended facts, so a recalled debt is a live obligation.
     */
    private function outstandingDebtPenalty(SocialExchangeContext $exchange): float
    {
        foreach ($exchange->history as $fact) {
            if (($fact['predicate'] ?? null) === AiMemoryPredicate::ResourceDebt->name) {
                return self::OUTSTANDING_DEBT_PENALTY;
            }
        }

        return 0.0;
    }

    private function hasTradeTerms(SocialExchangeContext $exchange): bool
    {
        return $this->hasResourceAmount(
            $exchange->terms[AiSocialTerm::OfferedResource->value] ?? null,
            $exchange->terms[AiSocialTerm::OfferedAmount->value] ?? null,
        ) && $this->hasResourceAmount(
            $exchange->terms[AiSocialTerm::RequestedResource->value] ?? null,
            $exchange->terms[AiSocialTerm::RequestedAmount->value] ?? null,
        );
    }

    private function hasResourceAmount(mixed $resource, mixed $amount): bool
    {
        if (!is_string($resource) || AiSocialResource::tryFrom($resource) === null) {
            return false;
        }

        return (is_int($amount) || is_float($amount)) && $amount > 0;
    }
}
