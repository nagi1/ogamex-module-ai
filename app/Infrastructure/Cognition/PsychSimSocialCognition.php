<?php

namespace Modules\AI\Infrastructure\Cognition;

use Illuminate\Support\Facades\Log;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\SocialExchangeContext;
use Modules\AI\Domain\Conversation\SocialExchangeEvaluation;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Enums\AiSocialResponseReason;
use Modules\AI\Enums\AiToMStance;
use Modules\AI\Support\PsychSimTheoryOfMind;

/**
 * Consults the PsychSim driver before accepting a social exchange.
 *
 * The driver runs a depth-one theory-of-mind step: the account decides first whether to
 * cooperate, and the counterparty answers. The module already owns the hard constraints —
 * exact terms, outstanding commitments, available resources — so the driver is only allowed
 * to *withhold* willingness. It can never grant a response the module's own evaluation
 * refused, because cognition must not be able to authorise something the module would not.
 *
 * The driver's answer is a single cooperate/defect stance, not ranked responses with
 * reasons. Turning that into the module's typed stance is therefore a module decision,
 * documented as such rather than presented as driver capability.
 */
class PsychSimSocialCognition implements SocialCognition
{
    public function __construct(
        private readonly SocialCognition $fallback,
        private readonly PsychSimTheoryOfMind $theory,
    ) {
    }

    public function evaluateSocialExchange(SocialExchangeContext $exchange): SocialExchangeEvaluation
    {
        $native = $this->fallback->evaluateSocialExchange($exchange);

        return $this->withheld($exchange, $native) ?? $native;
    }

    private function withheld(SocialExchangeContext $exchange, SocialExchangeEvaluation $native): SocialExchangeEvaluation|null
    {
        // Only an acceptance can be withheld. A native decline, counter or clarification
        // already answers the exchange, and overriding it would widen the driver's say.
        if ($native->response !== AiSocialResponse::Accept) {
            return null;
        }

        // The driver models the account against a counterparty; without one there is no
        // one to reason about, so the native stance stands.
        if ($exchange->counterpartyPlayerId === null) {
            return null;
        }

        $stance = $this->theory->stanceFor($exchange->threat);

        // An absent answer or a cooperate stance leaves the native stance in place; only a
        // modelled defect withholds it — the wary account that will not warm to a counterparty
        // the theory-of-mind step says would exploit it.
        if ($stance !== AiToMStance::Defect) {
            return null;
        }

        Log::info('The PsychSim driver withheld a social exchange the native rules would have accepted.', [
            'exchange' => $exchange->exchangeId,
            'type' => $exchange->type->name,
            'threat' => $exchange->threat,
        ]);

        return app()->makeWith(SocialExchangeEvaluation::class, [
            'response' => AiSocialResponse::Reject,
            'reason' => AiSocialResponseReason::SocialExchangeVolition,
            'step' => 'psychsim-depth-1',
        ]);
    }
}
