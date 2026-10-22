<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\BreakAiCeasefireAction;
use Modules\AI\Actions\InitiateAiSocialContactAction;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\ChatMessage;
use Modules\AI\Actions\EvaluateAiSocialExchangeAction;
use Modules\AI\Actions\RecordAiRelationshipInteractionAction;
use Modules\AI\Actions\RecordAiSocialExchangeAction;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\NativeSocialCognition;
use Modules\AI\Domain\Decision\Enemies;
use Modules\AI\Enums\AiCommitmentState;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Enums\AiSocialResponseReason;
use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Domain\Conversation\MemoryRecallQuery;
use Modules\AI\Actions\BuildAuthoredSocialReplyAction;
use Modules\AI\Actions\ClassifyInboundSocialExchangeAction;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Models\AiCommitment;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiSocialExchange;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

const CEASEFIRE_NOW = '2026-10-10 12:00:00';

beforeEach(function (): void {
    app()->bind(SocialCognition::class, NativeSocialCognition::class);
    $this->travelTo(CarbonImmutable::parse(CEASEFIRE_NOW));
});

function askedForTruce(int $playerId, int $askerId): AiSocialExchange|null
{
    $exchange = app(RecordAiSocialExchangeAction::class)->handle($playerId, $askerId, 92001, AiSocialExchangeType::CeasefireRequest, []);

    return app(EvaluateAiSocialExchangeAction::class)->handle($exchange?->id ?? 0, 0, CarbonImmutable::now());
}

// Every judge reading flagged truce talk with no terms: a ceasefire was always answered "we'll see" and never held.
test('a ceasefire asked for under the refusal line is agreed for a day and binds both sides', function (bool $inAlliance): void {
    $asker = User::factory()->create();
    if ($inAlliance) {
        app(AllianceService::class)->createAlliance($this->currentUserId, 'TRC' . $this->currentUserId % 100, 'Truce');
    }

    $exchange = askedForTruce($this->currentUserId, $asker->id);

    expect($exchange?->response)->toBe(AiSocialResponse::Accept)
        ->and($exchange?->commitment_id)->not->toBeNull()
        ->and(AiCommitment::query()->find($exchange?->commitment_id)?->due_at?->equalTo(CarbonImmutable::now()->addHours(24)))->toBeTrue()
        ->and(app(Enemies::class)->spared($this->currentUserId))->toContain($asker->id)
        ->and(app(Enemies::class)->spared($asker->id))->toContain($this->currentUserId);

    $this->travel(25)->hours();

    expect(app(Enemies::class)->spared($this->currentUserId))->not->toContain($asker->id);
})->with(['alone' => [false], 'in an alliance' => [true]]);

test('a ceasefire is refused to a player already held as an enemy', function (): void {
    $asker = User::factory()->create();
    app(RecordAiRelationshipInteractionAction::class)->handle($this->currentUserId, $asker->id, 92000, CarbonImmutable::now(), threatChange: 0.9);

    expect(askedForTruce($this->currentUserId, $asker->id)?->response)->toBe(AiSocialResponse::Reject)
        ->and(app(Enemies::class)->spared($this->currentUserId))->not->toContain($asker->id);
});

test('a hit inside the ceasefire breaks it and the attacker is no longer spared', function (): void {
    $asker = User::factory()->create();
    $commitmentId = askedForTruce($this->currentUserId, $asker->id)?->commitment_id;

    expect(app(BreakAiCeasefireAction::class)->handle($asker->id, $this->currentUserId))->toBe(1)
        ->and(AiCommitment::query()->find($commitmentId)?->state)->toBe(AiCommitmentState::Broken)
        ->and(app(Enemies::class)->spared($this->currentUserId))->not->toContain($asker->id);
});

test('the player whose truce was broken says so once, to the one who broke it', function (): void {
    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 7, 'enabled' => true]);
    $asker = User::factory()->create();
    askedForTruce($this->currentUserId, $asker->id);
    app(BreakAiCeasefireAction::class)->handle($asker->id, $this->currentUserId);

    $first = app(InitiateAiSocialContactAction::class)->handle($this->currentUserId, CarbonImmutable::now());
    $second = app(InitiateAiSocialContactAction::class)->handle($this->currentUserId, CarbonImmutable::now());

    expect($first)->toBe(1)
        ->and($second)->toBe(0)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->where('recipient_id', $asker->id)->count())->toBe(1);
});

