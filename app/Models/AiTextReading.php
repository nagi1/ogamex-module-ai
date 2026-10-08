<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * What the classifier read in one text for one purpose, keyed by the question names the reader asked.
 *
 * @property string $purpose
 * @property string $text_hash
 * @property string $text
 * @property array<string, array<string, mixed>> $answers
 * @property string $provider
 * @property string $model
 */
#[Unguarded]
class AiTextReading extends Model
{
    protected function casts(): array
    {
        return ['answers' => 'array'];
    }

    /** The chosen option, or null when the classifier was less sure of it than the floor. */
    public function choice(string $question, float $floor = 0.5): string|null
    {
        $answer = $this->answers[$question] ?? [];
        $choice = $answer['choice'] ?? null;

        return is_string($choice) && (float) ($answer['probabilities'][$choice] ?? 0.0) >= $floor ? $choice : null;
    }

    public function isTrue(string $question, float $threshold = 0.5): bool
    {
        return (float) ($this->answers[$question]['probability'] ?? 0.0) >= $threshold;
    }

    /** The most probable level of a score question, counted from zero, or null when it was not asked. */
    public function level(string $question): int|null
    {
        $answer = $this->answers[$question] ?? null;
        if ($answer === null) {
            return null;
        }

        $probabilities = (array) ($answer['probabilities'] ?? []);

        return $probabilities === [] ? (int) round((float) ($answer['score'] ?? 0)) : (int) array_search(max($probabilities), $probabilities, true);
    }
}
