<?php

use Modules\AI\Actions\RecordAiExperienceOutcomeAction;
use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Domain\Experience\ExperienceQuery;
use Modules\AI\Domain\Experience\NativeExperienceEngine;
use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiExperienceOutcome;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(ExperienceEngine::class, NativeExperienceEngine::class);
});

test('finalized personal outcomes are source-deduplicated and bounded', function (): void {
    $record = app(RecordAiExperienceOutcomeAction::class);
    $stored = $record->handle($this->currentUserId, 1001, AiExperienceCaseFamily::SocialAssistance, AiExperienceOutcome::Succeeded, 'v1', 'v1', ['amount' => 100, 'resource' => 'crystal'], 2, -1);
    $duplicate = $record->handle($this->currentUserId, 1001, AiExperienceCaseFamily::SocialAssistance, AiExperienceOutcome::Failed, 'v2', 'v2', [], 0, 0);

    expect($stored?->utility)->toBe('1.0000')
        ->and($stored?->uncertainty)->toBe('0.0000')
        ->and($duplicate?->outcome)->toBe(AiExperienceOutcome::Succeeded)
        ->and($record->handle($this->currentUserId, 1002, AiExperienceCaseFamily::SocialAssistance, AiExperienceOutcome::Pending, 'v1', 'v1', [], 0, 0))->toBeNull();
});

test('experience recall is owner and version scoped with deterministic similarity', function (): void {
    $record = app(RecordAiExperienceOutcomeAction::class);
    $record->handle($this->currentUserId, 1003, AiExperienceCaseFamily::SocialAssistance, AiExperienceOutcome::Succeeded, 'v1', 'v1', ['amount' => 100, 'resource' => 'crystal'], 0.8, 0.1);
    $record->handle($this->currentUserId, 1004, AiExperienceCaseFamily::SocialAssistance, AiExperienceOutcome::Failed, 'v1', 'v1', ['amount' => 50, 'resource' => 'metal'], -0.5, 0.2);
    $record->handle($this->currentUserId, 1005, AiExperienceCaseFamily::SocialAssistance, AiExperienceOutcome::Succeeded, 'v2', 'v1', ['amount' => 100], 1, 0);

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(app()->makeWith(ExperienceQuery::class, [
        'playerId' => $this->currentUserId,
        'family' => AiExperienceCaseFamily::SocialAssistance,
        'featureVersion' => 'v1',
        'rulesetVersion' => 'v1',
        'features' => ['amount' => 100, 'resource' => 'crystal'],
    ]));
    $coldStart = app(ExperienceEngine::class)->rankSimilarExperiences(app()->makeWith(ExperienceQuery::class, [
        'playerId' => $this->currentUserId + 1,
        'family' => AiExperienceCaseFamily::SocialAssistance,
        'featureVersion' => 'v1',
        'rulesetVersion' => 'v1',
        'features' => ['amount' => 100],
    ]));

    expect($ranked)->toHaveCount(2)
        ->and($ranked[0]->outcome)->toBe(AiExperienceOutcome::Succeeded)
        ->and($ranked[0]->similarity)->toBe(1.0)
        ->and($ranked[1]->similarity)->toBeLessThan(1.0)
        ->and($coldStart)->toBe([]);
});

test('experience similarity remains bounded when a case has unknown features', function (): void {
    $record = app(RecordAiExperienceOutcomeAction::class);
    $record->handle($this->currentUserId, 1006, AiExperienceCaseFamily::SocialAssistance, AiExperienceOutcome::Succeeded, 'v1', 'v1', ['amount' => null], 0, 0);
    $record->handle($this->currentUserId, 1007, AiExperienceCaseFamily::SocialAssistance, AiExperienceOutcome::Succeeded, 'v1', 'v1', ['unknown' => null], 0, 0);
    $record->handle($this->currentUserId, 1008, AiExperienceCaseFamily::SocialAssistance, AiExperienceOutcome::Succeeded, 'v1', 'v1', ['empty' => 100], 0, 0);

    $ranked = app(ExperienceEngine::class)->rankSimilarExperiences(app()->makeWith(ExperienceQuery::class, [
        'playerId' => $this->currentUserId,
        'family' => AiExperienceCaseFamily::SocialAssistance,
        'featureVersion' => 'v1',
        'rulesetVersion' => 'v1',
        'features' => ['amount' => 100, 'unknown' => 100],
    ]));

    expect($ranked)->toHaveCount(3)
        ->and($ranked[0]->similarity)->toBe(0.0)
        ->and($ranked[1]->similarity)->toBe(0.0);

    $unknown = app(ExperienceEngine::class)->rankSimilarExperiences(app()->makeWith(ExperienceQuery::class, [
        'playerId' => $this->currentUserId,
        'family' => AiExperienceCaseFamily::SocialAssistance,
        'featureVersion' => 'v1',
        'rulesetVersion' => 'v1',
        'features' => ['empty' => null],
    ]));

    expect($unknown)->toHaveCount(3)
        ->and($unknown[0]->similarity)->toBe(0.0);
});
