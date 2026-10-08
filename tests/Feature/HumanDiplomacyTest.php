<?php

use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use Modules\AI\Actions\AdvanceAiAlliancePoliticsAction;
use Modules\AI\Actions\ReactAiToHumanMessageAction;
use Modules\AI\Actions\ReviewAiAllianceApplicationsAction;
use Modules\AI\Domain\Decision\Enemies;
use Modules\AI\Enums\AiAllianceStance;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiAllianceRelation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use OGame\Models\Alliance;
use OGame\Models\AllianceApplication;
use OGame\Models\ChatMessage;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// Humans take part in the alliance politics through the same rows the AI leaders write (llm-classifier-everywhere.md,
// step 3): a leader's message, an alliance page, an application text, each read by the classifier.

beforeEach(function (): void {
    config(['ai.classification.enabled' => true]);
    Queue::fake();
});

function diplomacyLeader(int $playerId, string $tag): Alliance
{
    AiProfile::create(['player_id' => $playerId, 'archetype' => AiArchetype::Fleeter, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 1, 'enabled' => true]);

    return app(AllianceService::class)->createAlliance($playerId, $tag, $tag . ' Club');
}

function diplomacyAct(string $act): array
{
    return [
        'act' => new ChoiceAnswer($act, [$act => 0.9]),
        'urgency' => new ScoreAnswer(0.0, [1.0, 0.0, 0.0], ['day', 'hours', 'now']),
        'needs_reply' => new BooleanAnswer(0.9),
        'language' => new ChoiceAnswer('en', ['en' => 0.99]),
        // The outbound read of the AI leader's answer: a plain line.
        'bot' => new BooleanAnswer(0.1),
        'leak' => new BooleanAnswer(0.0),
    ];
}

function diplomacyMessage(int $from, int $to, string $text): int
{
    return (int) ChatMessage::create(['sender_id' => $from, 'recipient_id' => $to, 'message' => $text])->id;
}

test('a human leader\'s declaration puts the AI alliance at war with theirs', function (): void {
    $ours = diplomacyLeader($this->currentUserId, 'OURS');
    $human = User::factory()->create();
    $theirs = app(AllianceService::class)->createAlliance($human->id, 'HUM', 'Humans');
    Classification::fake([diplomacyAct('war')]);

    app(ReactAiToHumanMessageAction::class)->handle(diplomacyMessage($human->id, $this->currentUserId, 'your alliance is done, we hit you from tonight'));

    expect(AiAllianceRelation::counterparts($ours->id, AiAllianceStance::War))->toBe([$theirs->id])
        ->and(app(Enemies::class)->of($this->currentUserId))->toHaveKey($human->id);
});

test('a human leader\'s pact offer is taken unless the AI leader hates their side', function (bool $hated, bool $pact): void {
    $ours = diplomacyLeader($this->currentUserId, 'OURS');
    $human = User::factory()->create();
    $theirs = app(AllianceService::class)->createAlliance($human->id, 'HUM', 'Humans');
    if ($hated) {
        AiRelationship::unguarded(fn () => AiRelationship::create(['player_id' => $this->currentUserId, 'other_player_id' => $human->id, 'threat' => '1.0000', 'revision' => 0]));
    }
    Classification::fake(fn ($prompt): array => diplomacyAct('pact_offer'));

    app(ReactAiToHumanMessageAction::class)->handle(diplomacyMessage($human->id, $this->currentUserId, 'nap between us?'));

    expect(AiAllianceRelation::counterparts($ours->id, AiAllianceStance::Nap) === [$theirs->id])->toBe($pact)
        ->and(ChatMessage::query()->where('sender_id', $this->currentUserId)->where('recipient_id', $human->id)->exists())->toBeTrue();
})->with([
    'a leader with no grudge' => [false, true],
    'a leader whose members they raided' => [true, false],
]);

