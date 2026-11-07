<?php

namespace Modules\AI\Domain\Advisor;

use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Modules\AI\Actions\ReserveAiUsageAction;
use Modules\AI\Actions\ResolveAiProviderRouteAction;
use Modules\AI\Actions\SettleAiUsageReservationAction;
use Modules\AI\Domain\Conversation\UsageBudgetLimit;
use Modules\AI\Domain\Conversation\UsageBudgetLimits;
use Modules\AI\Domain\Conversation\UsageReservationRequest;
use Modules\AI\Enums\AiCampaignConsultationMode;
use Modules\AI\Enums\AiLanguageTaskKind;
use Modules\AI\Models\AiUsageReservation;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\AiRuntimeSettings;
use Throwable;

/**
 * The optional in-game advisor (DeepSeek Flash on the campaign-consultation route). The rules and
 * models propose; the advisor only chooses among their legal options, or "none" to keep the rule's
 * choice. Fail-closed: off, a cap, a timeout, a failure or an invalid answer all return the rule's
 * choice. `observe` records the advice beside the rule's choice; only `advice` applies it.
 *
 * Every call is reserved and settled on the usage ledger (tokens and cost) under its own universe
 * scope and a per-account key, so the advisor's daily caps never eat the reply lanes' budget.
 */
class Advisor
{
    /** An identical question is answered once in this window: a pass that repeats is not a new moment. */
    private const REMEMBER_HOURS = 6;

    public function __construct(private readonly AiClock $clock)
    {
    }

    /** Whether this area may consult at all; the caller skips building a brief when it is off. */
    public function enabled(AdvisorArea $area): bool
    {
        return app(AiRuntimeSettings::class)->advisorMode($area) !== AiCampaignConsultationMode::Off;
    }

    /** Whether this area's advice is applied (mode `advice`), not only recorded. */
    public function applies(AdvisorArea $area): bool
    {
        return app(AiRuntimeSettings::class)->advisorMode($area) === AiCampaignConsultationMode::Advice;
    }

    /**
     * Area 4's gate: an action whose cost or risk is at least the configured share of the account's
     * value is a big moment worth a consultation. Both figures are the account's own.
     */
    public function shouldConsult(float $cost, float $accountValue): bool
    {
        if (!$this->enabled(AdvisorArea::Costly) || $cost <= 0.0) {
            return false;
        }

        return $accountValue <= 0.0 || $cost >= app(AiRuntimeSettings::class)->advisorCostlyShare() * $accountValue;
    }

    public function consult(AdvisorQuestion $question): AdvisorVerdict
    {
        $rule = new AdvisorVerdict($question->ruleChoice);
        $mode = app(AiRuntimeSettings::class)->advisorMode($question->area);
        if ($mode === AiCampaignConsultationMode::Off || count($question->options) < 2 || !in_array($question->ruleChoice, $question->optionIds(), true)) {
            return $rule;
        }

        $brief = $question->brief();
        $memory = 'ai-advisor:' . sha1($question->playerId . '|' . $brief);
        $answer = Cache::get($memory);
        if (!is_array($answer)) {
            $answer = $this->ask($question, $brief, $mode);
            Cache::put($memory, $answer, now()->addHours(self::REMEMBER_HOURS));
        }

        $advice = $answer['advice'] ?? null;
        if ($mode !== AiCampaignConsultationMode::Advice || !is_string($advice) || $advice === 'none') {
            return new AdvisorVerdict($question->ruleChoice, null, is_string($advice) ? $advice : null);
        }

        return new AdvisorVerdict($advice, $this->keptText($question, $answer['text'] ?? null), $advice);
    }

