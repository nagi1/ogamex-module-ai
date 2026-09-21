<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\EvaluateAiSocialExchangeAction;
use Modules\AI\Actions\InitiateAiSocialContactAction;
use Modules\AI\Actions\RecordAiRelationshipInteractionAction;
use Modules\AI\Actions\RecordAiSocialExchangeAction;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\NativeSocialCognition;
use Modules\AI\Domain\Conversation\SocialExchangeContext;
use Modules\AI\Domain\Decision\SaveFailurePolicy;
use Modules\AI\Domain\Perception\PlayerObservationService;
use Modules\AI\Domain\Routine\SessionPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCommitmentDirection;
use Modules\AI\Enums\AiCommitmentState;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialResource;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Enums\AiSocialTerm;
use Modules\AI\Models\AiCommitment;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SeededRandomSource;
use Modules\AI\Tests\Support\FixtureAiClock;
use OGame\Models\ChatMessage;
use OGame\Models\FleetMission;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/FixtureAiClock.php';

uses(IsolatedAccountTestCase::class);

/**
 * The adversarial neighbour (verification-and-monitoring.md §2): one scripted neighbour probes,
 * attacks, apologises and compensates, and the harness records the account's answer. Each figure
 * below is produced by the same real actions a live cohort runs, under a frozen clock and a seeded
 * random source, so the report is reproducible rather than anecdotal.
 */
beforeEach(function (): void {
    app()->bind(SocialCognition::class, NativeSocialCognition::class);
    app()->bind(RandomSource::class, SeededRandomSource::class);
    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, [
        'now' => CarbonImmutable::parse('2026-09-11 08:00:00 UTC'),
    ]));
});

test('a probe wakes a reaction inside the 120–180 second window, never instantly', function (): void {
    $profile = provokeProfile($this->currentUserId);
    $this->planetAddUnit('large_cargo', 5);
    $mission = provokeInboundHostileFleet($this->currentPlanetId, 600);

    $state = app(PlayerObservationService::class)->ownedState($this->currentUserId);

    expect($state['fleetsave_eligible'])->toBeFalse()
        ->and($state['reaction_wake_at'])->not->toBeNull()
        ->and($state['reaction_wake_at'])->toBeBetween($mission->time_arrival - 180, $mission->time_arrival - 120)
        // The account owns a fleet and the save would fly; the wake is the reaction, not a refusal.
        ->and($profile->random_seed)->toBeInt();
});

test('an attack is saved from, and a save can also fail', function (): void {
    $profile = provokeProfile($this->currentUserId);
    $this->planetAddUnit('large_cargo', 5);
    $mission = provokeInboundHostileFleet($this->currentPlanetId, 150);

    $profile->update(['random_seed' => provokeSkipSeed($mission->id)]);
    $skipped = app(PlayerObservationService::class)->ownedState($this->currentUserId);

    $profile->update(['random_seed' => provokeNoSkipSeed($mission->id)]);
    $saved = app(PlayerObservationService::class)->ownedState($this->currentUserId);

    expect($skipped['fleetsave_eligible'])->toBeFalse()
        ->and($skipped['fleetsave_skip_reason'])->toBe('overnight_gamble')
        ->and($saved['fleetsave_eligible'])->toBeTrue()
        ->and($saved['fleetsave_skip_reason'])->toBeNull();
});

test('a cheap apology is refused: no forgiveness without earned trust or compensation', function (): void {
    $evaluation = app(SocialCognition::class)->evaluateSocialExchange(app()->makeWith(SocialExchangeContext::class, [
        'exchangeId' => 1,
        'type' => AiSocialExchangeType::Apology,
        'terms' => [AiSocialTerm::AcknowledgesHarm->value => true],
        'trust' => 0.0,
        'affinity' => 0.9,
        'threat' => 0.1,
        'outstandingCommitments' => 0,
        'availableAmount' => 0,
        'evaluatedAt' => CarbonImmutable::parse('2026-09-11 08:00:00 UTC'),
    ]));

    expect($evaluation->response)->toBe(AiSocialResponse::Counter)
        ->and($evaluation->counterTerms)->toBe([AiSocialTerm::Repair->value => 'compensation']);
});

