<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\RecordAiMemoryFactAction;
use Modules\AI\Actions\RedactAiMemoryFactsAction;
use Modules\AI\Contracts\ContextBuilder;
use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Domain\Conversation\MemoryRecallQuery;
use Modules\AI\Domain\Conversation\NativeContextBuilder;
use Modules\AI\Domain\Conversation\NativeLongTermMemory;
use Modules\AI\Enums\AiMemoryEvidenceKind;
use Modules\AI\Enums\AiMemoryPredicate;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Models\AiObservation;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(ContextBuilder::class, NativeContextBuilder::class);
    app()->bind(LongTermMemory::class, NativeLongTermMemory::class);
});

test('native recall is owner scoped, provenance-preserving, and excludes non-current memory', function (): void {
    $subject = $this->createUser();
    $source = AiObservation::create([
        'player_id' => $this->currentUserId,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => 2001,
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'subject_player_id' => $subject->id,
        'source_time' => CarbonImmutable::parse('2026-09-11 10:00 UTC'),
        'observed_at' => CarbonImmutable::parse('2026-09-11 10:00 UTC'),
    ]);
    app(RecordAiMemoryFactAction::class)->handle($this->currentUserId, $subject->id, AiMemoryPredicate::AllianceMembership, AiMemoryEvidenceKind::Verified, ['alliance_tag' => 'RAVEN'], $source->id, CarbonImmutable::parse('2026-09-11 10:00 UTC'));
    app(RecordAiMemoryFactAction::class)->handle($this->currentUserId, $subject->id, AiMemoryPredicate::ResourceDebt, AiMemoryEvidenceKind::Claimed, ['amount' => 10], 2002, CarbonImmutable::parse('2026-09-11 10:00 UTC'), CarbonImmutable::parse('2026-09-11 11:00 UTC'));
    app(RecordAiMemoryFactAction::class)->handle($this->currentUserId, $subject->id, AiMemoryPredicate::AllianceMembership, AiMemoryEvidenceKind::Verified, ['alliance_tag' => 'FORMER'], 2003, CarbonImmutable::parse('2026-09-11 10:00 UTC'), null, null, CarbonImmutable::parse('2026-09-11 11:00 UTC'));
    app(RecordAiMemoryFactAction::class)->handle($this->currentUserId, $subject->id, AiMemoryPredicate::AllianceMembership, AiMemoryEvidenceKind::Claimed, ['alliance_tag' => 'HIDDEN'], 2004, CarbonImmutable::parse('2026-09-11 10:00 UTC'));
    app(RedactAiMemoryFactsAction::class)->handle($this->currentUserId, 2004, CarbonImmutable::parse('2026-09-11 11:00 UTC'));

    $recalled = app(LongTermMemory::class)->recallRelevantMemories(new MemoryRecallQuery($this->currentUserId, $subject->id, CarbonImmutable::parse('2026-09-11 12:00 UTC')));
    $otherOwner = app(LongTermMemory::class)->recallRelevantMemories(new MemoryRecallQuery($this->currentUserId + 1, $subject->id, CarbonImmutable::parse('2026-09-11 12:00 UTC')));

    expect($recalled)->toHaveCount(1)
        ->and($recalled[0]['value'])->toBe(['alliance_tag' => 'RAVEN'])
        ->and($recalled[0]['source_observation_id'])->toBe($source->id)
        ->and($recalled[0]['source_type'])->toBe('ChatMessage')
        ->and($recalled[0]['source_id'])->toBe(2001)
        ->and($recalled[0]['evidence_kind'])->toBe('Verified')
        ->and($otherOwner)->toBe([]);
});

test('context selection preserves earlier sections and applies a hard character budget', function (): void {
    $context = app(ContextBuilder::class)->buildConversationContext([
        'persona' => ['name' => 'Miner'],
        'terms' => ['amount' => 10],
        'older_memory' => str_repeat('x', 200),
    ], 80);

    expect($context->sections)->toHaveKeys(['persona', 'terms'])
        ->and($context->sections)->not->toHaveKey('older_memory')
        ->and(mb_strlen($context->serialized))->toBeLessThanOrEqual(80);
});
