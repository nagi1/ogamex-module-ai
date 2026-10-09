<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Modules\AI\Actions\ReadAiHumanMessageAction;
use Modules\AI\Ai\Agents\OgameHumanTextAgent;
use Throwable;

#[Description('Write human-style messages for every inbound act with a language model, labelled by construction, as classifier training data.')]
#[Signature('ai:write-human-texts
        {--per=40 : Messages asked for per act}
        {--rounds=2 : Calls per act, so one act yields up to per x rounds distinct messages}
        {--out=storage/rl/text/human.jsonl : JSONL in the shape ai:export-text-readings writes}')]
class WriteAiHumanTexts extends Command
{
    public function handle(): int
    {
        $out = (string) $this->option('out');
        @mkdir(dirname($out), 0775, true);
        $seen = [];
        $total = 0;
        $sink = fopen($out, 'w');

        foreach (ReadAiHumanMessageAction::ACTS as $act => $meaning) {
            $written = 0;
            for ($round = 0; $round < (int) $this->option('rounds'); $round++) {
                foreach ($this->messagesFor($act, $meaning) as $text) {
                    $key = mb_strtolower(trim($text));
                    if ($key === '' || isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    fwrite($sink, json_encode(['kind' => 'reading', 'purpose' => 'inbound', 'text' => trim($text), 'answers' => ['act' => ['choice' => $act, 'confidence' => 1.0]], 'model' => 'written:' . config('ai.language.model')], JSON_UNESCAPED_UNICODE) . "\n");
                    $written++;
                }
            }
            $total += $written;
            $this->line(sprintf('%s: %d message(s)', $act, $written));
        }

        fclose($sink);
        $this->info("{$total} message(s) written to {$out}");

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function messagesFor(string $act, string $meaning): array
    {
        try {
            $response = app()->makeWith(OgameHumanTextAgent::class, ['act' => $act, 'meaning' => $meaning, 'count' => (int) $this->option('per')])
                ->prompt('Write them now.', provider: config('ai.language.provider'), model: config('ai.language.model'), timeout: 120);
        } catch (Throwable $exception) {
            $this->error("{$act}: " . $exception->getMessage());

            return [];
        }

        return $response instanceof StructuredAgentResponse ? array_values(array_filter((array) $response['messages'], is_string(...))) : [];
    }
}
