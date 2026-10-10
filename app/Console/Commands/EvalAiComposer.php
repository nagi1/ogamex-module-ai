<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Modules\AI\Actions\ComposeAiLineAction;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Models\AiProfile;
use Symfony\Component\Yaml\Yaml;

#[Description('Measure the composer prompt on the authored alliance lines: how often the model line is kept, and whether it reads like a player.')]
#[Signature('ai:eval-composer {--rounds=2 : Tags tried per authored line} {--player= : An AI player id; the first enabled profile when omitted}')]
class EvalAiComposer extends Command
{
    private const TAGS = ['ZRK', 'NOVA', 'ORD', 'VX', 'KRN', 'HAWK'];

    public function handle(): int
    {
        $profile = $this->option('player') === null
            ? AiProfile::query()->where('enabled', true)->first()
            : AiProfile::query()->where('player_id', (int) $this->option('player'))->first();
        // An unsaved raider when the database holds no account: the prompt is what is measured, not an account.
        $profile ??= app()->makeWith(AiProfile::class, ['attributes' => ['player_id' => 0, 'archetype' => AiArchetype::Raider, 'random_seed' => 1]]);

        $banned = (array) Yaml::parseFile(dirname(__DIR__, 3) . '/resources/behavior/composer.yaml')['banned'];
        $lines = (array) Yaml::parseFile(dirname(__DIR__, 3) . '/resources/behavior/alliance-war.yaml')['lines'];
        $said = [];
        $asked = $worded = $dashes = $slop = 0;

        foreach ($lines as $key => $variants) {
            foreach (array_slice(self::TAGS, 0, max(1, (int) $this->option('rounds'))) as $tag) {
                $authored = str_replace('{tag}', $tag, (string) (is_array($variants) ? $variants[0] : $variants));
                Cache::forget('ai-compose:' . sha1($profile->player_id . '|alliance_politics.' . $key . '|' . json_encode(['tag' => $tag]) . '|' . $authored . '|'));
                $line = app(ComposeAiLineAction::class)->handle($profile, 'alliance_politics.' . $key, $authored, ['tag' => $tag]);
                $asked++;
                if ($line === $authored) {
                    continue;
                }
                $worded++;
                $said[] = $line;
                $dashes += preg_match('/[—–]| - /u', $line);
                $slop += count(array_filter($banned, static fn (string $word): bool => stripos($line, $word) !== false));
            }
        }

        $this->table(['asked', 'worded by model', 'fell back', 'dashes', 'slop words', 'distinct lines'], [[$asked, $worded, $asked - $worded, $dashes, $slop, count(array_unique($said))]]);
        foreach (array_slice($said, 0, 12) as $line) {
            $this->line('  ' . $line);
        }

        return self::SUCCESS;
    }
}
