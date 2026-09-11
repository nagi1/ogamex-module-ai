<?php

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\AI\Actions\AcceptAiCommitmentAction;
use Modules\AI\Actions\DecayAiAffectStateAction;
use Modules\AI\Actions\FindCurrentAiMemoryFactsAction;
use Modules\AI\Actions\FulfillAiCommitmentAction;
use Modules\AI\Actions\ReconcileAiChatObservationsAction;
use Modules\AI\Actions\RecordAiCommitmentAction;
use Modules\AI\Actions\RecordAiMemoryFactAction;
use Modules\AI\Actions\RecordAiRelationshipInteractionAction;
use Modules\AI\Actions\RecordObservedChatMessageAction;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCommitmentState;
use Modules\AI\Enums\AiMemoryEvidenceKind;
use Modules\AI\Enums\AiMemoryPredicate;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiAffectState;
use Modules\AI\Models\AiCommitment;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\FixtureAiClock;
use OGame\Models\Alliance;
use OGame\Models\ChatMessage;
use OGame\Models\User;
use Tests\TestCase;

require_once __DIR__ . '/../Support/FixtureAiClock.php';

class CommittedChatObservationTestCase extends TestCase
{
    /** @var list<int> */
    private array $createdAllianceIds = [];

    /** @var list<int> */
    private array $createdPlayerIds = [];

    private string $statusesFile = '';

    public function createApplication(): Application
    {
        $trackedFile = dirname(__DIR__, 4) . '/modules_statuses.json';
        $statuses = json_decode((string) file_get_contents($trackedFile), true);
        $statuses['AI'] = true;
        $this->statusesFile = sys_get_temp_dir() . '/modules_statuses_' . uniqid('', true) . '.json';
        file_put_contents($this->statusesFile, json_encode($statuses, JSON_PRETTY_PRINT));
        putenv('MODULES_STATUSES_FILE=' . $this->statusesFile);

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::hasTable('ai_affect_states')) {
            return;
        }

