<?php

namespace Modules\AI\Actions;

use Modules\AI\Domain\Conversation\InboundSocialExchange;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialResource;
use Modules\AI\Enums\AiSocialTerm;

/**
 * Recognises a known social exchange in an inbound English message.
 *
 * This is a bounded matcher for exchanges the module already has an authored answer for,
 * not an interpreter of free text: it decides which known exchange a message is, and nothing
 * else. A message it cannot place yields nothing, which is why the default answer to
 * ambiguous text is silence rather than a guess. Understanding arbitrary prose is the
 * optional provider's job and is never required on the ordinary path.
 *
 * Rules run from the most specific to the least, because one message carries several cues:
 * "hello, sorry about your fleet" is an apology, not a greeting.
 */
class ClassifyInboundSocialExchangeAction
{
    /**
     * Deciding on length is a guard against a stray word, not a claim about how people
     * write: a greeting or a thank-you inside a longer message is not what that message is.
     */
    private const MAXIMUM_ACKNOWLEDGEMENT_CHARACTERS = 80;

    /** Resource words, including the abbreviation the community actually types. */
    private const RESOURCE_WORDS = 'metal|crystal|deuterium|deut';

    public function handle(string $text): InboundSocialExchange|null
    {
        $normalised = strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? ''));

        if ($normalised === '') {
            return null;
        }

        return $this->apology($normalised)
            ?? $this->warning($normalised)
            ?? $this->ceasefire($normalised)
            ?? $this->cooperation($normalised)
            ?? $this->trade($normalised)
            ?? $this->acknowledgement($normalised);
    }

    private function apology(string $text): InboundSocialExchange|null
    {
        if (!$this->matches($text, 'sorry|apolog\w*|my bad|my fault|excuse me')) {
            return null;
        }

        // An apology only acknowledges harm when it names the harm. Inventing the
        // acknowledgement on the sender's behalf would skip the one question the module has
        // to ask, so an apology that says nothing about what happened is recorded as a
        // question back rather than as a repaired relationship.
        $acknowledged = $this->matches($text, 'attack\w*|raid\w*|hit|crash\w*|fleet|ninja\w*|farm\w*|spy|probe\w*|robbed|stole');

        return $this->exchange(AiSocialExchangeType::Apology, $acknowledged
            ? [AiSocialTerm::AcknowledgesHarm->value => true]
            : []);
    }

    private function warning(string $text): InboundSocialExchange|null
    {
        if (!$this->matches($text, 'stop attacking|or else|you will regret|last warning|back off|leave (me|us) alone|we will (crush|destroy|hunt|wipe)')) {
            return null;
        }

        $coercive = $this->matches($text, 'or else|you will regret|last warning|we will (crush|destroy|hunt|wipe)');

        return $this->exchange(AiSocialExchangeType::Warning, $coercive
            ? [AiSocialTerm::Coercive->value => true]
            : []);
    }

    private function ceasefire(string $text): InboundSocialExchange|null
    {
        if (!$this->matches($text, 'ceasefire|cease fire|truce|non[- ]aggression|nap|stop attacking each other')) {
            return null;
        }

        // No expiry is fabricated: the module cannot enforce an agreement through an
        // in-game message, so the exchange is recorded and answered honestly instead of
        // inventing a term it would then have to keep.
        return $this->exchange(AiSocialExchangeType::CeasefireRequest, []);
    }

    private function cooperation(string $text): InboundSocialExchange|null
    {
        $scope = $this->firstMatch($text, 'cooperat\w*|work together|team up|join forces|ally with (me|us)|join (my|our) alliance|alliance');

        return $scope === null ? null : $this->exchange(AiSocialExchangeType::CooperationRequest, [AiSocialTerm::Scope->value => $scope]);
    }

    private function trade(string $text): InboundSocialExchange|null
    {
        if (!$this->matches($text, 'trade|swap|exchange|sell|buy|deal')) {
            return null;
        }

        return $this->exchange(AiSocialExchangeType::TradeOffer, $this->tradeTerms($text));
    }

    private function acknowledgement(string $text): InboundSocialExchange|null
    {
        if (mb_strlen($text) > self::MAXIMUM_ACKNOWLEDGEMENT_CHARACTERS) {
            return null;
        }

        if ($this->matches($text, 'hi|hello|hey|greetings|good (morning|afternoon|evening)')) {
            return $this->exchange(AiSocialExchangeType::Greeting, []);
        }

        if ($this->matches($text, 'thanks|thank you|thx|cheers')) {
            return $this->exchange(AiSocialExchangeType::Thanks, []);
        }

        return null;
    }

    /**
     * Trade terms are read only far enough to tell a stated offer from an incomplete one.
     * Which resource was offered and which was requested is deliberately not resolved: the
     * module cannot complete a trade either way, so guessing the direction would only make
     * the recorded reason wrong.
     *
     * @return array<string, mixed>
     */
    private function tradeTerms(string $text): array
    {
        preg_match_all('/\b(?:' . self::RESOURCE_WORDS . ')\b/', $text, $resources);
        preg_match_all('/\d[\d.,]*/', $text, $amounts);
        $amounts = array_values(array_filter(
            $amounts[0],
            fn (string $amount): bool => $this->number($amount) > 0,
        ));

        if (count($resources[0]) < 2 || count($amounts) < 2) {
            return [];
        }

        return [
            AiSocialTerm::OfferedResource->value => $this->resource($resources[0][0]),
            AiSocialTerm::OfferedAmount->value => $this->number($amounts[0]),
            AiSocialTerm::RequestedResource->value => $this->resource($resources[0][1]),
            AiSocialTerm::RequestedAmount->value => $this->number($amounts[1]),
        ];
    }

    private function resource(string $word): string
    {
        return $word === 'deut' ? AiSocialResource::Deuterium->value : $word;
    }

    private function number(string $value): float
    {
        return (float) str_replace(',', '', $value);
    }

    private function matches(string $text, string $pattern): bool
    {
        return preg_match('/\b(?:' . $pattern . ')\b/', $text) === 1;
    }

    private function firstMatch(string $text, string $pattern): string|null
    {
        return preg_match('/\b(?:' . $pattern . ')\b/', $text, $matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param array<string, mixed> $terms
     */
    private function exchange(AiSocialExchangeType $type, array $terms): InboundSocialExchange
    {
        return app()->makeWith(InboundSocialExchange::class, ['type' => $type, 'terms' => $terms]);
    }
}
