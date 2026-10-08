<?php

use Symfony\Component\Yaml\Yaml;

if (!function_exists('voicedLines')) {
    /**
     * Every line a persona may send for an exchange: its own voice and the default lines whose key starts with the prefix,
     * so a reviewed voice file can change the words without changing what a test proves (which answer went out).
     *
     * @return list<string>
     */
    function voicedLines(string $archetype, string $prefix): array
    {
        $root = dirname(__DIR__, 2) . '/resources/behavior/';
        $voices = is_file($root . 'social-voices.yaml') ? (array) Yaml::parseFile($root . 'social-voices.yaml') : [];
        $lines = [];
        foreach ([(array) ($voices[$archetype] ?? []), (array) (Yaml::parseFile($root . 'social-lines.yaml')['default'] ?? [])] as $voice) {
            foreach ($voice as $key => $keyed) {
                if ($key === $prefix || str_starts_with((string) $key, $prefix . '.')) {
                    $lines = [...$lines, ...(array) $keyed];
                }
            }
        }

        return $lines;
    }
}
