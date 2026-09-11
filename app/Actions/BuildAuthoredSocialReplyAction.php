<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Enums\AiSocialTerm;
use Modules\AI\Models\AiSocialExchange;
use Modules\AI\Support\RandomSource;

class BuildAuthoredSocialReplyAction
{
    public function __construct(private RandomSource $randomSource)
    {
    }

    public function handle(AiSocialExchange $exchange, int $personaSeed): string|null
    {
        if ($exchange->response === null) {
            return null;
        }

        $response = $exchange->response;
        $lines = match ($exchange->type) {
            AiSocialExchangeType::HelpRequest => $this->helpReplyLines($response),
            AiSocialExchangeType::Apology => $this->apologyReplyLines($response),
            AiSocialExchangeType::Greeting => $this->greetingReplyLines(),
            AiSocialExchangeType::Thanks => $this->thanksReplyLines(),
            AiSocialExchangeType::TradeOffer => $this->tradeReplyLines($response),
            AiSocialExchangeType::CeasefireRequest => $this->ceasefireReplyLines($response),
            AiSocialExchangeType::Warning => $this->warningReplyLines($response),
            AiSocialExchangeType::CooperationRequest => $this->cooperationReplyLines($response),
            AiSocialExchangeType::CompensationOffer => $this->compensationReplyLines($response),
        };
        $index = (int) floor($this->randomSource->unitInterval($personaSeed, 'social-exchange:' . $exchange->id . ':' . $exchange->revision) * count($lines));
        $line = $lines[$index];
        $amount = $exchange->response_terms[AiSocialTerm::Amount->value] ?? null;

        return is_int($amount) || is_float($amount) ? str_replace('{amount}', (string) $amount, $line) : $line;
    }

    /**
     * @return list<string>
     */
    private function helpReplyLines(AiSocialResponse $response): array
    {
        return match ($response) {
            AiSocialResponse::Accept => ['I can help with the requested amount.', 'Your request is accepted; I will provide the agreed amount.'],
            AiSocialResponse::Reject => ['I cannot accept this request.', 'I must decline this request for now.'],
            AiSocialResponse::Counter => ['I can only offer {amount}.', 'I cannot meet the full request; I can offer {amount}.'],
            AiSocialResponse::Clarify => ['Please confirm the amount and terms.', 'I need clearer terms before I can respond.'],
        };
    }

    /**
     * @return list<string>
     */
    private function apologyReplyLines(AiSocialResponse $response): array
    {
        return match ($response) {
            AiSocialResponse::Accept => ['I accept your apology, but I remember what happened.', 'Your apology is acknowledged; trust will take time to rebuild.'],
            AiSocialResponse::Reject => ['An apology alone does not repair this harm.', 'I cannot accept this apology while the threat remains.'],
            AiSocialResponse::Counter => ['I need a concrete repair before we can move forward.', 'Show how you will repair this harm first.'],
            AiSocialResponse::Clarify => ['Please acknowledge the harm and explain how you will repair it.', 'I need a clearer acknowledgment before we discuss forgiveness.'],
        };
    }

    /** @return list<string> */
    private function greetingReplyLines(): array
    {
        return ['Greetings.', 'Hello.'];
    }

    /** @return list<string> */
    private function thanksReplyLines(): array
    {
        return ['You are welcome.', 'Acknowledged.'];
    }

    /** @return list<string> */
    private function tradeReplyLines(AiSocialResponse $response): array
    {
        return match ($response) {
            AiSocialResponse::Clarify => ['Please state both resources and exact amounts.'],
            AiSocialResponse::Reject => ['I cannot authorize a trade without a validated transport path.'],
            AiSocialResponse::Accept, AiSocialResponse::Counter => ['I cannot authorize this trade.'],
        };
    }

    /** @return list<string> */
    private function ceasefireReplyLines(AiSocialResponse $response): array
    {
        return match ($response) {
            AiSocialResponse::Reject => ['I cannot accept this ceasefire under the current threat.'],
            AiSocialResponse::Clarify, AiSocialResponse::Accept, AiSocialResponse::Counter => ['I cannot enforce a ceasefire through this channel.'],
        };
    }

    /** @return list<string> */
    private function warningReplyLines(AiSocialResponse $response): array
    {
        return match ($response) {
            AiSocialResponse::Reject => ['I will not accept coercive terms.'],
            AiSocialResponse::Clarify, AiSocialResponse::Accept, AiSocialResponse::Counter => ['Your warning is noted; no commitment is implied.'],
        };
    }

    /** @return list<string> */
    private function cooperationReplyLines(AiSocialResponse $response): array
    {
        return match ($response) {
            AiSocialResponse::Reject => ['I cannot cooperate under the current relationship conditions.'],
            AiSocialResponse::Clarify => ['I cannot authorize that cooperation without a supported game path.'],
            AiSocialResponse::Accept, AiSocialResponse::Counter => ['I cannot authorize that cooperation.'],
        };
    }

    /** @return list<string> */
    private function compensationReplyLines(AiSocialResponse $response): array
    {
        return match ($response) {
            AiSocialResponse::Accept => ['Your compensation is recorded and remains due.'],
            AiSocialResponse::Reject => ['I cannot accept this compensation while the threat remains.'],
            AiSocialResponse::Clarify => ['State the exact compensation and due time.'],
            AiSocialResponse::Counter => ['I need exact compensation terms before I can respond.'],
        };
    }
}
