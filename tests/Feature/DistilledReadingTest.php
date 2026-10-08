<?php

use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Modules\AI\Actions\ReadAiTextAction;
use Modules\AI\Domain\Language\DistilledTextModel;
use Modules\AI\Models\AiTextReading;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// The third rung of the ladder (llm-classifier-everywhere.md): a model distilled from the classifier's readings
// answers in process when it is trusted and sure, and the classifier is asked otherwise.

function distilledModel(string $directory, float $accuracy): void
{
    $buckets = 64;
    $weights = [];
    foreach (DistilledTextModel::features('hey there', $buckets) as $bucket) {
        $weights[$bucket] = [0 => 3.0];
    }
    foreach (DistilledTextModel::features('back off', $buckets) as $bucket) {
        $weights[$bucket] = [1 => 3.0];
    }

    file_put_contents("{$directory}/test.act.json", json_encode(['kind' => 'choice', 'examples' => 900, 'accuracy' => $accuracy,
        'labels' => ['greeting', 'warning'], 'buckets' => $buckets, 'bias' => [0.0, 0.0], 'weights' => $weights]));
}

test('a trusted distilled model answers a sure question without a call, and its guess is not stored', function (float $accuracy, string $text, bool $inProcess): void {
    $directory = sys_get_temp_dir() . '/distilled-' . uniqid();
    mkdir($directory);
    distilledModel($directory, $accuracy);
    config(['ai.classification.enabled' => true, 'ai.classification.distilled_path' => $directory, 'ai.classification.daily_readings' => 10]);
    Classification::fake([['act' => new ChoiceAnswer('warning', ['warning' => 0.9])]]);

    $reading = app(ReadAiTextAction::class)->handle('test', $text, ['act' => new Choice('Act?', ['greeting' => null, 'warning' => null])]);

    expect($reading?->provider === 'distilled')->toBe($inProcess)
        ->and(AiTextReading::query()->count())->toBe($inProcess ? 0 : 1);
    array_map('unlink', glob("{$directory}/*"));
    rmdir($directory);
})->with([
    'a trusted model, a text it knows' => [0.97, 'hey there', true],
    'a trusted model, a text it is unsure of' => [0.97, 'what is the weather', false],
    'a model that agreed too rarely on held-out readings' => [0.80, 'hey there', false],
]);
