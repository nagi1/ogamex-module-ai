<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\ExplainAiDecisionAction;
use Modules\AI\Domain\Operability\AiDecisionExplanation;

#[Description('Explain recorded AI decisions: the action, the score that decided it and what lost.')]
#[Signature('ai:explain-decision
        {--trace= : Explain one recorded decision by id}
        {--player= : Explain the newest decisions of one account}
        {--limit=5 : How many decisions to explain}
        {--json : Print the same redacted fields as machine-readable JSON}')]
class ExplainAiDecision extends Command
{
    public function handle(): int
    {
        $explanations = $this->explanations();

        if ($explanations === []) {
            $this->error('No recorded decision matches.');

            return self::FAILURE;
        }

        // One read, two renderings: the JSON is the explanation's own shape, so a review can diff
        // decisions mechanically instead of reading sentences back out of the console lines.
        if ($this->option('json')) {
            $this->line(json_encode(
                array_map(static fn (AiDecisionExplanation $explanation): array => $explanation->toArray(), $explanations),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            ));

            return self::SUCCESS;
        }

        foreach ($explanations as $explanation) {
            $this->explain($explanation);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, AiDecisionExplanation>
     */
    private function explanations(): array
    {
        $action = app(ExplainAiDecisionAction::class);
        $traceId = $this->option('trace');

        // A scalar check rather than a string check: the value arrives as a string from a real
        // console and as whatever the caller passed from a test or a script, and both mean the
        // same thing here.
        if (is_scalar($traceId) && (string) $traceId !== '') {
            $explanation = $action->forTrace((int) $traceId);

            return $explanation === null ? [] : [$explanation];
        }

        $playerId = $this->option('player');

        if (is_scalar($playerId) && (string) $playerId !== '') {
            return $action->forPlayer((int) $playerId, max(1, (int) $this->option('limit')));
        }

        return $action->latest(max(1, (int) $this->option('limit')));
    }

    private function explain(AiDecisionExplanation $explanation): void
    {
        $this->newLine();
        $this->line(sprintf(
            'Trace %d · player %d · %s',
            $explanation->traceId,
            $explanation->playerId,
            $explanation->observedAt?->toDateTimeString() ?? 'time not recorded',
        ));
        $this->line(sprintf(
            '  chose %s · %s · score %.2f',
            $explanation->selectedAction,
            $explanation->selectedReason,
            $explanation->selectedScore,
        ));
        $this->line('  decided by ' . $this->pairs($explanation->components));
        $this->line('  ranked ' . $this->scores($explanation->alternatives));

        if ($explanation->refusals !== []) {
            $this->line('  refused ' . $this->pairs($explanation->refusals));
        }

        if ($explanation->evidence !== []) {
            $this->line('  evidence ' . $this->pairs($explanation->evidence));
        }
    }

    /**
     * Scores are numbers, labels and refusal reasons are words, and the difference is worth
     * seeing at a glance on a console line.
     *
     * @param array<string, float|string> $values
     */
    private function pairs(array $values): string
    {
        return implode(', ', array_map(
            static fn (string $name, float|string $value): string => is_float($value)
                ? sprintf('%s %.2f', $name, $value)
                : $name . ' (' . $value . ')',
            array_keys($values),
            array_values($values),
        ));
    }

    /**
     * @param list<array{action: string, score: float}> $alternatives
     */
    private function scores(array $alternatives): string
    {
        return implode(', ', array_map(
            static fn (array $entry): string => sprintf('%s %.2f', $entry['action'], $entry['score']),
            $alternatives,
        ));
    }
}
