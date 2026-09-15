<?php

namespace Modules\AI\Infrastructure\Cognition;

use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\SocialExchangeContext;
use Modules\AI\Domain\Conversation\SocialExchangeEvaluation;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Enums\AiSocialResponseReason;

/**
 * The hybrid social engine: the native stance is authoritative, and CiF volition is evidence
 * that can withhold or question it.
 *
 * The driver may only restrain, never grant. An exchange the driver says cannot start is
 * withheld even when the module's own rules accepted it, and a present but lukewarm volition
 * questions an acceptance — the human play of "I only accept a deal I clearly want". Every
 * other outcome keeps the native stance and simply carries the driver's volition and step.
 */
class HybridSocialCognition implements SocialCognition
{
    /**
     * Below this CiF volition an otherwise accepted exchange is questioned instead of
     * accepted. The driver's volition sits on the authored scale the scenario already uses
     * (utilities up to 10), so this is module policy over the driver's own units.
     */
    private const ACCEPTANCE_VOLITION = 5.0;

    public function __construct(
        private readonly SocialCognition $native,
        private readonly FatimaSocialCognition $driver,
    ) {
    }

    public function evaluateSocialExchange(SocialExchangeContext $exchange): SocialExchangeEvaluation
    {
        $native = $this->native->evaluateSocialExchange($exchange);
        $evidence = $this->driver->evidence($exchange);

        if ($evidence === null) {
            return $native;
        }

        $volition = $this->volitionMagnitude($evidence['volitions']);
        $step = $evidence['step'];

        if ($volition === null && $native->response === AiSocialResponse::Accept) {
            return app()->makeWith(SocialExchangeEvaluation::class, [
                'response' => AiSocialResponse::Reject,
                'reason' => AiSocialResponseReason::SocialExchangeVolition,
                'step' => $step,
            ]);
        }

        if ($volition !== null && $volition < self::ACCEPTANCE_VOLITION && $native->response === AiSocialResponse::Accept) {
            return app()->makeWith(SocialExchangeEvaluation::class, [
                'response' => AiSocialResponse::Clarify,
                'reason' => AiSocialResponseReason::TermsNeedConfirmation,
                'volition' => $volition,
                'step' => $step,
            ]);
        }

        return app()->makeWith(SocialExchangeEvaluation::class, [
            'response' => $native->response,
            'reason' => $native->reason,
            'counterTerms' => $native->counterTerms,
            'volition' => $volition,
            'step' => $step,
        ]);
    }

    /**
     * @param  array<string, float>  $volitions
     */
    private function volitionMagnitude(array $volitions): float|null
    {
        return $volitions === [] ? null : max($volitions);
    }
}
