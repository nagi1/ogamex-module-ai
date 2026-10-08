<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Modules\AI\Actions\ReadAiTextAction;
use Modules\AI\Actions\SummarizeAiWorldAction;
use Modules\AI\Ai\Agents\OgameWorldJudgeAgent;
use Throwable;

#[Description('Grade a finished world like a veteran player would: great war, politics, human-likeness, and findings.')]
#[Signature('ai:judge-world
        {--sqlite= : A late world\'s end.sqlite; the default connection when omitted}
        {--out= : Write the judgement here as markdown}')]
class JudgeAiWorld extends Command
{
    public function handle(): int
    {
        $connection = $this->connection();
        config(['ai.classification.enabled' => true]);
        $record = app(SummarizeAiWorldAction::class)->handle($connection);

        // The classifier's read of what players said: a second, cheap opinion on the politics beside the judge's.
        $talk = (string) strstr($record, '# Last things players said');
        $reading = app(ReadAiTextAction::class)->handle('world-politics', $talk, [
            'real' => new Score('How much does this read like real OGame alliance politics and player talk?', ['not at all', 'a little', 'mostly', 'fully']),
        ]);

        try {
            $judgement = app(OgameWorldJudgeAgent::class)->prompt($record, provider: config('ai.language.provider'), model: config('ai.language.model'), timeout: 120);
        } catch (Throwable $exception) {
            $this->error('The judge failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        if (!$judgement instanceof StructuredAgentResponse) {
            $this->error('The judge answered without structure.');

            return self::FAILURE;
        }

        $markdown = $this->markdown($judgement, $reading?->level('real'));
        foreach (explode("\n", trim($markdown)) as $line) {
            $this->line($line);
        }
        if ($this->option('out') !== null) {
            file_put_contents((string) $this->option('out'), $markdown);
        }

        return self::SUCCESS;
    }

    private function connection(): string
    {
        $path = $this->option('sqlite');
        if ($path === null) {
            return (string) config('database.default');
        }

        config(['database.connections.ai_judged_world' => ['driver' => 'sqlite', 'database' => (string) $path, 'foreign_key_constraints' => false]]);

        return 'ai_judged_world';
    }

    private function markdown(StructuredAgentResponse $judgement, int|null $talk): string
    {
        $lines = [
            sprintf('great war %d/3, politics %d/3, human-like %d/3, talk (classifier) %s/3', $judgement['great_war'], $judgement['politics'], $judgement['human_like'], $talk ?? '-'),
            '',
        ];
        foreach ((array) $judgement['findings'] as $finding) {
            $lines[] = sprintf('- **%s**: %s → %s', $finding['aspect'] ?? '', $finding['observation'] ?? '', $finding['better_play'] ?? '');
        }

        return implode("\n", $lines) . "\n";
    }
}
