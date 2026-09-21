<?php

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Models\AiObservation;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

test('the atomic observation helper is idempotent and never duplicates on a repeated write', function (): void {
    $attributes = [
        'player_id' => $this->currentUserId,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => 9001,
    ];
    $values = [
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'subject_player_id' => null,
        'source_time' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
        'observed_at' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
    ];

    $first = AiObservation::firstOrCreateAtomically($attributes, $values);
    $second = AiObservation::firstOrCreateAtomically($attributes, $values);

    expect($second->id)->toBe($first->id)
        ->and($first->wasRecentlyCreated)->toBeTrue()
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and(AiObservation::query()->where($attributes)->count())->toBe(1);
});
