<?php

namespace Modules\AI\Domain\Conversation;

use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialTerm;

/**
 * Authored relationship policy for simply being spoken to.
 *
 * Contact is evidence of attention, so an ordinary message raises affinity and social
 * importance a little. Coercion is the one input that raises threat and lowers trust. No
 * exchange type grants trust here, because an agreement that was actually honoured is what
 * earns it; friendly wording on its own is not a reason to trust anyone.
 *
 * Resource exchanges are deliberately absent from the deltas: a request answered with
 * nothing costs nothing, and what should move the relationship is the outcome of an
 * obligation, not the fact that somebody asked.
 */
class ContactImpactPolicy
{
    /**
     * @param array<string, mixed> $terms
     */
    public function impactFor(AiSocialExchangeType $type, array $terms): ContactImpact
    {
        return app()->makeWith(ContactImpact::class, $this->deltas($type, $terms));
    }

    /**
     * @param array<string, mixed> $terms
     * @return array<string, float>
     */
    private function deltas(AiSocialExchangeType $type, array $terms): array
    {
        return match ($type) {
            AiSocialExchangeType::Greeting => ['affinity' => 0.05, 'socialImportance' => 0.05],
            AiSocialExchangeType::Thanks => ['affinity' => 0.10, 'socialImportance' => 0.05],
            AiSocialExchangeType::Apology => ['affinity' => 0.02, 'respect' => 0.05, 'socialImportance' => 0.05],
            AiSocialExchangeType::Warning => $this->warningDeltas($terms),
            AiSocialExchangeType::CeasefireRequest => ['socialImportance' => 0.02],
            AiSocialExchangeType::CooperationRequest => ['affinity' => 0.02, 'socialImportance' => 0.05],
            AiSocialExchangeType::TradeOffer => ['affinity' => 0.05, 'respect' => 0.02, 'socialImportance' => 0.05],
            AiSocialExchangeType::HelpRequest, AiSocialExchangeType::CompensationOffer => [],
        };
    }

    /**
     * A coercive warning is a threat made in words, so it raises threat and costs trust. A
     * plain warning is only an unverifiable claim of intent and is treated as such.
     *
     * @param array<string, mixed> $terms
     * @return array<string, float>
     */
    private function warningDeltas(array $terms): array
    {
        return ($terms[AiSocialTerm::Coercive->value] ?? false) === true
            ? ['trust' => -0.10, 'threat' => 0.15, 'affinity' => -0.05]
            : ['threat' => 0.05];
    }
}
