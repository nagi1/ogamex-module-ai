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