        Artisan::call('migrate', [
            '--path' => dirname(__DIR__, 2) . '/database/migrations',
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    protected function tearDown(): void
    {
        AiAffectState::query()->whereIn('player_id', $this->createdPlayerIds)->delete();
        AiRelationship::query()->whereIn('player_id', $this->createdPlayerIds)->delete();
        AiObservation::query()->whereIn('player_id', $this->createdPlayerIds)->delete();
        AiProfile::query()->whereIn('player_id', $this->createdPlayerIds)->delete();
        ChatMessage::withTrashed()
            ->where(function ($query): void {
                $query->whereIn('sender_id', $this->createdPlayerIds)
                    ->orWhereIn('recipient_id', $this->createdPlayerIds);
            })
            ->forceDelete();
        Alliance::query()->whereKey($this->createdAllianceIds)->delete();
        User::query()->whereKey($this->createdPlayerIds)->delete();

        if (is_file($this->statusesFile)) {
            unlink($this->statusesFile);
        }

        putenv('MODULES_STATUSES_FILE');

        parent::tearDown();
    }

    protected function createAlliance(User $founder): Alliance
    {
        $alliance = Alliance::create([
            'alliance_tag' => Str::upper(Str::random(8)),
            'alliance_name' => Str::random(20),
            'founder_user_id' => $founder->id,
        ]);
        $this->createdAllianceIds[] = $alliance->id;

        return $alliance;
    }

    protected function createChatPlayer(): User
    {
        $player = User::withoutEvents(fn (): User => User::factory()->create([
            'username' => 'ai_observation_' . Str::random(16),
        ]));
        $this->createdPlayerIds[] = $player->id;

        return $player;
    }

    protected function createProfile(User $player, bool $enabled = true): AiProfile
    {
        return AiProfile::create([
            'player_id' => $player->id,
            'archetype' => AiArchetype::Miner,
            'skill_band' => AiSkillBand::Standard,
            'random_seed' => 42,
            'enabled' => $enabled,
        ]);
    }
}

uses(CommittedChatObservationTestCase::class);

test('a committed inbound direct message becomes an observation for only its AI recipient', function (): void {
    $sender = $this->createChatPlayer();
    $recipient = $this->createChatPlayer();
    $otherAiPlayer = $this->createChatPlayer();
    $this->createProfile($recipient);
    $this->createProfile($otherAiPlayer);

    DB::transaction(function () use ($sender, $recipient): void {
        ChatMessage::create([
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'message' => 'Incoming request',
        ]);
    });

    $observation = AiObservation::query()->sole();

    expect($observation->player_id)->toBe($recipient->id)
        ->and($observation->subject_player_id)->toBe($sender->id)
        ->and($observation->source_type)->toBe(AiObservationSource::ChatMessage)
        ->and($observation->kind)->toBe(AiObservationKind::DirectChatMessageReceived)
        ->and(AiObservation::query()->where('player_id', $otherAiPlayer->id)->exists())->toBeFalse();
});

test('a rolled back chat message never becomes an observation', function (): void {
    $sender = $this->createChatPlayer();
    $recipient = $this->createChatPlayer();
    $this->createProfile($recipient);

    try {
        DB::transaction(function () use ($sender, $recipient): void {
            ChatMessage::create([
                'sender_id' => $sender->id,
                'recipient_id' => $recipient->id,
                'message' => 'Discarded request',
            ]);

            throw new RuntimeException('Force the transaction to roll back.');
        });
    } catch (RuntimeException) {
    }

    expect(AiObservation::query()->where('player_id', $recipient->id)->exists())->toBeFalse();
});

test('retrying a source does not duplicate or rewrite its observation', function (): void {
    $sender = $this->createChatPlayer();
    $recipient = $this->createChatPlayer();
    $this->createProfile($recipient);
    $chatMessage = ChatMessage::withoutEvents(fn (): ChatMessage => ChatMessage::create([
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'message' => 'Retry-safe request',
    ]));

    $firstObservation = app(RecordObservedChatMessageAction::class)->handle($chatMessage->id);
    $secondObservation = app(RecordObservedChatMessageAction::class)->handle($chatMessage->id);

    expect($firstObservation?->wasRecentlyCreated)->toBeTrue()
        ->and($secondObservation?->wasRecentlyCreated)->toBeFalse()
        ->and(AiObservation::query()->where('source_id', $chatMessage->id)->count())->toBe(1);
});

test('reconciliation retains a late source without replacing newer observed history', function (): void {
    $sender = $this->createChatPlayer();
    $recipient = $this->createChatPlayer();
    $olderMessage = ChatMessage::withoutEvents(fn (): ChatMessage => ChatMessage::create([
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'message' => 'Older request',
    ]));
    $olderSourceTime = CarbonImmutable::parse('2026-09-11 08:00:00 UTC');
    DB::table('chat_messages')->where('id', $olderMessage->id)->update([
        'created_at' => $olderSourceTime,
        'updated_at' => $olderSourceTime,
    ]);
    $this->createProfile($recipient);
    $newerMessage = ChatMessage::withoutEvents(fn (): ChatMessage => ChatMessage::create([
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'message' => 'Newer request',
    ]));
    $newerSourceTime = CarbonImmutable::parse('2026-09-11 10:00:00 UTC');
    DB::table('chat_messages')->where('id', $newerMessage->id)->update([
        'created_at' => $newerSourceTime,
        'updated_at' => $newerSourceTime,
    ]);
    app()->instance(AiClock::class, new FixtureAiClock(CarbonImmutable::parse('2026-09-11 12:00:00 UTC')));

    app(RecordObservedChatMessageAction::class)->handle($newerMessage->id);
    $createdObservations = app(ReconcileAiChatObservationsAction::class)->handle($recipient->id);
    $olderObservation = AiObservation::query()->where('source_id', $olderMessage->id)->sole();
    $newerObservation = AiObservation::query()->where('source_id', $newerMessage->id)->sole();

    expect($createdObservations)->toBe(1)
        ->and($olderObservation->source_time?->toDateTimeString())->toBe($olderSourceTime->toDateTimeString())
        ->and($olderObservation->source_time?->lessThan($newerObservation->source_time))->toBeTrue()
        ->and(AiObservation::query()->where('player_id', $recipient->id)->count())->toBe(2);
});

test('reconciliation respects its enabled recipient and explicit limit', function (): void {
    $sender = $this->createChatPlayer();
    $recipient = $this->createChatPlayer();
    $this->createProfile($recipient, false);
    $chatMessage = ChatMessage::withoutEvents(fn (): ChatMessage => ChatMessage::create([
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'message' => 'Deferred request',
    ]));

    $disabledResult = app(ReconcileAiChatObservationsAction::class)->handle($recipient->id);
    AiProfile::query()->where('player_id', $recipient->id)->update(['enabled' => true]);
    $limitedResult = app(ReconcileAiChatObservationsAction::class)->handle($recipient->id, 0);

    expect($disabledResult)->toBe(0)
        ->and($limitedResult)->toBe(0)
        ->and(AiObservation::query()->where('source_id', $chatMessage->id)->exists())->toBeFalse();
});

test('non-direct, self-sent, deleted, and disabled sources are not observed', function (): void {
    $sender = $this->createChatPlayer();
    $recipient = $this->createChatPlayer();
    $disabledRecipient = $this->createChatPlayer();
    $this->createProfile($recipient);
    $this->createProfile($disabledRecipient, false);
    $alliance = $this->createAlliance($sender);
    $nonDirect = ChatMessage::withoutEvents(fn (): ChatMessage => ChatMessage::create([
        'sender_id' => $sender->id,
        'alliance_id' => $alliance->id,
        'message' => 'Alliance request',
    ]));
    $mixedScope = ChatMessage::withoutEvents(fn (): ChatMessage => ChatMessage::create([
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'alliance_id' => $alliance->id,
        'message' => 'Ambiguous request',
    ]));
    $selfSent = ChatMessage::withoutEvents(fn (): ChatMessage => ChatMessage::create([
        'sender_id' => $recipient->id,
        'recipient_id' => $recipient->id,
        'message' => 'Self request',
    ]));
    $deleted = ChatMessage::withoutEvents(fn (): ChatMessage => ChatMessage::create([
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'message' => 'Deleted request',
    ]));
    $deleted->delete();
    $disabled = ChatMessage::withoutEvents(fn (): ChatMessage => ChatMessage::create([
        'sender_id' => $sender->id,
        'recipient_id' => $disabledRecipient->id,
        'message' => 'Disabled request',
    ]));

    $results = [
        app(RecordObservedChatMessageAction::class)->handle($nonDirect->id),
        app(RecordObservedChatMessageAction::class)->handle($mixedScope->id),
        app(RecordObservedChatMessageAction::class)->handle($selfSent->id),
        app(RecordObservedChatMessageAction::class)->handle($deleted->id),
        app(RecordObservedChatMessageAction::class)->handle($disabled->id),
        app(RecordObservedChatMessageAction::class)->handle(PHP_INT_MAX),
    ];

    expect($results)->each->toBeNull()
        ->and(AiObservation::query()->count())->toBe(0);
});

test('an attributed claim remains distinct from verified current knowledge and expires independently', function (): void {
    $owner = $this->createChatPlayer();
    $subject = $this->createChatPlayer();
    $speaker = $this->createChatPlayer();
    $claimSource = AiObservation::create([
        'player_id' => $owner->id,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => 101,
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'subject_player_id' => $speaker->id,
        'source_time' => CarbonImmutable::parse('2026-09-11 08:00:00 UTC'),
        'observed_at' => CarbonImmutable::parse('2026-09-11 08:00:00 UTC'),
    ]);
    $verifiedSource = AiObservation::create([
        'player_id' => $owner->id,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => 102,
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'subject_player_id' => $subject->id,
        'source_time' => CarbonImmutable::parse('2026-09-11 10:00:00 UTC'),
        'observed_at' => CarbonImmutable::parse('2026-09-11 10:00:00 UTC'),
    ]);
    $recordFact = app(RecordAiMemoryFactAction::class);
    $recordFact->handle(
        $owner->id,
        $subject->id,
        AiMemoryPredicate::AllianceMembership,
        AiMemoryEvidenceKind::Claimed,
        ['alliance_tag' => 'RAVEN'],
        $claimSource->id,
        CarbonImmutable::parse('2026-09-11 08:00:00 UTC'),
        CarbonImmutable::parse('2026-09-11 09:00:00 UTC'),
        $speaker->id,
    );
    $verified = $recordFact->handle(
        $owner->id,
        $subject->id,
        AiMemoryPredicate::AllianceMembership,
        AiMemoryEvidenceKind::Verified,
        ['alliance_tag' => 'DRACO'],
        $verifiedSource->id,
        CarbonImmutable::parse('2026-09-11 10:00:00 UTC'),
    );

    $currentFacts = app(FindCurrentAiMemoryFactsAction::class)->handle(
        $owner->id,
        $subject->id,
        AiMemoryPredicate::AllianceMembership,
        CarbonImmutable::parse('2026-09-11 11:00:00 UTC'),
    );

    expect($verified->evidence_kind)->toBe(AiMemoryEvidenceKind::Verified)
        ->and($verified->speaker_player_id)->toBeNull()
        ->and($currentFacts)->toHaveCount(1)
        ->and($currentFacts->sole()->value)->toBe(['alliance_tag' => 'DRACO'])
        ->and($currentFacts->sole()->source_observation_id)->toBe($verifiedSource->id);
});

test('a commitment retains exact proposed terms and cannot duplicate its source', function (): void {
    $owner = $this->createChatPlayer();
    $counterparty = $this->createChatPlayer();
    $source = AiObservation::create([
        'player_id' => $owner->id,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => 201,
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'subject_player_id' => $counterparty->id,
        'source_time' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
        'observed_at' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
    ]);
    $terms = ['resource' => 'crystal', 'amount' => 2_000_000];

    $first = app(RecordAiCommitmentAction::class)->handle(
        $owner->id,
        $counterparty->id,
        $terms,
        $source->id,
        CarbonImmutable::parse('2026-09-12 12:00:00 UTC'),
    );
    $second = app(RecordAiCommitmentAction::class)->handle(
        $owner->id,
        $counterparty->id,
        ['resource' => 'crystal', 'amount' => 1],
        $source->id,
    );

    expect($first->state)->toBe(AiCommitmentState::Proposed)
        ->and($second->terms)->toEqual($terms)
        ->and(AiCommitment::query()->where('source_observation_id', $source->id)->count())->toBe(1);
});

test('an accepted commitment is fulfilled once with evidence or expires after its due time', function (): void {
    $owner = $this->createChatPlayer();
    $counterparty = $this->createChatPlayer();
    $source = AiObservation::create([
        'player_id' => $owner->id,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => 301,
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'subject_player_id' => $counterparty->id,
        'source_time' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
        'observed_at' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
    ]);
    $fulfillmentSource = AiObservation::create([
        'player_id' => $owner->id,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => 302,
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'subject_player_id' => $counterparty->id,
        'source_time' => CarbonImmutable::parse('2026-09-11 13:00:00 UTC'),
        'observed_at' => CarbonImmutable::parse('2026-09-11 13:00:00 UTC'),
    ]);
    $record = app(RecordAiCommitmentAction::class);
    $fulfilled = $record->handle($owner->id, $counterparty->id, ['amount' => 10], $source->id, CarbonImmutable::parse('2026-09-12 12:00:00 UTC'));
    $expired = $record->handle($owner->id, $counterparty->id, ['amount' => 20], $fulfillmentSource->id, CarbonImmutable::parse('2026-09-11 12:30:00 UTC'));

    app(AcceptAiCommitmentAction::class)->handle($fulfilled->id);
    app(AcceptAiCommitmentAction::class)->handle($expired->id);
    $fulfilled = app(FulfillAiCommitmentAction::class)->handle($fulfilled->id, $fulfillmentSource->id, CarbonImmutable::parse('2026-09-11 14:00:00 UTC'));
    $expired = app(FulfillAiCommitmentAction::class)->handle($expired->id, $fulfillmentSource->id, CarbonImmutable::parse('2026-09-11 14:00:00 UTC'));

    expect($fulfilled?->state)->toBe(AiCommitmentState::Fulfilled)
        ->and($fulfilled?->fulfillment_observation_id)->toBe($fulfillmentSource->id)
        ->and($expired?->state)->toBe(AiCommitmentState::Expired)
        ->and($expired?->fulfillment_observation_id)->toBeNull();
});

test('a relationship is sourced, bounded, and cannot be revised by a late interaction', function (): void {
    $owner = $this->createChatPlayer();
    $otherPlayer = $this->createChatPlayer();
    $source = AiObservation::create([
        'player_id' => $owner->id,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => 401,
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'subject_player_id' => $otherPlayer->id,
        'source_time' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
        'observed_at' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
    ]);
    $record = app(RecordAiRelationshipInteractionAction::class);
    $first = $record->handle($owner->id, $otherPlayer->id, $source->id, CarbonImmutable::parse('2026-09-11 12:00:00 UTC'), 2, -1, 2);
    $late = $record->handle($owner->id, $otherPlayer->id, $source->id, CarbonImmutable::parse('2026-09-11 11:00:00 UTC'), -1, 1);

    expect($first?->trust)->toBe('1.0000')
        ->and($first?->threat)->toBe('0.0000')
        ->and($first?->last_observation_id)->toBe($source->id)
        ->and($late?->trust)->toBe('1.0000')
        ->and(AiRelationship::query()->where('player_id', $owner->id)->where('other_player_id', $otherPlayer->id)->count())->toBe(1)
        ->and($record->handle($owner->id, $owner->id, $source->id, CarbonImmutable::now()))->toBeNull();
});

test('affect decay is deterministic, bounded, and does not alter commitments', function (): void {
    $owner = $this->createChatPlayer();
    $state = AiAffectState::create([
        'player_id' => $owner->id,
        'emotion' => AiAffectEmotion::Anger,
        'intensity' => 0.5,
        'updated_for' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
        'revision' => 1,
    ]);

    $decayed = app(DecayAiAffectStateAction::class)->handle($state, CarbonImmutable::parse('2026-09-12 12:00:00 UTC'));
    $unchanged = app(DecayAiAffectStateAction::class)->handle($decayed, CarbonImmutable::parse('2026-09-12 11:00:00 UTC'));

    expect($decayed->intensity)->toBe('0.2500')
        ->and($decayed->revision)->toBe(2)
        ->and($unchanged->revision)->toBe(2);
});
