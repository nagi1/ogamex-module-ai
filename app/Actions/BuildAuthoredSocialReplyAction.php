<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialResponse;
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

        if ($exchange->type === AiSocialExchangeType::Apology) {
            return $this->apologyReply($exchange, $personaSeed);
        }

        $lines = match ($exchange->response) {
            AiSocialResponse::Accept => ['I can help with the requested amount.', 'Your request is accepted; I will provide the agreed amount.'],
            AiSocialResponse::Reject => ['I cannot accept this request.', 'I must decline this request for now.'],
            AiSocialResponse::Counter => ['I can only offer {amount}.', 'I cannot meet the full request; I can offer {amount}.'],
            AiSocialResponse::Clarify => ['Please confirm the amount and terms.', 'I need clearer terms before I can respond.'],
        };
        $index = (int) floor($this->randomSource->unitInterval($personaSeed, 'social-exchange:' . $exchange->id . ':' . $exchange->revision) * count($lines));
        $line = $lines[$index];
        $amount = $exchange->response_terms['amount'] ?? null;

        return is_int($amount) || is_float($amount) ? str_replace('{amount}', (string) $amount, $line) : $line;
    }

    private function apologyReply(AiSocialExchange $exchange, int $personaSeed): string
    {
        $lines = match ($exchange->response) {
            AiSocialResponse::Accept => ['I accept your apology, but I remember what happened.', 'Your apology is acknowledged; trust will take time to rebuild.'],
            AiSocialResponse::Reject => ['An apology alone does not repair this harm.', 'I cannot accept this apology while the threat remains.'],
            AiSocialResponse::Counter => ['I need a concrete repair before we can move forward.', 'Show how you will repair this harm first.'],
            AiSocialResponse::Clarify => ['Please acknowledge the harm and explain how you will repair it.', 'I need a clearer acknowledgment before we discuss forgiveness.'],
        };
        $index = (int) floor($this->randomSource->unitInterval($personaSeed, 'social-apology:' . $exchange->id . ':' . $exchange->revision) * count($lines));

        return $lines[$index];
    }
}
