<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use JsonException;
use Modules\AI\Domain\Operability\AiPilotReport;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiActionReceipt;
use Modules\AI\Models\AiLanguageRequest;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiUsageReservation;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;
use RuntimeException;

/**
 * Builds the pilot report an operator shows: outcomes, failures, lateness and cost for one window.
 *
 * Everything here is read from records the module already keeps, which is what makes the report
 * evidence rather than a claim about the population. Human feedback is the one part the module
 * cannot measure, so it is read from a file the operator supplies and reported as absent when it
 * is missing instead of being filled in with an impression.
 */
class BuildAiPilotReportAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(int $days, string|null $feedbackPath = null): AiPilotReport
    {
        $now = $this->clock->now();
        $from = $now->subDays(max(1, $days));
        $completed = AiWorkItem::query()
            ->where('state', AiWorkState::Completed)
            ->whereBetween('updated_at', [$from, $now])
            ->get(['due_at', 'updated_at']);

        return app()->makeWith(AiPilotReport::class, [
            'days' => max(1, $days),
            'profiles' => AiProfile::query()->where('enabled', true)->count(),
            'work' => [
                'created' => AiWorkItem::query()->whereBetween('created_at', [$from, $now])->count(),
                'completed' => $completed->count(),
                'retried' => AiWorkItem::query()->whereBetween('created_at', [$from, $now])->where('attempts', '>', 1)->count(),
                'stuck' => AiWorkItem::query()
                    ->where('state', AiWorkState::Leased)
                    ->where('lease_until', '<', $now)
                    ->count(),
            ],
            'actions' => $this->actions($from, $now),
            'latencyMinutes' => $this->lateness($completed),
            'language' => $this->language($from, $now),
            'feedback' => $feedbackPath === null ? null : $this->feedback($feedbackPath),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function actions(CarbonImmutable $from, CarbonImmutable $now): array
    {
        return AiActionReceipt::query()
            ->whereBetween('created_at', [$from, $now])
            ->pluck('state')
            ->countBy(static fn (AiReceiptState $state): string => $state->name)
            ->all();
    }

    /**
     * How late the module acted on work it had decided was due. This is the module's own lateness,
     * not a server tick: this host progresses resources lazily and delivers fleet arrivals
     * through queued jobs, so there is no tick to measure against.
     *
     * @param \Illuminate\Support\Collection<int, AiWorkItem> $completed
     * @return list<float>
     */
    private function lateness($completed): array
    {
        return $completed
            ->map(static fn (AiWorkItem $item): float => max(0.0, $item->due_at->diffInMinutes($item->updated_at)))
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function language(CarbonImmutable $from, CarbonImmutable $now): array
    {
        $reservations = AiUsageReservation::query()
            ->whereBetween('reserved_for', [$from->toDateString(), $now->toDateString()])
            ->get(['actual_input_tokens', 'actual_output_tokens']);

        return [
            'attempts' => AiLanguageRequest::query()->whereBetween('created_at', [$from, $now])->count(),
            'tokens' => (int) $reservations->sum(static fn ($reservation): int => (int) $reservation->actual_input_tokens + (int) $reservation->actual_output_tokens),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function feedback(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Feedback file not found: ' . $path);
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Feedback file is not valid JSON: ' . $path, 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Feedback file must contain a JSON object: ' . $path);
        }

        return $decoded;
    }
}
