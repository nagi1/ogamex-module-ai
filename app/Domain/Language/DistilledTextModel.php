<?php

namespace Modules\AI\Domain\Language;

/**
 * A small model distilled from the classifier's own readings (llm-classifier-everywhere.md, the distillation ladder):
 * hashed word and word-pair features into a logistic regression, trained by rl/ogrl/distil_text.py. It answers one
 * question of one purpose in process, so the readings thousands of accounts need cost no call. The hashing here and in
 * the trainer must stay identical: crc32 of each lowercased word and adjacent pair, modulo the bucket count.
 */
class DistilledTextModel
{
    /** A model trained on fewer readings, or that agreed with the classifier less often on held-out ones, is not used. */
    private const MIN_EXAMPLES = 500;

    private const MIN_ACCURACY = 0.95;

    /** @var array<string, array<string, mixed>|null> */
    private static array $models = [];

    /**
     * The answer in the shape the classifier returns it (a choice, a boolean or a score), or null when no model exists
     * or it is less sure than the floor.
     *
     * @return array<string, mixed>|null
     */
    public function answer(string $purpose, string $question, string $text, float $floor): array|null
    {
        $model = $this->model($purpose, $question);
        if ($model === null) {
            return null;
        }

        $probabilities = $this->probabilities($model, $text);
        $best = (int) array_search(max($probabilities), $probabilities, true);
        if ($probabilities[$best] < $floor) {
            return null;
        }

        $labels = (array) $model['labels'];
        $named = array_combine($labels, $probabilities);

        return match ($model['kind']) {
            'boolean' => ['probability' => (float) ($named['true'] ?? 0.0)],
            'score' => ['score' => (float) $best, 'probabilities' => array_values($probabilities), 'legend' => $labels, 'confidence' => $probabilities[$best]],
            default => ['choice' => $labels[$best], 'probabilities' => $named, 'confidence' => $probabilities[$best]],
        };
    }

    /**
     * @param array<string, mixed> $model
     * @return list<float>
     */
    private function probabilities(array $model, string $text): array
    {
        $buckets = (int) $model['buckets'];
        $logits = array_map('floatval', (array) $model['bias']);
        foreach (self::features($text, $buckets) as $bucket) {
            foreach ((array) ($model['weights'][$bucket] ?? []) as $label => $weight) {
                $logits[$label] += (float) $weight;
            }
        }

        $peak = max($logits);
        $exponents = array_map(static fn (float $logit): float => exp($logit - $peak), $logits);
        $sum = array_sum($exponents);

        return array_map(static fn (float $exponent): float => $exponent / $sum, $exponents);
    }

    /** @return list<int> */
    public static function features(string $text, int $buckets): array
    {
        preg_match_all('/\w+/u', mb_strtolower($text), $words);
        $tokens = $words[0];
        for ($i = 1, $count = count($words[0]); $i < $count; $i++) {
            $tokens[] = $words[0][$i - 1] . ' ' . $words[0][$i];
        }

        return array_map(static fn (string $token): int => crc32($token) % $buckets, $tokens);
    }

    /** @return array<string, mixed>|null */
    private function model(string $purpose, string $question): array|null
    {
        $file = config('ai.classification.distilled_path') . '/' . $purpose . '.' . $question . '.json';
        if (!array_key_exists($file, self::$models)) {
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $trusted = is_array($decoded) && (int) ($decoded['examples'] ?? 0) >= self::MIN_EXAMPLES && (float) ($decoded['accuracy'] ?? 0) >= self::MIN_ACCURACY;
            self::$models[$file] = $trusted ? $decoded : null;
        }

        return self::$models[$file];
    }
}
