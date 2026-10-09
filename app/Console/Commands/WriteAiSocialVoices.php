<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Modules\AI\Actions\BuildAuthoredSocialReplyAction;
use Modules\AI\Actions\GuardAiOutboundTextAction;
use Modules\AI\Ai\Agents\OgameVoiceAgent;
use Symfony\Component\Yaml\Yaml;
use Throwable;

#[Description('Draft persona voices for the reply lines with a language model, each line checked by the classifier.')]
#[Signature('ai:write-social-voices
        {archetypes* : miner, raider, fleeter, turtle, hybrid, trader, casual}
        {--out= : Write here instead of resources/behavior/social-voices.yaml}')]
class WriteAiSocialVoices extends Command
{
    private const SAMPLES = ['{attacker}' => 'Zorak', '{victim}' => 'Lena', '{coords}' => '2:45:7', '{name}' => 'Kira', '{tag}' => 'ORBITAL', '{amount}' => '50000', '{hits}' => '4', '{ships}' => '120', '{target}' => 'Zorak'];

    public function handle(): int
    {
        config(['ai.classification.enabled' => true]);
        $root = dirname(__DIR__, 3);
        $defaults = (array) (Yaml::parseFile($root . '/resources/behavior/social-lines.yaml')['default'] ?? []);
        $path = (string) ($this->option('out') ?? $root . BuildAuthoredSocialReplyAction::VOICES);
        $voices = is_file($path) ? (array) Yaml::parseFile($path) : [];

        foreach ((array) $this->argument('archetypes') as $archetype) {
            try {
                $response = app()->makeWith(OgameVoiceAgent::class, ['archetype' => $archetype])
                    ->prompt((string) json_encode($defaults, JSON_PRETTY_PRINT), provider: config('ai.language.provider'), model: config('ai.language.model'), timeout: 120);
            } catch (Throwable $exception) {
                $this->error("{$archetype}: " . $exception->getMessage());

                continue;
            }

            $voice = $response instanceof StructuredAgentResponse ? $this->checked((array) $response['keys'], $defaults) : [];
            $voices[$archetype] = $voice;
            $this->line(sprintf('%s: %d key(s), %d line(s)', $archetype, count($voice), array_sum(array_map('count', $voice))));
        }

        file_put_contents($path, "# Persona voices drafted by ai:write-social-voices; review before merging. Keys as in social-lines.yaml.\n" . Yaml::dump($voices, 3, 2));

        return self::SUCCESS;
    }

    /**
     * Only keys the defaults have, only lines the classifier lets through to a human.
     *
     * @param list<array<string, mixed>> $keys
     * @param array<string, mixed> $defaults
     * @return array<string, list<string>>
     */
    private function checked(array $keys, array $defaults): array
    {
        $voice = [];
        foreach ($keys as $entry) {
            $key = (string) ($entry['key'] ?? '');
            if (!array_key_exists($key, $defaults)) {
                continue;
            }

            // A line keeps the slots all the defaults share, and is judged the way a player sees it: with the slots filled.
            $slots = $this->sharedSlots((array) $defaults[$key]);
            $lines = array_values(array_filter((array) ($entry['lines'] ?? []), fn ($line): bool => is_string($line)
                && array_reduce($slots, static fn (bool $kept, string $slot): bool => $kept && str_contains($line, $slot), true)
                && app(GuardAiOutboundTextAction::class)->reads(strtr($line, self::SAMPLES))));
            if ($lines !== []) {
                $voice[$key] = $lines;
            }
        }

        return $voice;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function sharedSlots(array $lines): array
    {
        return array_values(array_filter(array_keys(self::SAMPLES), static fn (string $slot): bool => $lines !== [] && count(array_filter($lines, static fn (string $line): bool => str_contains($line, $slot))) === count($lines)));
    }
}
