<?php

namespace Modules\AI\Infrastructure\Cognition;

use Illuminate\Support\Facades\Log;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\SocialExchangeContext;
use Modules\AI\Domain\Conversation\SocialExchangeEvaluation;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Enums\AiSocialResponseReason;

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
        private readonly PsychSimClient $client,
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

        $decision = $this->client->decide($this->temptation($exchange));

        // An absent answer, a cooperate stance or a contract deviation leaves the native
        // stance in place; only a modelled defect withholds it — the wary account that will
        // not warm to a counterparty the theory-of-mind step says would exploit it.
        if ($decision !== 'defect') {
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

    /**
     * The counterparty's payoff for defecting on a cooperator, on the same scale as the
     * sidecar's cooperation payoff of 2. The module's threat rating (0..1) doubles into that
     * 0..2 incentive, so the depth-one model cooperates below half and defects above it: the
     * account does not accept from a counterparty it rates more than half threatening.
     */
    private function temptation(SocialExchangeContext $exchange): float
    {
        return $exchange->threat * 2.0;
    }
}
