<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\AI\Actions\AdvanceAiAlliancePoliticsAction;
use Modules\AI\Contracts\AllianceTreaties;
use Modules\AI\Domain\Social\SocialMinds;
use Modules\AI\Domain\Social\SocialPersona;
use Modules\AI\Enums\AiAllianceStance;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiAllianceCohesion;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiSocialMind;
use OGame\Models\Alliance;
use OGame\Models\AllianceMember;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// Cycles (the simulator's arm `cycles`, diplomacy.rs `cycle`): asabiyya, schism and nomad uprisings, with the minds on.
// The gateway is faked where the test needs FAtiMA's Revolt choice, and down otherwise.

/** An enabled AI account with its own mind: traits 0.5 but those given, and its public points. */
function socialCyclesAccount(int $userId, int $points, array $traits = []): void
{
    AiProfile::query()->firstOrCreate(['player_id' => $userId], ['archetype' => AiArchetype::Fleeter, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 3, 'enabled' => true]);
    Highscore::query()->updateOrCreate(['player_id' => $userId], ['general' => $points]);
    $minds = app(SocialMinds::class);
    $minds->put($userId, 'persona', ['archetype' => 'test', 'traits' => $traits + array_fill_keys(SocialPersona::TRAITS, 0.5)]);
    $minds->save();
}

function socialCyclesClub(int $founderId, string $tag, int $points, float|null $cohesion = null): Alliance
{
    $club = app(AllianceService::class)->createAlliance($founderId, $tag, $tag . ' Club');
    Highscore::query()->updateOrCreate(['player_id' => $founderId], ['general' => $points]);
    if ($cohesion !== null) {
        AiAllianceCohesion::query()->create(['alliance_id' => $club->id, 'asabiyya' => $cohesion]);
    }

    return $club;
}

function socialCyclesJoin(int $userId, int $allianceId): void
{
    User::query()->whereKey($userId)->update(['alliance_id' => $allianceId]);
    AllianceMember::unguarded(fn () => AllianceMember::create(['alliance_id' => $allianceId, 'user_id' => $userId, 'rank_id' => null, 'joined_at' => now()]));
}

function socialCyclesOn(bool $cycles = true): void
{
    config(['ai.cognition.social.minds' => true, 'ai.cognition.social.cycles' => $cycles]);
}

/** The gateway answering: FAtiMA's exchange picks $revolt when Revolt is an option, Ignore otherwise. */
function socialCyclesGateway(string $revolt): void
{
    Http::fake(static fn (Request $request) => str_ends_with($request->url(), '/health')
        ? Http::response(['status' => 'ok'])
        : Http::response(['results' => array_map(static fn (array $item): array => ['results' => [
            $item['route'] === '/social/exchange' ? ['choice' => in_array('Revolt', $item['body']['items'][0]['options'], true) ? $revolt : 'Ignore'] : [],
        ]], $request->data()['items'])]));
}

function socialCyclesPin(int $userId): int
{
    return (int) (AiSocialMind::query()->where('user_id', $userId)->first()?->state['pinned'] ?? 0);
}

function socialCyclesAlliance(int $userId): int|null
{
    return User::query()->whereKey($userId)->value('alliance_id');
}

/**
 * A soft club of six: the founder leads, one ambitious and strong member, two disloyal and two loyal ones.
 *
 * @return array{0: Alliance, 1: int, 2: list<int>, 3: list<int>}
 */
function socialCyclesSoftClub(int $founderId): array
{
    socialCyclesAccount($founderId, 1_000);
    $club = socialCyclesClub($founderId, 'SOFT', 1_000, 0.3);
    $head = User::factory()->create()->id;
    socialCyclesJoin($head, $club->id);
    socialCyclesAccount($head, 5_000, ['ambition' => 0.9]);
    $members = [];
    foreach ([0.2, 0.2, 0.9, 0.9] as $loyalty) {
        $member = User::factory()->create()->id;
        socialCyclesJoin($member, $club->id);
        socialCyclesAccount($member, 500, ['loyalty' => $loyalty]);
        $members[] = $member;
    }

    return [$club, $head, array_slice($members, 0, 2), array_slice($members, 2)];
}

