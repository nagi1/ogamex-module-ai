<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\AI\Models\AiTextReading;

#[Description('Export the classifier\'s readings of one purpose as JSONL labels for rl/ogrl/distil_text.py.')]
#[Signature('ai:export-text-readings
        {purpose : The reader\'s purpose, e.g. inbound or outbound}
        {--out= : The JSONL file; one line per reading: text and every answer}')]
class ExportAiTextReadings extends Command
{
    public function handle(): int
    {
        $out = (string) ($this->option('out') ?? storage_path('rl/text/' . $this->argument('purpose') . '.jsonl'));
        is_dir(dirname($out)) || mkdir(dirname($out), 0775, true);
        $handle = fopen($out, 'w');
        $count = 0;

        // Only the classifier's own labels: a distilled model's guesses are never stored, so it never learns from itself.
        foreach (AiTextReading::query()->where('purpose', $this->argument('purpose'))->where('provider', '!=', 'distilled')->lazyById() as $reading) {
            fwrite($handle, json_encode(['text' => $reading->text, 'answers' => $reading->answers], JSON_UNESCAPED_UNICODE) . "\n");
            $count++;
        }

        fclose($handle);
        $this->info("{$count} reading(s) written to {$out}");

        return self::SUCCESS;
    }
}
