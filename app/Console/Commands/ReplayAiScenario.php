<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Actions\ReplayAiScenarioAction;
use Modules\AI\Domain\Operability\AiScenarioReplay;
use RuntimeException;

#[Description('Replay a saved synthetic scenario through the decision engine. Writes nothing.')]
#[Signature('ai:replay-scenario {scenario : A scenario name shipped with the module, or a path to a JSON file}')]
class ReplayAiScenario extends Command
{
    public function handle(): int
    {
        $action = app(ReplayAiScenarioAction::class);

        try {
            $replay = $action->handle($this->scenarioPath($action, (string) $this->argument('scenario')));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->report($replay);

        return self::SUCCESS;
    }

    /**
     * A shipped scenario is named; anything else is a path, so an operator can replay a file they
     * wrote themselves. Only the name form is reachable from the admin page.
     */
    private function scenarioPath(ReplayAiScenarioAction $action, string $scenario): string
    {
        if (is_file($scenario)) {
            return $scenario;
        }

        return $action->pathFor($scenario);
    }

    private function report(AiScenarioReplay $replay): void
    {
        $this->newLine();
        $this->line(sprintf('Scenario %s · persona %s', $replay->name, $replay->persona));
        $this->line(sprintf(
            '  frozen at %s · decision key %s',
            $replay->observedAt?->toDateTimeString() ?? 'unset',
            $replay->decisionKey,
        ));
        $this->line(sprintf(
            '  chose %s · %s · score %.2f',
            $replay->selectedAction,
            $replay->selectedReason,
            $replay->selectedScore,
        ));
        $this->line('  decided by ' . $this->components($replay->components));
        $this->line('  ranked ' . $this->alternatives($replay->alternatives));

        if ($replay->refusals !== []) {
            $this->line('  refused ' . $this->refusals($replay->refusals));
        }

        $this->line('  this replay wrote nothing.');
    }

    /**
     * @param array<string, float> $components
     */
    private function components(array $components): string
    {
        return implode(', ', array_map(
            static fn (string $name, float $value): string => sprintf('%s %.2f', $name, $value),
            array_keys($components),
            array_values($components),
        ));
    }

    /**
     * @param list<array{action: string, score: float}> $alternatives
     */
    private function alternatives(array $alternatives): string
    {
        return implode(', ', array_map(
            static fn (array $entry): string => sprintf('%s %.2f', $entry['action'], $entry['score']),
            $alternatives,
        ));
    }

    /**
     * @param array<string, string> $refusals
     */
    private function refusals(array $refusals): string
    {
        return implode(', ', array_map(
            static fn (string $name, string $reason): string => $name . ' (' . $reason . ')',
            array_keys($refusals),
            array_values($refusals),
        ));
    }
}
