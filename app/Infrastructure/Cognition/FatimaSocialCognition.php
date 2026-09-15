<?php

namespace Modules\AI\Infrastructure\Cognition;

use Illuminate\Support\Facades\Log;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\SocialExchangeContext;
use Modules\AI\Domain\Conversation\SocialExchangeEvaluation;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Enums\AiSocialResponseReason;

/**
 * Consults the FAtiMA/CiF driver before accepting a social exchange.
 *
 * The driver reports one volition per usable mode at the exchange's current step. The
 * module already owns the hard constraints - exact terms, outstanding commitments,
 * available resources - so the driver is only allowed to *withhold* willingness. It
 * can never grant a response the module's own evaluation refused, because cognition
 * must not be able to authorise something the module would not.
 *
 * CiF's own output is a volition scalar and a resolved step, not ranked responses with
 * reasons. Turning that into the module's typed stance is therefore a module decision,
 * documented as such rather than presented as driver capability.
 */
class FatimaSocialCognition implements SocialCognition
{
    public function __construct(
        private readonly SocialCognition $fallback,
        private readonly FatimaCognitionSession $session,
    ) {
    }

    public function evaluateSocialExchange(SocialExchangeContext $exchange): SocialExchangeEvaluation
    {
        $native = $this->fallback->evaluateSocialExchange($exchange);

        return $this->withheld($exchange, $native) ?? $native;
    }

    /**
     * The raw CiF answer for this exchange, or null when the driver could not answer: no
     * persona, no counterparty, an absent sidecar or an exchange the scenario does not author.
     *
     * @return array{name: string, step: string, volitions: array<string, float>}|null
     */
    public function evidence(SocialExchangeContext $exchange): array|null
    {
        if ($exchange->archetype === null || $exchange->counterpartyPlayerId === null) {
            return null;
        }

        $counterparty = 'Player' . $exchange->counterpartyPlayerId;

        $exchanges = $this->session->evaluateSocialExchanges($exchange->archetype, $counterparty, [
            sprintf('RapportLevel(SELF, %s)', $counterparty) => $this->rapport($exchange),
        ]);

        if ($exchanges === null) {
            return null;
        }

        foreach ($exchanges as $authored) {
            if ($authored['name'] === (string) config('ai.cognition.fatima.exchange', 'CooperativeMove')) {
                return $authored;
            }
        }

        return null;
    }

    private function withheld(SocialExchangeContext $exchange, SocialExchangeEvaluation $native): SocialExchangeEvaluation|null
    {
        // Only an acceptance can be withheld. A native decline, counter or clarification
        // already answers the exchange, and overriding it would widen the driver's say.
        if ($native->response !== AiSocialResponse::Accept) {
            return null;
        }

        $evidence = $this->evidence($exchange);

        // An absent answer or a usable volition set leaves the native stance in place; only
        // an authored exchange with an empty volition set means the counterparty's standing
        // does not support starting it.
        if ($evidence === null || $evidence['volitions'] !== []) {
            return null;
        }

        Log::info('The FAtiMA/CiF driver withheld a social exchange the native rules would have accepted.', [
            'exchange' => $exchange->exchangeId,
            'type' => $exchange->type->name,
        ]);

        return app()->makeWith(SocialExchangeEvaluation::class, [
            'response' => AiSocialResponse::Reject,
            'reason' => AiSocialResponseReason::SocialExchangeVolition,
            'step' => $evidence['step'],
        ]);
    }

    /**
     * CiF gates an exchange on a single rapport value, so the module's trust and
     * affinity are reduced to one figure on the driver's scale.
     */
    private function rapport(SocialExchangeContext $exchange): string
    {
        $scale = (float) config('ai.cognition.fatima.rapport_scale', 10.0);

        return (string) (int) round((($exchange->trust + $exchange->affinity) / 2) * $scale);
    }
}