    /**
     * One provider call, reserved and settled on the ledger, and its receipt.
     *
     * @return array{advice: string|null, text: string|null}
     */
    private function ask(AdvisorQuestion $question, string $brief, AiCampaignConsultationMode $mode): array
    {
        $settings = fn (string $key, int $fallback): int => max(1, (int) config('ai.campaign-consultation.advisor.' . $key, $fallback));
        $maximumInput = $settings('maximum_input_tokens', 3_000);
        $maximumOutput = $settings('maximum_output_tokens', 400);
        $receipt = ['status' => 'capped', 'advice' => null, 'reason' => null, 'evidence_ids' => [], 'provider' => null, 'model' => null, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => null, 'usage_reservation_id' => null];

        $ladder = app(ResolveAiProviderRouteAction::class)->handle(AiLanguageTaskKind::CampaignConsultation, $this->clock->now());
        $reservation = $ladder->isEmpty() ? null : app(ReserveAiUsageAction::class)->handle(app()->makeWith(UsageReservationRequest::class, [
            'universeScope' => 'advisor:' . (string) config('ai.campaign-consultation.universe_scope', 'default'),
            'playerId' => $question->playerId,
            'conversationKey' => 'advisor:' . $question->playerId,
            'requestKey' => 'advisor:' . $question->playerId . ':' . $question->area->value . ':' . sha1($brief) . ':' . $this->clock->now()->timestamp . ':' . random_int(0, PHP_INT_MAX),
            'inputTokens' => $maximumInput,
            'outputTokens' => $maximumOutput,
            'reservedAt' => $this->clock->now(),
        ]), $this->limits());

        if ($reservation === null) {
            $this->record($question, $mode, $receipt + ['status' => $ladder->isEmpty() ? 'no_provider' : 'capped']);

            return ['advice' => null, 'text' => null];
        }

        $text = null;
        try {
            $response = app()->makeWith(AdvisorAgent::class, [
                'maximumReasonCharacters' => $settings('maximum_reason_characters', 200),
                'maximumTextCharacters' => $settings('maximum_text_characters', 300),
            ])->prompt($brief, provider: $ladder->toProviderMap(), timeout: $settings('timeout_seconds', 20));
            $receipt['input_tokens'] = (int) ($response->usage->inputTokens ?? $response->usage->promptTokens ?? 0);
            $receipt['output_tokens'] = (int) ($response->usage->outputTokens ?? $response->usage->completionTokens ?? 0);
            $receipt['provider'] = $response->meta->provider;
            $receipt['model'] = $response->meta->model;
            [$receipt, $text] = $this->validated($question, $response, $receipt);
        } catch (Throwable $exception) {
            $timedOut = str_contains(strtolower($exception->getMessage()), 'time');
            $receipt['status'] = $timedOut ? 'timed_out' : 'failed';
            $receipt['provider'] = $ladder->primary()['provider'] ?? null;
            $receipt['model'] = $ladder->primary()['model'] ?? null;
            // A timed-out call may still complete remotely, so it is charged at the reserved maximum.
            [$receipt['input_tokens'], $receipt['output_tokens']] = $timedOut ? [$maximumInput, $maximumOutput] : [0, 0];
        }

        app(SettleAiUsageReservationAction::class)->handle(
            $reservation->id,
            min($receipt['input_tokens'], $maximumInput),
            min($receipt['output_tokens'], $maximumOutput),
            $this->clock->now(),
            $receipt['provider'],
            $receipt['model'],
        );
        $receipt['usage_reservation_id'] = $reservation->id;
        $receipt['cost'] = AiUsageReservation::query()->whereKey($reservation->id)->value('cost');
        $this->record($question, $mode, $receipt);

        return ['advice' => $receipt['advice'], 'text' => $text];
    }

    /**
     * The answer must name a supplied option (or "none") and cite only supplied evidence.
     *
     * @param array<string, mixed> $receipt
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function validated(AdvisorQuestion $question, mixed $response, array $receipt): array
    {
        $choice = $response instanceof StructuredAgentResponse ? ($response['choice'] ?? null) : null;
        $evidence = $response instanceof StructuredAgentResponse ? ($response['evidence_ids'] ?? null) : null;
        $reason = $response instanceof StructuredAgentResponse ? ($response['reason'] ?? null) : null;

        $valid = is_string($choice) && ($choice === 'none' || in_array($choice, $question->optionIds(), true))
            && is_array($evidence) && array_filter($evidence, static fn (mixed $id): bool => !is_int($id) || $id < 1 || $id > count($question->evidence)) === [];

        if (!$valid) {
            return [['status' => 'invalid'] + $receipt, null];
        }

        $text = $response['text'] ?? null;

        return [[
            'status' => 'completed',
            'advice' => $choice,
            'reason' => is_string($reason) ? mb_substr(trim($reason), 0, 255) : null,
            'evidence_ids' => array_values($evidence),
        ] + $receipt, is_string($text) ? trim($text) : null];
    }

    /** The advisor's line, only when the option is spoken and the line keeps every fact; else the authored line stands. */
    private function keptText(AdvisorQuestion $question, mixed $text): string|null
    {
        $maximum = (int) config('ai.campaign-consultation.advisor.maximum_text_characters', 300);
        if ($question->speech === null || !is_string($text) || $text === '' || mb_strlen($text) > $maximum || preg_match('/[—–]| - /u', $text) === 1) {
            return null;
        }

        foreach ($question->speech['facts'] ?? [] as $value) {
            if ($value !== null && $value !== '' && !str_contains(mb_strtolower($text), mb_strtolower((string) $value))) {
                return null;
            }
        }

        return $text;
    }

    /** @param array<string, mixed> $receipt */
    private function record(AdvisorQuestion $question, AiCampaignConsultationMode $mode, array $receipt): void
    {
        AdvisorReceipt::query()->create([
            'player_id' => $question->playerId,
            'area' => $question->area->value,
            'moment' => mb_substr($question->moment, 0, 64),
            'mode' => $mode->value,
            'rule_choice' => $question->ruleChoice,
            'applied' => $mode === AiCampaignConsultationMode::Advice && is_string($receipt['advice']) && $receipt['advice'] !== 'none' && $receipt['advice'] !== $question->ruleChoice,
        ] + $receipt);
    }

    private function limits(): UsageBudgetLimits
    {
        $limit = static fn (string $scope): UsageBudgetLimit => app()->makeWith(UsageBudgetLimit::class, [
            'attempts' => (int) config('ai.campaign-consultation.advisor.daily_limits.' . $scope . '.attempts'),
            'inputTokens' => (int) config('ai.campaign-consultation.advisor.daily_limits.' . $scope . '.input_tokens'),
            'outputTokens' => (int) config('ai.campaign-consultation.advisor.daily_limits.' . $scope . '.output_tokens'),
        ]);

        // The player row is shared with the reply lanes, so the advisor's own per-account cap is the
        // conversation key `advisor:<player>`; the shared row is bounded by the universe cap only.
        return app()->makeWith(UsageBudgetLimits::class, ['universe' => $limit('universe'), 'player' => $limit('universe'), 'conversation' => $limit('account')]);
    }
}
