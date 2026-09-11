<?php

namespace Modules\AI\Actions;

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
}