test('a delivered compensation offer is accepted as an outstanding commitment, then thanked', function (): void {
    $counterparty = $this->createUser();
    provokeProfile($this->currentUserId);
    $dueAt = CarbonImmutable::parse('2026-09-12 12:00:00 UTC');

    $exchange = app(RecordAiSocialExchangeAction::class)->handle(
        $this->currentUserId,
        $counterparty->id,
        9001,
        AiSocialExchangeType::CompensationOffer,
        [AiSocialTerm::AcknowledgesHarm->value => true, AiSocialTerm::Resource->value => AiSocialResource::Crystal->value, AiSocialTerm::Amount->value => 200],
        $dueAt,
    );
    $evaluated = app(EvaluateAiSocialExchangeAction::class)->handle($exchange?->id ?? 0, 0, CarbonImmutable::parse('2026-09-11 08:30:00 UTC'));
    $commitment = AiCommitment::query()->findOrFail($evaluated?->commitment_id);

    expect($evaluated?->response)->toBe(AiSocialResponse::Accept)
        ->and($commitment->state)->toBe(AiCommitmentState::Accepted)
        ->and($commitment->direction)->toBe(AiCommitmentDirection::ExpectedFromCounterparty)
        ->and($commitment->fulfilled_at)->toBeNull();

    // The neighbour then ships: the received transport earns exactly one thank-you.
    app(RecordAiRelationshipInteractionAction::class)->handle(
        $this->currentUserId,
        $counterparty->id,
        $exchange?->source_observation_id ?? 9001,
        CarbonImmutable::parse('2026-09-11 09:00:00 UTC'),
        0.4,
        0,
        0.0,
    );
    AiObservation::query()->firstOrCreate([
        'player_id' => $this->currentUserId,
        'source_type' => AiObservationSource::FleetMessage,
        'source_id' => 9101,
    ], [
        'kind' => AiObservationKind::TransferReceived,
        'subject_player_id' => $counterparty->id,
        'source_time' => CarbonImmutable::parse('2026-09-11 09:00:00 UTC'),
        'observed_at' => CarbonImmutable::parse('2026-09-11 09:00:00 UTC'),
    ]);

    expect(app(InitiateAiSocialContactAction::class)->handle($this->currentUserId, CarbonImmutable::parse('2026-09-11 09:30:00 UTC')))->toBe(1)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->where('recipient_id', $counterparty->id)->exists())->toBeTrue();
});

test('the account keeps a nine-hour dark period whatever its wake time', function (): void {
    $profile = provokeProfile($this->currentUserId);
    $planner = app(SessionPlanner::class);
    $day = CarbonImmutable::parse('2026-09-11 00:00:00 UTC');

    $darkHours = 0;
    for ($hour = 0; $hour < 24; $hour++) {
        if (!$planner->isAwake($profile, $day->addHours($hour))) {
            $darkHours++;
        }
    }

    expect($darkHours)->toBeGreaterThanOrEqual(9);
});

function provokeProfile(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 42,
        'enabled' => true,
    ]);
}

function provokeInboundHostileFleet(int $targetPlanetId, int $leadSeconds): FleetMission
{
    $foreign = test()->createForeignPlanet();
    $foreignPlayer = $foreign->getPlayer();

    $mission = new FleetMission();
    $mission->user_id = $foreignPlayer->getId();
    $mission->planet_id_from = $foreign->getPlanetId();
    $mission->planet_id_to = $targetPlanetId;
    $mission->mission_type = 1;
    $mission->time_departure = now()->subMinute()->timestamp;
    $mission->time_arrival = now()->addSeconds($leadSeconds)->timestamp;
    $mission->time_arrival_ms = 0;
    $mission->processed = 0;
    $mission->canceled = 0;
    $mission->save();

    return $mission;
}

function provokeSkipSeed(int $missionId): int
{
    $policy = app(SaveFailurePolicy::class);
    for ($seed = 0; $seed < 1000; $seed++) {
        if ($policy->shouldSkip($seed, $missionId) !== null) {
            return $seed;
        }
    }

    throw new RuntimeException('No seed found that skips mission ' . $missionId);
}

function provokeNoSkipSeed(int $missionId): int
{
    $policy = app(SaveFailurePolicy::class);
    for ($seed = 0; $seed < 1000; $seed++) {
        if ($policy->shouldSkip($seed, $missionId) === null) {
            return $seed;
        }
    }

    throw new RuntimeException('No seed found that does not skip mission ' . $missionId);
}