test('a human\'s acceptance counts only for a pact the AI leader offered', function (): void {
    $ours = diplomacyLeader($this->currentUserId, 'OURS');
    $human = User::factory()->create();
    $theirs = app(AllianceService::class)->createAlliance($human->id, 'HUM', 'Humans');
    $enemy = app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'ENEM', 'Enemy');
    Classification::fake(fn ($prompt): array => str_contains((string) $prompt->state, 'We are both') ? ['bot' => new BooleanAnswer(0.1), 'leak' => new BooleanAnswer(0.0)] : diplomacyAct('pact_accept'));

    app(ReactAiToHumanMessageAction::class)->handle(diplomacyMessage($human->id, $this->currentUserId, 'deal, nap it is'));
    $unasked = AiAllianceRelation::counterparts($ours->id, AiAllianceStance::Nap);

    foreach ([$ours->id, $theirs->id] as $side) {
        AiAllianceRelation::query()->create(['alliance_id' => $side, 'other_alliance_id' => $enemy->id, 'stance' => AiAllianceStance::War, 'cause' => 'test', 'started_at' => now()]);
    }
    app(AdvanceAiAlliancePoliticsAction::class)->handle();
    app(AdvanceAiAlliancePoliticsAction::class)->handle();
    $offers = ChatMessage::query()->where('sender_id', $this->currentUserId)->where('recipient_id', $human->id)->count();
    app(ReactAiToHumanMessageAction::class)->handle(diplomacyMessage($human->id, $this->currentUserId, 'deal, nap it is'));

    expect($unasked)->toBe([])
        ->and($offers)->toBe(1)
        ->and(AiAllianceRelation::counterparts($ours->id, AiAllianceStance::Nap))->toBe([$theirs->id]);
});

test('a human alliance page that declares war on the AI alliance is a war', function (): void {
    $ours = diplomacyLeader($this->currentUserId, 'OURS');
    $human = User::factory()->create();
    $theirs = app(AllianceService::class)->createAlliance($human->id, 'HUM', 'Humans');
    Alliance::query()->whereKey($theirs->id)->update(['external_text' => "Recruiting 500k+\nWars: [OURS]"]);
    Classification::fake([['stance' => new ChoiceAnswer('war', ['war' => 0.95])]])->preventStrayClassifications();

    app(AdvanceAiAlliancePoliticsAction::class)->handle();
    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect(AiAllianceRelation::counterparts($ours->id, AiAllianceStance::War))->toBe([$theirs->id])
        ->and(AiAllianceRelation::query()->count())->toBe(1);
});

test('an application the classifier reads as an enemy\'s spy is refused', function (string $kind, int $status): void {
    diplomacyLeader($this->currentUserId, 'OURS');
    $alliance = Alliance::query()->where('founder_user_id', $this->currentUserId)->sole();
    $applicant = User::factory()->create();
    Highscore::unguarded(fn () => Highscore::updateOrCreate(['player_id' => $applicant->id], ['general' => 1000, 'general_rank' => 1, 'economy' => 1000, 'research' => 1000]));
    $application = app(AllianceService::class)->applyToAlliance($applicant->id, $alliance->id, 'hi, what fleets do your members run and when are they online?');
    AllianceApplication::unguarded(fn () => AllianceApplication::whereKey($application->id)->update(['created_at' => now()->subMinutes(30)]));
    Classification::fake([['kind' => new ChoiceAnswer($kind, [$kind => 0.9])]]);

    app(ReviewAiAllianceApplicationsAction::class)->handle();

    expect($application->fresh()->status)->toBe($status);
})->with([
    'a spy' => ['spy', AllianceApplication::STATUS_REJECTED],
    'a serious player' => ['serious', AllianceApplication::STATUS_ACCEPTED],
]);

test('a human mate\'s ACS call on a target makes its owner an enemy of every AI mate in the channel', function (bool $mate, bool $enemy): void {
    $ours = diplomacyLeader($this->currentUserId, 'OURS');
    $human = User::factory()->create();
    if ($mate) {
        User::query()->whereKey($human->id)->update(['alliance_id' => $ours->id]);
    }
    $target = $this->createForeignPlanet();
    $at = $target->getPlanetCoordinates();
    Classification::fake(fn ($prompt): array => diplomacyAct('acs_call'));

    $message = $mate
        ? ChatMessage::create(['sender_id' => $human->id, 'alliance_id' => $ours->id, 'message' => "acs on {$at->galaxy}:{$at->system}:{$at->position} at 22:00, join"])
        : ChatMessage::create(['sender_id' => $human->id, 'recipient_id' => $this->currentUserId, 'message' => "acs on {$at->galaxy}:{$at->system}:{$at->position} at 22:00, join"]);
    app(ReactAiToHumanMessageAction::class)->handle($message->id);

    expect(isset(app(Enemies::class)->of($this->currentUserId)[$target->getPlayer()->getId()]))->toBe($enemy);
})->with([
    'a mate in the alliance channel' => [true, true],
    'a stranger in a direct message' => [false, false],
]);
