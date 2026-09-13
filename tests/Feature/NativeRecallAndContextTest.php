<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\RecordAiMemoryFactAction;
use Modules\AI\Actions\RedactAiMemoryFactsAction;
use Modules\AI\Contracts\ContextBuilder;
use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Domain\Conversation\ConversationContextSection;
use Modules\AI\Domain\Conversation\MemoryRecallQuery;
use Modules\AI\Domain\Conversation\NativeContextBuilder;
use Modules\AI\Enums\AiMemoryEvidenceKind;
use Modules\AI\Enums\AiMemoryPredicate;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Models\AiObservation;
use Modules\AI\Support\LongTermMemorySelector;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(ContextBuilder::class, NativeContextBuilder::class);
    // Routed through the selector so the module's real recall wiring stays under test.
    app()->bind(LongTermMemory::class, fn (): LongTermMemory => app(LongTermMemorySelector::class)->resolve());
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

    $recalled = app(LongTermMemory::class)->recallRelevantMemories(app()->makeWith(MemoryRecallQuery::class, [
        'playerId' => $this->currentUserId,
        'subjectPlayerId' => $subject->id,
        'now' => CarbonImmutable::parse('2026-09-11 12:00 UTC'),
    ]));
    $otherOwner = app(LongTermMemory::class)->recallRelevantMemories(app()->makeWith(MemoryRecallQuery::class, [
        'playerId' => $this->currentUserId + 1,
        'subjectPlayerId' => $subject->id,
        'now' => CarbonImmutable::parse('2026-09-11 12:00 UTC'),
    ]));

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
        ->and($context->protectedContentFits)->toBeTrue()
        ->and(mb_strlen($context->serialized))->toBeLessThanOrEqual(80);
});

test('protected context is prioritized and reports when it cannot fit intact', function (): void {
    $prioritized = app(ContextBuilder::class)->buildConversationContext([
        'older_memory' => str_repeat('x', 200),
        app()->makeWith(ConversationContextSection::class, [
            'name' => 'exact_terms',
            'value' => ['amount' => 10],
            'isProtected' => true,
        ]),
    ], 80);
    $overflowed = app(ContextBuilder::class)->buildConversationContext([
        app()->makeWith(ConversationContextSection::class, [
            'name' => 'current_message',
            'value' => str_repeat('x', 200),
            'isProtected' => true,
        ]),
    ], 80);

    expect($prioritized->sections)->toHaveKey('exact_terms')
        ->and($prioritized->sections)->not->toHaveKey('older_memory')
        ->and($prioritized->protectedContentFits)->toBeTrue()
        ->and($overflowed->sections)->toBe([])
        ->and($overflowed->protectedContentFits)->toBeFalse();
});
