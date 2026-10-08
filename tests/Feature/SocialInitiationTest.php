<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\InitiateAiSocialContactAction;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\NativeSocialCognition;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiSocialExchange;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SeededRandomSource;
use Modules\AI\Tests\Support\FixtureAiClock;
use OGame\Models\ChatMessage;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/FixtureAiClock.php';

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    app()->bind(SocialCognition::class, NativeSocialCognition::class);
    app()->bind(RandomSource::class, SeededRandomSource::class);
    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, ['now' => CarbonImmutable::parse('2024-01-01 00:00:00 UTC')]));
});

function initiationProfile(int $playerId, int $randomSeed = 42): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Miner,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => $randomSeed,
        'enabled' => true,
    ]);
}

function receivedTransportObservation(int $playerId, int $senderId, int $sourceId): AiObservation
{
    return AiObservation::query()->firstOrCreate([
        'player_id' => $playerId,
        'source_type' => AiObservationSource::FleetMessage,
        'source_id' => $sourceId,
    ], [
        'kind' => AiObservationKind::TransferReceived,
        'subject_player_id' => $senderId,
        'source_time' => CarbonImmutable::parse('2024-01-01 00:00:00 UTC'),
        'observed_at' => CarbonImmutable::parse('2024-01-01 00:00:00 UTC'),
    ]);
}

function joinedObservation(int $playerId, int $joinerId, int $sourceId): AiObservation
{
    return AiObservation::query()->firstOrCreate([
        'player_id' => $playerId,
        'source_type' => AiObservationSource::AllianceMembershipJoined,
        'source_id' => $sourceId,
    ], [
        'kind' => AiObservationKind::AllianceMembershipJoined,
        'subject_player_id' => $joinerId,
        'source_time' => CarbonImmutable::parse('2024-01-01 00:00:00 UTC'),
        'observed_at' => CarbonImmutable::parse('2024-01-01 00:00:00 UTC'),
    ]);
}

test('a received transport earns exactly one thank-you to the sender', function (): void {
    $sender = $this->createUser();
    initiationProfile($this->currentUserId);
    receivedTransportObservation($this->currentUserId, $sender->id, 7001);

    expect(app(InitiateAiSocialContactAction::class)->handle($this->currentUserId, CarbonImmutable::parse('2024-01-01 00:00:00 UTC')))->toBe(1)
        ->and(AiSocialExchange::query()->where('player_id', $this->currentUserId)->where('counterparty_player_id', $sender->id)->where('type', AiSocialExchangeType::TransportThanks)->exists())->toBeTrue()
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->where('recipient_id', $sender->id)->exists())->toBeTrue();
});

test('a transport already thanked is never thanked twice', function (): void {
    $sender = $this->createUser();
    initiationProfile($this->currentUserId);
    receivedTransportObservation($this->currentUserId, $sender->id, 7002);

    $action = app(InitiateAiSocialContactAction::class);
    $now = CarbonImmutable::parse('2024-01-01 00:00:00 UTC');

    expect($action->handle($this->currentUserId, $now))->toBe(1)
        ->and($action->handle($this->currentUserId, $now))->toBe(0)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->where('recipient_id', $sender->id)->count())->toBe(1);
});

test('a self-received transport is never thanked', function (): void {
    initiationProfile($this->currentUserId);
    receivedTransportObservation($this->currentUserId, $this->currentUserId, 7003);

    expect(app(InitiateAiSocialContactAction::class)->handle($this->currentUserId, CarbonImmutable::parse('2024-01-01 00:00:00 UTC')))->toBe(0)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->exists())->toBeFalse();
});

test('a chatty account thanks a whole pile of senders, a quiet one thanks one', function (): void {
    $quiet = $this->createUser();
    $chatty = $this->createUser();
    initiationProfile($quiet->id, 5);   // sociability 0.10 -> one thanks
    initiationProfile($chatty->id, 9);  // sociability 0.90 -> thanks them all

    foreach ([$this->createUser(), $this->createUser(), $this->createUser()] as $index => $sender) {
        receivedTransportObservation($quiet->id, $sender->id, 7100 + $index);
        receivedTransportObservation($chatty->id, $sender->id, 7200 + $index);
    }

    $now = CarbonImmutable::parse('2024-01-01 00:00:00 UTC');
    $action = app(InitiateAiSocialContactAction::class);

    expect($action->handle($quiet->id, $now))->toBe(1)
        ->and($action->handle($chatty->id, $now))->toBe(3);
});

test('a chatty member welcomes a newcomer and a quiet one stays silent', function (): void {
    $chatty = $this->createUser();
    $quiet = $this->createUser();
    $joiner = $this->createUser();
    initiationProfile($chatty->id, 9);  // sociability 0.90 -> greets
    initiationProfile($quiet->id, 5);   // sociability 0.10 -> silent
    joinedObservation($chatty->id, $joiner->id, 8101);
    joinedObservation($quiet->id, $joiner->id, 8102);

    $now = CarbonImmutable::parse('2024-01-01 00:00:00 UTC');
    $action = app(InitiateAiSocialContactAction::class);

    expect($action->handle($chatty->id, $now))->toBe(1)
        ->and(ChatMessage::query()->where('sender_id', $chatty->id)->where('recipient_id', $joiner->id)->exists())->toBeTrue()
        ->and(AiSocialExchange::query()->where('player_id', $chatty->id)->where('counterparty_player_id', $joiner->id)->where('type', AiSocialExchangeType::Greeting)->exists())->toBeTrue()
        ->and($action->handle($quiet->id, $now))->toBe(0)
        ->and(ChatMessage::query()->where('sender_id', $quiet->id)->exists())->toBeFalse();
});