// A betrayed account remembers: the one who broke a truce is not given another on the same words, a stranger is.
test('a player who broke a truce with the account is refused the next one', function (): void {
    $breaker = User::factory()->create();
    $stranger = User::factory()->create();
    askedForTruce($this->currentUserId, $breaker->id);
    app(BreakAiCeasefireAction::class)->handle($breaker->id, $this->currentUserId);

    $again = app(RecordAiSocialExchangeAction::class)->handle($this->currentUserId, $breaker->id, 92002, AiSocialExchangeType::CeasefireRequest, []);
    $refused = app(EvaluateAiSocialExchangeAction::class)->handle($again?->id ?? 0, 0, CarbonImmutable::now());

    expect($refused?->response)->toBe(AiSocialResponse::Reject)
        ->and($refused?->response_reason)->toBe(AiSocialResponseReason::TruceBrokenBefore)
        ->and(askedForTruce($this->currentUserId, $stranger->id)?->response)->toBe(AiSocialResponse::Accept);
});

// The memory driver ranks sentences, so an agreed and a broken truce are stored as sentences it can be asked about.
test('an agreed and a broken truce are remembered as readable sentences', function (): void {
    $asker = User::factory()->create();
    askedForTruce($this->currentUserId, $asker->id);
    app(BreakAiCeasefireAction::class)->handle($asker->id, $this->currentUserId);

    $facts = app(LongTermMemory::class)->recallRelevantMemories(app()->makeWith(MemoryRecallQuery::class, [
        'playerId' => $this->currentUserId,
        'subjectPlayerId' => $asker->id,
        'now' => CarbonImmutable::now(),
        'limit' => 5,
        'queryText' => 'you broke our truce',
    ]));

    expect(array_column(array_column($facts, 'value'), 'summary'))
        ->toContain('we agreed a ceasefire of 24 hours')
        ->toContain('they hit us during an agreed ceasefire');
});

// 9509 to 9511: "no deal" and "good try ;)" with no mention of the raids that came before them.
test('a refused truce names how many times the asker has hit the account', function (): void {
    $asker = User::factory()->create();
    foreach ([8101, 8102, 8103] as $reportId) {
        AiObservation::query()->create([
            'player_id' => $this->currentUserId,
            'source_type' => AiObservationSource::BattleReport,
            'source_id' => $reportId,
            'kind' => AiObservationKind::BattleReportObserved,
            'subject_player_id' => $asker->id,
            'source_time' => CarbonImmutable::now(),
            'observed_at' => CarbonImmutable::now(),
        ]);
    }
    app(RecordAiRelationshipInteractionAction::class)->handle($this->currentUserId, $asker->id, 92000, CarbonImmutable::now(), threatChange: 0.9);

    $refused = askedForTruce($this->currentUserId, $asker->id);
    $profile = AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 7, 'enabled' => true]);

    expect($refused?->response)->toBe(AiSocialResponse::Reject)
        ->and(app(BuildAuthoredSocialReplyAction::class)->handle($refused, $profile))->toContain('3');
});

// A veteran answers a truce with its length and gives it a fixed end date, never an open-ended one (veteran situations:
// truce_after_raids, nap_request_from_war_ally, vacation_truce_return).
test('a truce runs for the length the asker names, capped at two weeks, and the reply states it', function (string $message, int $hours, string $said): void {
    $asker = User::factory()->create();
    $inbound = app(ClassifyInboundSocialExchangeAction::class)->handle($message);
    $exchange = app(RecordAiSocialExchangeAction::class)->handle($this->currentUserId, $asker->id, 92003, $inbound->type, $inbound->terms);
    $agreed = app(EvaluateAiSocialExchangeAction::class)->handle($exchange?->id ?? 0, 0, CarbonImmutable::now());
    $profile = AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 7, 'enabled' => true]);

    expect($inbound->type)->toBe(AiSocialExchangeType::CeasefireRequest)
        ->and($agreed?->response)->toBe(AiSocialResponse::Accept)
        ->and(AiCommitment::query()->find($agreed?->commitment_id)?->due_at?->equalTo(CarbonImmutable::now()->addHours($hours)))->toBeTrue()
        ->and(app(BuildAuthoredSocialReplyAction::class)->handle($agreed, $profile))->toContain($said);
})->with([
    'no length named' => ['truce please', 24, '24h'],
    'hours' => ['nap 36h?', 36, '36h'],
    'days' => ['truce for 3 days?', 72, '3 days'],
    'a week' => ['nap for a week', 168, '7 days'],
    'longer than two weeks' => ['nap for 2 months', 336, '14 days'],
    'open ended' => ['can we have a permanent nap', 336, '14 days'],
]);
