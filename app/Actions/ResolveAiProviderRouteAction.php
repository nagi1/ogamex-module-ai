<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\AI\Domain\Language\AiProviderLadder;
use Modules\AI\Enums\AiLanguageTaskKind;
use Modules\AI\Enums\AiProviderWindowGate;

/**
 * Turns routing configuration into the ordered ladder one request will be answered by.
 *
 * This is policy, not failover: the SDK already walks an ordered provider list and reports which
 * rung it failed over from, so the module decides the order and lets the SDK do the walking.
 *
 * A vendor with no credential is dropped quietly, because a missing key is that vendor switched
 * off. An unknown vendor or a malformed window throws instead, because a rung silently dropped
 * for a typo is a vendor nobody can find out about.
 */
class ResolveAiProviderRouteAction
{
    public function handle(AiLanguageTaskKind $task, CarbonImmutable $now): AiProviderLadder
    {
        $routing = (bool) config('ai.routing.enabled', false);
        $rungs = $routing ? $this->gatedRungs($task, $now) : [];

        if ($rungs === []) {
            // The single configured pair is the last resort, exactly as it behaved before ladders
            // existed, and it is only credential-checked once routing is what asked for it.
            $rungs = $this->configuredPair($task, $routing);
        }

        return app()->makeWith(AiProviderLadder::class, ['rungs' => $rungs]);
    }

    /** @return list<array{provider: string, model: string}> */
    private function gatedRungs(AiLanguageTaskKind $task, CarbonImmutable $now): array
    {
        $rungs = [];

        foreach ((array) config('ai.routing.ladders.' . $task->value, []) as $configured) {
            $rung = $this->rung($configured, $task);

            if (!$rung['gate']->allows($this->isPeak($rung['provider'], $now)) || !$this->hasCredential($rung['provider'])) {
                continue;
            }

            $rungs[] = ['provider' => $rung['provider'], 'model' => $rung['model']];
        }

        return $rungs;
    }

    /**
     * @return array{provider: string, model: string, gate: AiProviderWindowGate}
     */
    private function rung(mixed $configured, AiLanguageTaskKind $task): array
    {
        if (!is_array($configured) || !isset($configured['provider'], $configured['model'])) {
            throw new InvalidArgumentException('Every rung of the ' . $task->value . ' ladder needs a provider and a model.');
        }

        $provider = (string) $configured['provider'];

        if (!is_array(config('ai.providers.' . $provider))) {
            throw new InvalidArgumentException('Unknown provider [' . $provider . '] in the ' . $task->value . ' ladder.');
        }

        return [
            'provider' => $provider,
            'model' => (string) $configured['model'],
            'gate' => $this->gate($configured['during'] ?? null, $task),
        ];
    }

    private function gate(mixed $configured, AiLanguageTaskKind $task): AiProviderWindowGate
    {
        if ($configured === null) {
            return AiProviderWindowGate::Any;
        }

        return AiProviderWindowGate::tryFrom((string) $configured)
            ?? throw new InvalidArgumentException('Unknown window gate [' . (string) $configured . '] in the ' . $task->value . ' ladder; use peak, off_peak or any.');
    }

    private function hasCredential(string $provider): bool
    {
        $key = config('ai.providers.' . $provider . '.key');

        return is_string($key) && trim($key) !== '';
    }

    private function isPeak(string $provider, CarbonImmutable $now): bool
    {
        $window = config('ai.routing.vendors.' . $provider . '.window');

        if (!is_string($window) || $window === '') {
            return false;
        }

        $definition = config('ai.routing.windows.' . $window);

        if (!is_array($definition)) {
            throw new InvalidArgumentException('Unknown peak window [' . $window . '] for vendor [' . $provider . '].');
        }

        return $this->covers($definition, $now);
    }

    /** @param array<string, mixed> $definition */
    private function covers(array $definition, CarbonImmutable $now): bool
    {
        $utc = $now->utc();

        if (!in_array($utc->isoWeekday(), array_map(intval(...), array_values((array) ($definition['days'] ?? []))), true)) {
            return false;
        }

        $minuteOfDay = ($utc->hour * 60) + $utc->minute;

        foreach ((array) ($definition['periods'] ?? []) as $period) {
            [$start, $end] = $this->minutes($period);

            if ($minuteOfDay >= $start && $minuteOfDay < $end) {
                return true;
            }
        }

        return false;
    }

    /** @return array{int, int} */
    private function minutes(mixed $period): array
    {
        $parts = is_array($period) ? array_values($period) : [];

        if (count($parts) !== 2 || !is_string($parts[0]) || !is_string($parts[1])) {
            throw new InvalidArgumentException('A peak window period must be a [start, end] pair of HH:MM strings.');
        }

        $start = $this->minuteOfDay($parts[0]);
        $end = $this->minuteOfDay($parts[1]);

        if ($end <= $start) {
            // A wrapping period is ambiguous about which day owns it, and vendors publish two
            // periods instead; refusing it here keeps a misread schedule from looking deliberate.
            throw new InvalidArgumentException('A peak window period must not wrap past midnight; publish two periods instead.');
        }

        return [$start, $end];
    }

    private function minuteOfDay(string $value): int
    {
        $matched = preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $value, $parts) === 1;

        if (!$matched) {
            throw new InvalidArgumentException('A peak window time must be HH:MM in UTC; got [' . $value . '].');
        }

        return ((int) $parts[1] * 60) + (int) $parts[2];
    }

    /** @return list<array{provider: string, model: string}> */
    private function configuredPair(AiLanguageTaskKind $task, bool $requireCredential): array
    {
        $provider = (string) config($this->providerConfigKey($task) . '.provider', 'openai');

        if ($requireCredential && !$this->hasCredential($provider)) {
            return [];
        }

        return [['provider' => $provider, 'model' => (string) config($this->providerConfigKey($task) . '.model', 'gpt-5-mini')]];
    }

    private function providerConfigKey(AiLanguageTaskKind $task): string
    {
        return $task === AiLanguageTaskKind::CampaignConsultation
            ? 'ai.campaign-consultation'
            : 'ai.language';
    }
}
