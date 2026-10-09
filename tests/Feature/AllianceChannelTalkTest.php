<?php

use Carbon\CarbonImmutable;
use Modules\AI\Actions\SpeakInAllianceChannelAction;
use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\NativeSocialCognition;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\RandomSource;
use Modules\AI\Support\SeededRandomSource;
use Modules\AI\Tests\Support\FixtureAiClock;
use OGame\Models\BattleReport;
use OGame\Models\ChatMessage;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../Support/FixtureAiClock.php';

uses(IsolatedAccountTestCase::class);

const CHANNEL_NOW = '2024-01-01 12:00:00 UTC';

beforeEach(function (): void {
    app()->bind(SocialCognition::class, NativeSocialCognition::class);
    app()->bind(RandomSource::class, SeededRandomSource::class);
    app()->bind(AiClock::class, fn (): FixtureAiClock => app()->makeWith(FixtureAiClock::class, ['now' => CarbonImmutable::parse(CHANNEL_NOW)]));
    $this->travelTo(CarbonImmutable::parse(CHANNEL_NOW));
});

/** A two-member club, both AI, and a battle in which the second member (the victim) was hit by an outsider. */
function channelClubUnderAttack(): array
{
    $speaker = User::factory()->create();
    $victim = User::factory()->create();
    $attacker = User::factory()->create();
    foreach ([$speaker, $victim] as $member) {
        AiProfile::create(['player_id' => $member->id, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 9, 'enabled' => true]);
    }
    $alliance = app(AllianceService::class)->createAlliance($speaker->id, 'CHAN' . $speaker->id % 100, 'Channel');
    User::query()->whereKey($victim->id)->update(['alliance_id' => $alliance->id]);
    $report = BattleReport::unguarded(fn () => BattleReport::create(['planet_galaxy' => 2, 'planet_system' => 45, 'planet_position' => 7, 'planet_user_id' => $victim->id, 'planet_type' => 1]));
    foreach ([[$speaker, AiObservationKind::AllyUnderAttack], [$victim, AiObservationKind::BattleReportObserved]] as [$member, $kind]) {
        AiObservation::create([
            'player_id' => $member->id, 'source_type' => AiObservationSource::BattleReport, 'source_id' => $report->id, 'kind' => $kind,
            'subject_player_id' => $attacker->id, 'source_time' => CarbonImmutable::parse(CHANNEL_NOW)->subMinutes(10), 'observed_at' => CarbonImmutable::parse(CHANNEL_NOW),
        ]);
    }

    return [$alliance->id, $speaker, $victim, $attacker];
}

// 9475: 948 mates were hit and the alliance channel stayed empty, because it was reply-only.
test('a member hit by an outsider calls for help in the alliance channel with who and where', function (): void {
    [$allianceId, , $victim, $attacker] = channelClubUnderAttack();

    expect(app(SpeakInAllianceChannelAction::class)->handle($victim->id, CarbonImmutable::parse(CHANNEL_NOW)))->toBe(1);

    $line = ChatMessage::query()->where('alliance_id', $allianceId)->sole();
    expect($line->sender_id)->toBe($victim->id)
        ->and($line->message)->toContain($attacker->username)->toContain('2:45:7');
});

test('a mate who saw the hit says so, and only one member speaks for the alliance', function (): void {
    [$allianceId, $speaker, $victim, $attacker] = channelClubUnderAttack();
    $action = app(SpeakInAllianceChannelAction::class);
    $now = CarbonImmutable::parse(CHANNEL_NOW);

    expect($action->handle($speaker->id, $now))->toBe(1)
        ->and($action->handle($victim->id, $now))->toBe(0)
        ->and($action->handle($speaker->id, $now))->toBe(0)
        ->and(ChatMessage::query()->where('alliance_id', $allianceId)->sole()->message)->toContain($attacker->username)->toContain($victim->username);
});

test('an old hit is not announced', function (): void {
    [, , $victim] = channelClubUnderAttack();

    expect(app(SpeakInAllianceChannelAction::class)->handle($victim->id, CarbonImmutable::parse(CHANNEL_NOW)->addHours(5)))->toBe(0);
});

// Words that go with a fleet: the member who sends ships to hold at a mate says what and where.
test('a member who sends a defence to a mate says how many ships and where', function (): void {
    [$allianceId, $speaker, $victim] = channelClubUnderAttack();
    $planet = OGame\Models\Planet::factory()->create(['user_id' => $victim->id, 'galaxy' => 2, 'system' => 45, 'planet' => 7]);

    expect(app(SpeakInAllianceChannelAction::class)->announceDefend($speaker->id, $planet->id, 120))->toBeTrue();

    expect(ChatMessage::query()->where('alliance_id', $allianceId)->sole()->message)->toContain('120')->toContain($victim->username)
        ->and(app(SpeakInAllianceChannelAction::class)->announceDefend($speaker->id, $planet->id, 0))->toBeFalse();
});