test('a newcomer is welcomed only once', function (): void {
    $chatty = $this->createUser();
    $joiner = $this->createUser();
    initiationProfile($chatty->id, 9);
    joinedObservation($chatty->id, $joiner->id, 8201);

    $action = app(InitiateAiSocialContactAction::class);
    $now = CarbonImmutable::parse('2024-01-01 00:00:00 UTC');

    expect($action->handle($chatty->id, $now))->toBe(1)
        ->and($action->handle($chatty->id, $now))->toBe(0)
        ->and(ChatMessage::query()->where('sender_id', $chatty->id)->where('recipient_id', $joiner->id)->count())->toBe(1);
});

function raidedObservation(int $playerId, int $raiderId, int $sourceId): AiObservation
{
    return AiObservation::query()->firstOrCreate([
        'player_id' => $playerId,
        'source_type' => AiObservationSource::BattleReport,
        'source_id' => $sourceId,
    ], [
        'kind' => AiObservationKind::BattleReportObserved,
        'subject_player_id' => $raiderId,
        'source_time' => CarbonImmutable::parse('2024-01-01 00:00:00 UTC'),
        'observed_at' => CarbonImmutable::parse('2024-01-01 00:00:00 UTC'),
    ]);
}

// A player the raider keeps hitting writes once, "stop attacking me"; the raider's own turn answers it.
test('a raider held at the war line is warned once and never twice', function (string $threat, int $warnings): void {
    $raider = $this->createUser();
    initiationProfile($this->currentUserId);
    raidedObservation($this->currentUserId, $raider->id, 7101);
    Modules\AI\Models\AiRelationship::unguarded(fn () => Modules\AI\Models\AiRelationship::create([
        'player_id' => $this->currentUserId, 'other_player_id' => $raider->id, 'threat' => $threat, 'revision' => 0,
    ]));
    $now = CarbonImmutable::parse('2024-01-01 00:00:00 UTC');

    app(InitiateAiSocialContactAction::class)->handle($this->currentUserId, $now);
    app(InitiateAiSocialContactAction::class)->handle($this->currentUserId, $now);

    expect(AiSocialExchange::query()->where('player_id', $this->currentUserId)->where('type', AiSocialExchangeType::Warning)->count())->toBe($warnings)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->where('recipient_id', $raider->id)->count())->toBe($warnings);
})->with([
    'a grudge at the war line' => ['0.9000', 1],
    'one raid only' => ['0.5000', 0],
]);

// Every persona's warning line must be one the classifier reads back as a warning, or the exchange never happens.
test('every voice\'s raid warning is read as a warning by the receiving account', function (): void {
    $voices = Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 2) . '/resources/behavior/social-voices.yaml');
    $lines = collect($voices)->flatMap(static fn (array $voice): array => $voice['raid_warning'] ?? [])
        ->merge(Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 2) . '/resources/behavior/social-lines.yaml')['default']['raid_warning']);

    expect($lines)->not->toBeEmpty()
        ->and($lines->reject(static fn (string $line): bool => app(Modules\AI\Actions\ClassifyInboundSocialExchangeAction::class)->handle($line)?->type === AiSocialExchangeType::Warning)->all())->toBe([]);
});

// A warned raider that keeps hitting is asked for a truce once, a quarter day after the warning.
test('a raider who kept hitting after the warning is asked for a truce once', function (int $hoursAfterWarning, bool $newRaid, int $pleas): void {
    $raider = $this->createUser();
    initiationProfile($this->currentUserId);
    $warnedAt = CarbonImmutable::parse('2024-01-01 00:00:00 UTC');
    $first = raidedObservation($this->currentUserId, $raider->id, 7201);
    AiSocialExchange::unguarded(fn () => AiSocialExchange::create([
        'player_id' => $this->currentUserId, 'counterparty_player_id' => $raider->id, 'source_observation_id' => $first->id,
        'type' => AiSocialExchangeType::Warning, 'terms' => [], 'state' => 1, 'revision' => 1, 'protocol_depth' => 1,
        'created_at' => $warnedAt, 'updated_at' => $warnedAt,
    ]));
    if ($newRaid) {
        AiObservation::query()->where('id', raidedObservation($this->currentUserId, $raider->id, 7202)->id)->update(['source_time' => $warnedAt->addMinutes(30)]);
    }
    $now = $warnedAt->addHours($hoursAfterWarning);

    app(InitiateAiSocialContactAction::class)->handle($this->currentUserId, $now);
    app(InitiateAiSocialContactAction::class)->handle($this->currentUserId, $now);

    expect(AiSocialExchange::query()->where('player_id', $this->currentUserId)->where('type', AiSocialExchangeType::CeasefireRequest)->count())->toBe($pleas);
})->with([
    'hit again, a day later' => [24, true, 1],
    'hit again but only an hour later' => [1, true, 0],
    'not hit again' => [24, false, 0],
]);
