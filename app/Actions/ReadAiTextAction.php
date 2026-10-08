<?php

namespace Modules\AI\Actions;

use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Classification;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\Responses\Data\Answer;
use Modules\AI\Domain\Language\DistilledTextModel;
use Modules\AI\Models\AiTextReading;
use Throwable;

/**
 * Asks the classifier (Jev, through laravel/ai) what a text means, for one purpose. Every reader in the module comes
 * through here, so the setting, the daily cap, the failure pause and the cache hold for all of them. A null answer
 * means "read it with the rules": off, over the cap, paused, or the provider failed.
 */
class ReadAiTextAction
{
    private const PAUSE_KEY = 'ai:classification:paused';

    /** @param array<string, Question> $questions */
    public function handle(string $purpose, string $text, array $questions): AiTextReading|null
    {
        $text = trim($text);
        if ($text === '' || !config('ai.classification.enabled')) {
            return null;
        }

        $hash = hash('sha256', $text);
        $cached = AiTextReading::query()->where('purpose', $purpose)->where('text_hash', $hash)->first();
        if ($cached !== null) {
            return $cached;
        }

        $distilled = $this->distilled($purpose, $text, $questions);
        if ($distilled !== null) {
            return $distilled;
        }

        if (Cache::has(self::PAUSE_KEY) || AiTextReading::query()->where('created_at', '>=', now()->startOfDay())->count() >= (int) config('ai.classification.daily_readings')) {
            return null;
        }

        try {
            $response = Classification::of($text)->questions($questions)->timeout((int) config('ai.classification.timeout_seconds'))
                ->classify(config('ai.classification.provider'), config('ai.classification.model'));
        } catch (Throwable) {
            Cache::put(self::PAUSE_KEY, true, now()->addMinutes((int) config('ai.classification.failure_pause_minutes')));

            return null;
        }

        return AiTextReading::query()->firstOrCreate(['purpose' => $purpose, 'text_hash' => $hash], [
            'text' => $text,
            'answers' => array_map(static fn (Answer $answer): array => $answer->toArray(), $response->answers),
            'provider' => $response->meta->provider ?? '',
            'model' => $response->meta->model ?? '',
        ]);
    }

    /**
     * The reading the distilled models make on their own when every question has one that is sure enough. It is not
     * stored: the readings table holds the classifier's labels the models learn from, never their own guesses.
     *
     * @param array<string, Question> $questions
     */
    private function distilled(string $purpose, string $text, array $questions): AiTextReading|null
    {
        $answers = [];
        foreach (array_keys($questions) as $question) {
            $answer = app(DistilledTextModel::class)->answer($purpose, $question, $text, (float) config('ai.classification.distilled_confidence'));
            if ($answer === null) {
                return null;
            }

            $answers[$question] = $answer;
        }

        return app()->makeWith(AiTextReading::class, ['attributes' => ['purpose' => $purpose, 'text' => $text, 'answers' => $answers, 'provider' => 'distilled', 'model' => $purpose]]);
    }
}