test('asabiyya wears down in peace and grows in war, once a day', function (bool $war, float $after): void {
    socialCyclesOn();
    Http::fake(['*' => Http::response('down', 503)]);
    socialCyclesAccount($this->currentUserId, 1_000);
    $ours = socialCyclesClub($this->currentUserId, 'OURS', 1_000, 0.5);
    $other = socialCyclesClub(User::factory()->create()->id, 'OTHR', 1_000);
    if ($war) {
        app(AllianceTreaties::class)->openWar($other->id, $ours->id, 'test', null);
    }

    app(AdvanceAiAlliancePoliticsAction::class)->handle();
    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect(AiAllianceCohesion::query()->where('alliance_id', $ours->id)->value('asabiyya'))->toEqualWithDelta($after, 1e-9)
        ->and(AiAllianceCohesion::query()->where('alliance_id', $other->id)->value('asabiyya'))->toEqualWithDelta($war ? 1.0 : 0.99, 1e-9);
})->with(['at peace' => [false, 0.49], 'at war' => [true, 0.52]]);

test('a soft club splits only when its strongest ambitious member feels like revolting', function (string $choice, bool $splits): void {
    socialCyclesOn();
    socialCyclesGateway($choice);
    [$club, $head, $disloyal, $loyal] = socialCyclesSoftClub($this->currentUserId);

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    foreach ([$head, ...$disloyal] as $mover) {
        expect(socialCyclesAlliance($mover))->toBe($splits ? null : $club->id)
            ->and(socialCyclesPin($mover))->toBe($splits ? $head : 0);
    }
    foreach ($loyal as $stayer) {
        expect(socialCyclesAlliance($stayer))->toBe($club->id);
    }

    // Past the host's join cooldown the head founds its club and the disloyal follow it there.
    $this->travel(4)->days();
    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    $splinter = Alliance::query()->where('founder_user_id', $head)->value('id');
    expect($splinter !== null)->toBe($splits);
    foreach ($splits ? $disloyal : [] as $follower) {
        expect(socialCyclesAlliance($follower))->toBe($splinter);
    }
})->with(['FAtiMA chooses Revolt' => ['Revolt', true], 'FAtiMA chooses Ignore' => ['Ignore', false]]);

test('nomads rise only against a ruler that leads the second club by half again', function (int $second, bool $rises): void {
    socialCyclesOn();
    Http::fake(['*' => Http::response('down', 503)]);
    socialCyclesAccount($this->currentUserId, 10_000);
    $ruler = socialCyclesClub($this->currentUserId, 'RULE', 10_000, 0.3);
    socialCyclesClub(User::factory()->create()->id, 'SCND', $second);
    $chief = User::factory()->create()->id;
    socialCyclesAccount($chief, 500, ['ambition' => 0.9, 'dominance' => 0.9, 'courage' => 0.9]);
    $riders = [User::factory()->create()->id, User::factory()->create()->id];
    foreach ($riders as $rider) {
        socialCyclesAccount($rider, 100, ['freedom' => 0.9]);
    }

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    $band = Alliance::query()->where('founder_user_id', $chief)->value('id');
    expect($band !== null)->toBe($rises);
    foreach ($riders as $rider) {
        expect(socialCyclesAlliance($rider))->toBe($band)
            ->and(socialCyclesPin($rider))->toBe($rises ? $chief : 0);
    }

    // Once it stands as a club, the band goes to war on the ruler.
    $this->travel(1)->days();
    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect($rises && app(AllianceTreaties::class)->holds((int) $band, $ruler->id, AiAllianceStance::War))->toBe($rises);
})->with(['a ruler' => [1_000, true], 'no ruler' => [9_000, false]]);

test('with cycles off nothing wears, splits or rises', function (): void {
    socialCyclesOn(false);
    socialCyclesGateway('Revolt');
    [$club, $head, $disloyal] = socialCyclesSoftClub($this->currentUserId);
    $chief = User::factory()->create()->id;
    socialCyclesAccount($chief, 500, ['ambition' => 0.9, 'dominance' => 0.9, 'courage' => 0.9]);

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect(AiAllianceCohesion::query()->where('alliance_id', $club->id)->value('asabiyya'))->toEqualWithDelta(0.3, 1e-9)
        ->and(AiAllianceCohesion::query()->count())->toBe(1)
        ->and(socialCyclesAlliance($head))->toBe($club->id)
        ->and(socialCyclesAlliance($disloyal[0]))->toBe($club->id)
        ->and(socialCyclesPin($head))->toBe(0)
        ->and(Alliance::query()->where('founder_user_id', $chief)->exists())->toBeFalse();
});
