<?php

namespace Modules\AI\Actions;

use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Modules\AI\Ai\Agents\OgameMessageAgent;
use Modules\AI\Domain\Advisor\AdvisorFacts;
use Modules\AI\Domain\Conversation\UsageBudgetLimit;
use Modules\AI\Domain\Conversation\UsageBudgetLimits;
use Modules\AI\Domain\Conversation\UsageReservationRequest;
use Modules\AI\Enums\AiLanguageTaskKind;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\AiRuntimeSettings;
use Throwable;

/**
 * Words one of the account's own lines with the language model (laravel/ai), from the facts of the situation, and hands
 * back the authored line whenever the model is off, over budget, slow, or says something that drops a fact. The
 * decision behind the line is never the model's, and it is spoken exactly as the authored line would have been.
 */
class ComposeAiLineAction
{
    private const MAXIMUM_CHARACTERS = 160;

    private const RESERVED_INPUT_TOKENS = 700;

    private const RESERVED_OUTPUT_TOKENS = 120;

    public function __construct(private readonly AiClock $clock)
    {
    }

    /**
     * @param array<string, scalar|null> $facts what the line may refer to; every non-empty value must survive into the text
     * @param bool $advised an advisor area in `advice` mode asks for the wording even while general composition is off
     */
    public function handle(AiProfile $profile, string $key, string $authored, array $facts = [], int|null $counterpartyId = null, bool $advised = false): string
    {
        $settings = app(AiRuntimeSettings::class);
        if (!$settings->languageEnabled() || (!$settings->languageCompose() && !$advised)) {
            return $authored;
        }

        $facts = array_filter($facts, static fn (mixed $value): bool => $value !== null && $value !== '');
        $cacheKey = 'ai-compose:' . sha1($profile->player_id . '|' . $key . '|' . json_encode($facts) . '|' . $authored . '|' . $counterpartyId);
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        $inner = $counterpartyId === null ? [] : app(ReadAiInnerStateAction::class)->handle((int) $profile->player_id, $counterpartyId, $authored);
        $language = $counterpartyId === null ? null : app(AdvisorFacts::class)->language($counterpartyId);
        $line = $this->ask($profile, $key, $authored, $facts, $inner, $cacheKey, $language) ?? $authored;
        Cache::put($cacheKey, $line, now()->addHours(6));

        return $line;
    }

    /**
     * @param array<string, scalar> $facts
     * @param array<string, string|list<string>> $inner
     * @param string|null $language the recipient's language code, so the line is written in it
     */
    private function ask(AiProfile $profile, string $key, string $authored, array $facts, array $inner, string $cacheKey, string|null $language): string|null
    {
        $ladder = app(ResolveAiProviderRouteAction::class)->handle(AiLanguageTaskKind::MessageComposition, $this->clock->now());
        if ($ladder->isEmpty()) {
            return null;
        }

        $reservation = app(ReserveAiUsageAction::class)->handle(
            app()->makeWith(UsageReservationRequest::class, [
                'universeScope' => (string) config('ai.language.universe_scope', 'default'),
                'playerId' => (int) $profile->player_id,
                'conversationKey' => 'compose:' . $profile->player_id . ':' . $key,
                'requestKey' => $cacheKey . ':' . $this->clock->now()->timestamp,
                'inputTokens' => self::RESERVED_INPUT_TOKENS,
                'outputTokens' => self::RESERVED_OUTPUT_TOKENS,
                'reservedAt' => $this->clock->now(),
            ]),
            $this->limits(),
        );
        if ($reservation === null) {
            return null;
        }

        $input = $output = 0;
        try {
            $response = app()->makeWith(OgameMessageAgent::class, [
                'archetype' => strtolower((string) $profile->archetype?->name),
                'maximumCharacters' => (string) self::MAXIMUM_CHARACTERS,
            ])->prompt(
                json_encode(array_filter(['situation' => $key, 'facts' => $facts, 'inner_state' => $inner, 'example_line' => $authored, 'write_in_language' => $language], static fn (mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR),
                provider: $ladder->toProviderMap(),
                timeout: app(AiRuntimeSettings::class)->languageTimeoutSeconds(),
            );
            $input = (int) ($response->usage->promptTokens ?? 0);
            $output = (int) ($response->usage->outputTokens ?? $response->usage->completionTokens ?? 0);
            $text = $response instanceof StructuredAgentResponse ? trim((string) ($response['text'] ?? '')) : '';
        } catch (Throwable) {
            $text = '';
        }

        app(SettleAiUsageReservationAction::class)->handle(
            $reservation->id,
            min($input, self::RESERVED_INPUT_TOKENS),
            min($output, self::RESERVED_OUTPUT_TOKENS),
            $this->clock->now(),
        );

        return $this->keepsTheFacts($text, $facts) ? $text : null;
    }

    /** @param array<string, scalar> $facts */
    private function keepsTheFacts(string $text, array $facts): bool
    {
        // A dash used as punctuation reads as a model; players type a comma or start a new sentence.
        if ($text === '' || mb_strlen($text) > self::MAXIMUM_CHARACTERS || preg_match('/[—–]| - /u', $text) === 1) {
            return false;
        }

        foreach ($facts as $value) {
            if (!str_contains(mb_strtolower($text), mb_strtolower((string) $value))) {
                return false;
            }
        }

        return true;
    }

    private function limits(): UsageBudgetLimits
    {
        $limit = static fn (string $scope): UsageBudgetLimit => app()->makeWith(UsageBudgetLimit::class, [
            'attempts' => (int) config('ai.language.compose_limits.' . $scope . '.attempts'),
            'inputTokens' => (int) config('ai.language.compose_limits.' . $scope . '.input_tokens'),
            'outputTokens' => (int) config('ai.language.compose_limits.' . $scope . '.output_tokens'),
        ]);

        return app()->makeWith(UsageBudgetLimits::class, ['universe' => $limit('universe'), 'player' => $limit('player'), 'conversation' => $limit('conversation')]);
    }
}
