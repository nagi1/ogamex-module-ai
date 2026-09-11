<?php

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AI\Actions\AcceptAiCommitmentAction;
use Modules\AI\Actions\DecayAiAffectStateAction;
use Modules\AI\Actions\FindCurrentAiMemoryFactsAction;
use Modules\AI\Actions\FulfillAiCommitmentAction;
use Modules\AI\Actions\ReconcileAiChatObservationsAction;
use Modules\AI\Actions\RecordAiCommitmentAction;
use Modules\AI\Actions\RecordAiEmotionalEpisodeAction;
use Modules\AI\Actions\RecordAiMemoryFactAction;
use Modules\AI\Actions\RecordAiRelationshipInteractionAction;
use Modules\AI\Actions\RecordObservedAllianceMembershipEndAction;
use Modules\AI\Actions\RecordObservedAllianceMembershipStartAction;
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
use Modules\AI\Models\AiEmotionalEpisode;
use Modules\AI\Models\AiMemoryFact;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Support\AiClock;
use Modules\AI\Tests\Support\FixtureAiClock;
use OGame\Models\Alliance;
use OGame\Models\AllianceMember;
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
    }

    protected function tearDown(): void
    {
        AiMemoryFact::query()->whereIn('player_id', $this->createdPlayerIds)->delete();
        AiAffectState::query()->whereIn('player_id', $this->createdPlayerIds)->delete();
        AiEmotionalEpisode::query()->whereIn('player_id', $this->createdPlayerIds)->delete();
        AiCommitment::query()->whereIn('player_id', $this->createdPlayerIds)->delete();
        AiRelationship::query()->whereIn('player_id', $this->createdPlayerIds)->delete();
        AiObservation::query()->whereIn('player_id', $this->createdPlayerIds)->delete();
        AiProfile::query()->whereIn('player_id', $this->createdPlayerIds)->delete();
        ChatMessage::withTrashed()
            ->where(function ($query): void {
                $query->whereIn('sender_id', $this->createdPlayerIds)
                    ->orWhereIn('recipient_id', $this->createdPlayerIds);
            })
            ->forceDelete();
        AllianceMember::query()->whereIn('user_id', $this->createdPlayerIds)->delete();
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

test('committed alliance membership changes retain accepted facts only for legal AI observers', function (): void {
    $joiningPlayer = $this->createChatPlayer();
    $allianceAiPlayer = $this->createChatPlayer();
    $outsideAiPlayer = $this->createChatPlayer();
    $alliance = $this->createAlliance($joiningPlayer);
    $this->createProfile($joiningPlayer);
    $this->createProfile($allianceAiPlayer);
    $this->createProfile($outsideAiPlayer);

    DB::transaction(function () use ($alliance, $joiningPlayer, $allianceAiPlayer): void {
        AllianceMember::create([
            'alliance_id' => $alliance->id,
            'user_id' => $joiningPlayer->id,
            'joined_at' => now(),
        ]);
        AllianceMember::create([
            'alliance_id' => $alliance->id,
            'user_id' => $allianceAiPlayer->id,
            'joined_at' => now(),
        ]);
        User::query()->whereKey($joiningPlayer->id)->update(['alliance_id' => $alliance->id]);
        User::query()->whereKey($allianceAiPlayer->id)->update(['alliance_id' => $alliance->id]);
    });

    $joiningFact = AiMemoryFact::query()
        ->where('player_id', $allianceAiPlayer->id)
        ->where('subject_player_id', $joiningPlayer->id)
        ->where('predicate', AiMemoryPredicate::AllianceMembership)
        ->sole();

    expect($joiningFact->value)->toBe(['alliance_id' => $alliance->id])
        ->and($joiningFact->evidence_kind)->toBe(AiMemoryEvidenceKind::Verified)
        ->and(AiObservation::query()->where('player_id', $outsideAiPlayer->id)->exists())->toBeFalse();

    $joiningMembership = AllianceMember::query()->where('user_id', $joiningPlayer->id)->sole();

    expect(app(RecordObservedAllianceMembershipStartAction::class)->handle($joiningMembership->id))->toBe(0);

    DB::transaction(function () use ($joiningPlayer): void {
        AllianceMember::query()->where('user_id', $joiningPlayer->id)->sole()->delete();
        User::query()->whereKey($joiningPlayer->id)->update(['alliance_id' => null]);
    });

    $currentFact = AiMemoryFact::query()
        ->where('player_id', $allianceAiPlayer->id)
        ->where('subject_player_id', $joiningPlayer->id)
        ->where('predicate', AiMemoryPredicate::AllianceMembership)
        ->orderByDesc('id')
        ->firstOrFail();

    expect($joiningFact->fresh()?->valid_to)->not->toBeNull()
        ->and($currentFact->value)->toBe(['alliance_id' => null])
        ->and($currentFact->sourceObservation?->source_type)->toBe(AiObservationSource::AllianceMembershipLeft)
        ->and(AiObservation::query()->where('player_id', $outsideAiPlayer->id)->exists())->toBeFalse()
        ->and(app(RecordObservedAllianceMembershipEndAction::class)->handle(
            $currentFact->sourceObservation?->source_id ?? 0,
            $joiningPlayer->id,
            $alliance->id,
            CarbonImmutable::instance($currentFact->sourceObservation?->source_time ?? now()),
        ))->toBe(0);
});

test('a rolled back alliance membership transition is not observed', function (): void {
    $joiningPlayer = $this->createChatPlayer();
    $alliance = $this->createAlliance($joiningPlayer);
    $this->createProfile($joiningPlayer);

    try {
        DB::transaction(function () use ($alliance, $joiningPlayer): void {
            AllianceMember::create([
                'alliance_id' => $alliance->id,
                'user_id' => $joiningPlayer->id,
                'joined_at' => now(),
            ]);
            User::query()->whereKey($joiningPlayer->id)->update(['alliance_id' => $alliance->id]);

            throw new RuntimeException('Force the transition to roll back.');
        });
    } catch (RuntimeException) {
    }

    expect(AiObservation::query()->where('source_type', AiObservationSource::AllianceMembershipJoined)->exists())->toBeFalse();
});

test('stale alliance membership sources do not create membership facts', function (): void {
    $player = $this->createChatPlayer();
    $alliance = $this->createAlliance($player);
    $this->createProfile($player);
    $membership = AllianceMember::withoutEvents(fn (): AllianceMember => AllianceMember::create([
        'alliance_id' => $alliance->id,
        'user_id' => $player->id,
        'joined_at' => now(),
    ]));

    expect(app(RecordObservedAllianceMembershipStartAction::class)->handle($membership->id))->toBe(0)
        ->and(app(RecordObservedAllianceMembershipStartAction::class)->handle(PHP_INT_MAX))->toBe(0)
        ->and(app(RecordObservedAllianceMembershipEndAction::class)->handle($membership->id, $player->id, $alliance->id, CarbonImmutable::now()))->toBe(0)
        ->and(AiMemoryFact::query()->where('player_id', $player->id)->exists())->toBeFalse();
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

test('deleting an observed chat source redacts its dependent current memory', function (): void {
    $sender = $this->createChatPlayer();
    $recipient = $this->createChatPlayer();
    $this->createProfile($recipient);
    $chatMessage = ChatMessage::create([
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'message' => 'This claim can be removed.',
    ]);
    $observation = AiObservation::query()->where('source_id', $chatMessage->id)->sole();

    app(RecordAiMemoryFactAction::class)->handle(
        $recipient->id,
        $sender->id,
        AiMemoryPredicate::AllianceMembership,
        AiMemoryEvidenceKind::Claimed,
        ['alliance_tag' => 'RAVEN'],
        $observation->id,
        CarbonImmutable::parse('2026-09-11 10:00 UTC'),
        null,
        $sender->id,
    );
    $chatMessage->delete();

    $fact = AiMemoryFact::query()->where('source_observation_id', $observation->id)->sole();

    expect($fact->redacted_at)->not->toBeNull()
        ->and(app(FindCurrentAiMemoryFactsAction::class)->handle($recipient->id, $sender->id, AiMemoryPredicate::AllianceMembership, CarbonImmutable::parse('2026-09-11 12:00 UTC')))->toBeEmpty();
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

test('terminal commitments and unknown identifiers cannot be transitioned again', function (): void {
    $owner = $this->createChatPlayer();
    $counterparty = $this->createChatPlayer();
    $source = AiObservation::create([
        'player_id' => $owner->id,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => 303,
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'subject_player_id' => $counterparty->id,
        'source_time' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
        'observed_at' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
    ]);
    $commitment = app(RecordAiCommitmentAction::class)->handle($owner->id, $counterparty->id, ['amount' => 10], $source->id);
    $accepted = app(AcceptAiCommitmentAction::class)->handle($commitment->id);
    $acceptedAgain = app(AcceptAiCommitmentAction::class)->handle($commitment->id);
    $fulfilled = app(FulfillAiCommitmentAction::class)->handle($commitment->id, $source->id, CarbonImmutable::parse('2026-09-11 13:00:00 UTC'));
    $fulfilledAgain = app(FulfillAiCommitmentAction::class)->handle($commitment->id, $source->id, CarbonImmutable::parse('2026-09-11 14:00:00 UTC'));

    expect(app(AcceptAiCommitmentAction::class)->handle(PHP_INT_MAX))->toBeNull()
        ->and(app(FulfillAiCommitmentAction::class)->handle(PHP_INT_MAX, $source->id, CarbonImmutable::parse('2026-09-11 13:00:00 UTC')))->toBeNull()
        ->and($accepted?->state)->toBe(AiCommitmentState::Accepted)
        ->and($acceptedAgain?->state)->toBe(AiCommitmentState::Accepted)
        ->and($fulfilled?->state)->toBe(AiCommitmentState::Fulfilled)
        ->and($fulfilledAgain?->state)->toBe(AiCommitmentState::Fulfilled);
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

test('a significant emotional episode is source-deduplicated and keeps its original intensity', function (): void {
    $owner = $this->createChatPlayer();
    $source = AiObservation::create([
        'player_id' => $owner->id,
        'source_type' => AiObservationSource::ChatMessage,
        'source_id' => 501,
        'kind' => AiObservationKind::DirectChatMessageReceived,
        'subject_player_id' => $this->createChatPlayer()->id,
        'source_time' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
        'observed_at' => CarbonImmutable::parse('2026-09-11 12:00:00 UTC'),
    ]);

    $record = app(RecordAiEmotionalEpisodeAction::class);
    $first = $record->handle($owner->id, $source->id, AiAffectEmotion::Anger, 4, CarbonImmutable::parse('2026-09-11 12:00:00 UTC'));
    $duplicate = $record->handle($owner->id, $source->id, AiAffectEmotion::Anger, 0, CarbonImmutable::parse('2026-09-12 12:00:00 UTC'));
    $fear = $record->handle($owner->id, $source->id, AiAffectEmotion::Fear, -1, CarbonImmutable::parse('2026-09-11 12:00:00 UTC'));

    expect($first->wasRecentlyCreated)->toBeTrue()
        ->and($first->intensity)->toBe('1.0000')
        ->and($duplicate->wasRecentlyCreated)->toBeFalse()
        ->and($duplicate->intensity)->toBe('1.0000')
        ->and($fear->intensity)->toBe('0.0000')
        ->and(AiEmotionalEpisode::query()->where('player_id', $owner->id)->count())->toBe(2);
});
