<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Modules\AI\Actions\AdvanceAiAlliancePoliticsAction;
use Modules\AI\Contracts\AllianceTreaties;
use Modules\AI\Domain\Decision\Enemies;
use Modules\AI\Domain\Social\SocialMinds;
use Modules\AI\Domain\Social\SocialPersona;
use Modules\AI\Enums\AiAllianceStance;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Models\AiSocialMind;
use OGame\GameMissions\TransportMission;
use OGame\Models\Alliance;
use OGame\Models\AllianceMember;
use OGame\Models\ChatMessage;
use OGame\Models\FleetMission;
use OGame\Models\Highscore;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// Living minds (SOCIAL-ENGINES-CONTRACT.md, phase E): AI leaders decide through the social gateway as the simulator's
// arm `minds` does. The gateway is faked: each engine route answers what the test gives it.

// Seed 2 draws a leader whose taste betrays a pact by the YAML rule, seed 1 one that keeps it (alliance-ambition.yaml).
function socialMindsLeader(int $playerId, string $tag, int $seed = 1): Alliance
{
    AiProfile::create(['player_id' => $playerId, 'archetype' => AiArchetype::Fleeter, 'skill_band' => AiSkillBand::Standard, 'random_seed' => $seed, 'enabled' => true]);

    return app(AllianceService::class)->createAlliance($playerId, $tag, $tag . ' Club');
}

function socialMindsPoints(int $playerId, int $points): void
{
    Highscore::query()->updateOrCreate(['player_id' => $playerId], ['general' => $points]);
}

/** @param array<string, array<string, mixed>> $answers engine + route => the first result of that route's answer */
function socialMindsGateway(array $answers): void
{
    config(['ai.cognition.social.minds' => true]);
    Http::fake(static fn (Request $request) => str_ends_with($request->url(), '/health')
        ? Http::response(['status' => 'ok'])
        : Http::response(['results' => array_map(static fn (array $item): array => ['results' => [$answers[$item['engine'] . $item['route']] ?? []]], $request->data()['items'])]));
}

/** @return list<array<string, mixed>> */
function socialMindsMemories(int $userId): array
{
    $state = AiSocialMind::query()->where('user_id', $userId)->firstOrFail()->state;

    return [...$state['memories'], ...$state['fresh']];
}

/** A leader at peace with a pact partner a tenth its size: the rule's betrayer turns on it. */
function socialMindsOutgrownPact(int $leaderId, int $seed): array
{
    $ours = socialMindsLeader($leaderId, 'OURS', $seed);
    $partnerLeader = User::factory()->create();
    $partner = socialMindsLeader($partnerLeader->id, 'PART');
    app(AllianceTreaties::class)->openPact($ours->id, $partner->id, 'test', $leaderId);
    socialMindsPoints($leaderId, 10_000);
    socialMindsPoints($partnerLeader->id, 1_000);

    return [$ours, $partner, $partnerLeader];
}

test('with living minds off the YAML rules decide and no engine is asked', function (): void {
    Http::fake();
    [$ours, $partner] = socialMindsOutgrownPact($this->currentUserId, 2);
    $treaties = app(AllianceTreaties::class);

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    // With no persona there is no surprise: the pact is cancelled by notice and ends in war only once it runs out.
    expect($treaties->inNotice($ours->id, $partner->id))->toBeTrue()
        ->and($treaties->holds($ours->id, $partner->id, AiAllianceStance::Nap))->toBeTrue()
        ->and(AiSocialMind::query()->count())->toBe(0);
    $this->travel(49)->hours();
    app(AdvanceAiAlliancePoliticsAction::class)->handle();
    expect($treaties->holds($ours->id, $partner->id, AiAllianceStance::War))->toBeTrue();
    Http::assertNothingSent();
});

test('with the gateway down the YAML rules decide while the minds still record', function (): void {
    config(['ai.cognition.social.minds' => true]);
    Http::fake(['*' => Http::response('down', 503)]);
    [$ours, $partner, $partnerLeader] = socialMindsOutgrownPact($this->currentUserId, 2);

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect(app(AllianceTreaties::class)->inNotice($ours->id, $partner->id))->toBeTrue()
        ->and(collect(socialMindsMemories($partnerLeader->id))->where('event', 'pact_cancelled')->where('actor', $this->currentUserId)->count())->toBe(1)
        ->and(collect(socialMindsMemories($partnerLeader->id))->where('event', 'betrayed')->count())->toBe(0);
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/v1/batch'));
});

test('a PsychSim betrayal cancels the pact by notice: the partner stays spared until it runs out, and its members remember who did it', function (): void {
    socialMindsGateway(['psychsim/social/partner' => ['accept_pact' => true, 'betray_now' => true, 'help_now' => false, 'p_loyal' => 0.2], 'fatima/social/exchange' => ['choice' => 'Ignore']]);
    // Seed 1 keeps its pacts by the YAML rule: the betrayal here is the engine's.
    [$ours, $partner, $partnerLeader] = socialMindsOutgrownPact($this->currentUserId, 1);
    $member = User::factory()->create();
    User::query()->whereKey($member->id)->update(['alliance_id' => $partner->id]);
    AiProfile::create(['player_id' => $member->id, 'archetype' => AiArchetype::Fleeter, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 3, 'enabled' => true]);

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    $treaties = app(AllianceTreaties::class);
    expect($treaties->inNotice($ours->id, $partner->id))->toBeTrue()
        ->and(app(Enemies::class)->spared($this->currentUserId))->toContain($partnerLeader->id, $member->id)
        ->and(collect(socialMindsMemories($partnerLeader->id))->where('event', 'pact_cancelled')->where('actor', $this->currentUserId)->count())->toBe(1)
        ->and(collect(socialMindsMemories($member->id))->where('event', 'pact_cancelled')->where('actor', $this->currentUserId)->count())->toBe(1)
        ->and(collect(socialMindsMemories($partnerLeader->id))->where('event', 'betrayed')->count())->toBe(0)
        ->and(app(SocialMinds::class)->get($this->currentUserId, 'broken', 0))->toBe(0)
        ->and(AiSocialMind::query()->where('user_id', $member->id)->firstOrFail()->state['persona']['traits'])->toHaveCount(12);

    $this->travel(49)->hours();
    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect($treaties->holds($ours->id, $partner->id, AiAllianceStance::War))->toBeTrue()
        ->and(app(Enemies::class)->spared($this->currentUserId))->not->toContain($partnerLeader->id)
        ->and(collect(socialMindsMemories($partnerLeader->id))->where('event', 'war_declared')->where('actor', $this->currentUserId)->count())->toBe(1);
});

test('only a cunning leader hit by the partner, remembering a grievance over its gratitude, breaks a pact by surprise', function (float $cunning, float $grievance, bool $hit, bool $surprise): void {
    socialMindsGateway(['psychsim/social/partner' => ['accept_pact' => true, 'betray_now' => true, 'help_now' => false, 'p_loyal' => 0.2], 'fatima/social/exchange' => ['choice' => 'Ignore']]);
    [$ours, $partner, $partnerLeader] = socialMindsOutgrownPact($this->currentUserId, 1);
    socialMindsTraits($this->currentUserId, ['cunning' => $cunning]);
    socialMindsRecord($this->currentUserId, 'recalled', [$partnerLeader->id => ['grievance' => $grievance, 'gratitude' => 0.2]]);
    if ($hit) {
        AiRelationship::unguarded(fn () => AiRelationship::create(['player_id' => $this->currentUserId, 'other_player_id' => $partnerLeader->id, 'threat' => '0.5000', 'trust' => '-0.5000', 'revision' => 0]));
    }

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    $treaties = app(AllianceTreaties::class);
    expect($treaties->holds($ours->id, $partner->id, AiAllianceStance::War))->toBe($surprise)
        ->and($treaties->inNotice($ours->id, $partner->id))->toBe(!$surprise)
        ->and(collect(socialMindsMemories($partnerLeader->id))->where('event', 'betrayed')->where('actor', $this->currentUserId)->count())->toBe((int) $surprise)
        ->and(app(SocialMinds::class)->get($this->currentUserId, 'broken', 0))->toBe((int) $surprise);
})->with([
    'revenge with a reason' => [0.9, 1.0, true, true],
    'a cunning leader with no grievance' => [0.9, 0.0, true, false],
    'a cunning leader with an old grievance and no fresh hit' => [0.9, 1.0, false, false],
    'an honest leader with a grievance' => [0.3, 1.0, true, false],
]);

test('a FAtiMA urge to betray without a remembered grievance keeps the pact', function (): void {
    socialMindsGateway(['psychsim/social/partner' => ['accept_pact' => true, 'betray_now' => false, 'help_now' => false, 'p_loyal' => 0.9], 'fatima/social/exchange' => ['choice' => 'Betray'], 'agentos/social/recall' => ['actors' => []]]);
    // Seed 2 would betray this outgrown partner by the YAML rule; the minds own the decision now.
    [$ours, $partner] = socialMindsOutgrownPact($this->currentUserId, 2);

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect(app(AllianceTreaties::class)->holds($ours->id, $partner->id, AiAllianceStance::Nap))->toBeTrue();
});

test('a leader whose PsychSim distrusts the offerer refuses the pact', function (): void {
    socialMindsGateway(['psychsim/social/partner' => ['accept_pact' => false, 'betray_now' => false, 'help_now' => false, 'p_loyal' => 0.1], 'fatima/social/exchange' => ['choice' => 'Ignore']]);
    $ours = socialMindsLeader($this->currentUserId, 'OURS');
    $partnerLeader = User::factory()->create();
    $partner = socialMindsLeader($partnerLeader->id, 'PART');
    $enemy = app(AllianceService::class)->createAlliance(User::factory()->create()->id, 'ENEM', 'Enemy');
    app(AllianceTreaties::class)->openWar($ours->id, $enemy->id, 'test', $this->currentUserId);
    app(AllianceTreaties::class)->openWar($partner->id, $enemy->id, 'test', $partnerLeader->id);

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect(app(AllianceTreaties::class)->related($ours->id, $partner->id))->toBeFalse()
        ->and(collect(socialMindsMemories($this->currentUserId))->where('event', 'pact_refused')->where('actor', $partnerLeader->id)->count())->toBe(1);
    Http::assertSent(static fn (Request $request): bool => collect($request->data()['items'] ?? [])->contains('route', '/social/partner'));
});

/** An enabled AI account in the alliance, with its own mind (traits 0.5 but those given). */
function socialMindsMember(int $userId, int $allianceId, array $traits = []): void
{
    User::query()->whereKey($userId)->update(['alliance_id' => $allianceId]);
    AllianceMember::unguarded(fn () => AllianceMember::create(['alliance_id' => $allianceId, 'user_id' => $userId, 'rank_id' => null, 'joined_at' => now()]));
    AiProfile::query()->firstOrCreate(['player_id' => $userId], ['archetype' => AiArchetype::Fleeter, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 3, 'enabled' => true]);
    socialMindsTraits($userId, $traits);
}

function socialMindsTraits(int $userId, array $traits): void
{
    $minds = app(SocialMinds::class);
    $minds->put($userId, 'persona', ['archetype' => 'test', 'traits' => $traits + array_fill_keys(SocialPersona::TRAITS, 0.5)]);
    $minds->save();
}

function socialMindsRecord(int $userId, string $key, mixed $value): void
{
    $minds = app(SocialMinds::class);
    $minds->put($userId, $key, $value);
    $minds->save();
}

test('a secret pact that slips is announced to every AI alliance and remembered by every mind', function (): void {
    config(['ai.cognition.social.minds' => true]);
    Http::fake(['*' => Http::response('down', 503)]);
    $ours = socialMindsLeader($this->currentUserId, 'OURS');
    $partnerLeader = $this->createUser();
    $partner = socialMindsLeader($partnerLeader->id, 'PART');
    $member = $this->createUser();
    socialMindsMember($member->id, $ours->id, ['loyalty' => 0.0]);
    app(AllianceTreaties::class)->openPact($ours->id, $partner->id, 'test', $this->currentUserId);
    app(AdvanceAiAlliancePoliticsAction::class)->handle();
    foreach ([[$this->currentUserId, $partner->id], [$partnerLeader->id, $ours->id]] as [$holder, $other]) {
        socialMindsTraits($holder, ['loyalty' => 0.0]);
        socialMindsRecord($holder, 'secret', [$other]);
    }

    // The first day on which the seeded draw lets the secret slip: three members, each 0.002 likely to talk.
    $stay = (1.0 - 0.002) ** 3;
    $day = (int) floor(now()->getTimestamp() / 86400) + 1;
    while (app(SocialMinds::class)->draw("pact:{$ours->id}:{$partner->id}", $day) <= $stay) {
        $day++;
    }
    $this->travelTo(Date::createFromTimestamp($day * 86400 + 3600));

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    $leaderId = $this->currentUserId;
    $heard = static fn (int $userId): int => collect(socialMindsMemories($userId))->where('event', 'secret_pact_exposed')->where('actor', $leaderId)->count();
    expect($heard($member->id))->toBe(1)
        ->and($heard($partnerLeader->id))->toBe(1)
        ->and(AiSocialMind::query()->where('user_id', $this->currentUserId)->firstOrFail()->state['secret'])->toBe([])
        ->and(Message::query()->where('user_id', $member->id)->where('key', 'alliance_broadcast')->get()
            ->contains(static fn (Message $m): bool => str_contains((string) ($m->params['message'] ?? ''), 'secret pact')))->toBeTrue();
});

test('a vassal pays its tribute by Transport only with cargo ships', function (bool $cargo): void {
    config(['ai.cognition.social.minds' => true, 'ai.cognition.social.tribute' => true]);
    Http::fake(['*' => Http::response('down', 503)]);
    $vassal = socialMindsLeader($this->currentUserId, 'VASS');
    $lordPlanet = $this->createForeignPlanet();
    $lordId = (int) Planet::query()->whereKey($lordPlanet->getPlanetId())->value('user_id');
    $overlord = socialMindsLeader($lordId, 'LORD');
    $this->planetAddResources(new Resources(100_000, 100_000, 100_000));
    if ($cargo) {
        $this->planetAddUnit('large_cargo', 10);
    }
    app(AdvanceAiAlliancePoliticsAction::class)->handle();
    socialMindsRecord($this->currentUserId, 'overlord', ['alliance' => $overlord->id, 'since' => 0]);
    $this->travel(1)->days();

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    $tribute = FleetMission::query()->where('user_id', $this->currentUserId)->where('mission_type', TransportMission::getTypeId())->where('planet_id_to', $lordPlanet->getPlanetId());
    expect($tribute->count())->toBe($cargo ? 1 : 0)
        ->and(app(Enemies::class)->spared($this->currentUserId))->toContain($lordId)
        ->and(app(Enemies::class)->spared($lordId))->toContain($this->currentUserId);
    if ($cargo) {
        expect((float) $tribute->firstOrFail()->metal)->toBeGreaterThan(0.0);
    }
})->with(['with cargo ships' => [true], 'without cargo ships' => [false]]);

test('a coup succeeds only where the members respect hierarchy less than the line', function (float $authority, bool $won): void {
    config(['ai.cognition.social.minds' => true]);
    Http::fake(['*' => Http::response('down', 503)]);
    $club = socialMindsLeader($this->currentUserId, 'CLUB');
    $plotter = User::factory()->create();
    socialMindsMember($plotter->id, $club->id, ['ambition' => 0.9]);
    foreach ([User::factory()->create(), User::factory()->create()] as $member) {
        socialMindsMember($member->id, $club->id, ['authority' => $authority]);
        socialMindsPoints($member->id, 3);
    }
    socialMindsPoints($this->currentUserId, 10);
    socialMindsPoints($plotter->id, 20);

    app(AdvanceAiAlliancePoliticsAction::class)->handle();
    $founderId = $this->currentUserId;
    $standing = static fn (): int => (int) AiSocialMind::query()->where('user_id', $founderId)->firstOrFail()->state['standing']['leader'];
    expect($standing())->toBe($this->currentUserId, 'the founder leads, and a new leader settles in first');

    $this->travel(20)->days();
    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect($standing())->toBe($won ? $plotter->id : $this->currentUserId)
        ->and(collect(socialMindsMemories($this->currentUserId))->where('event', $won ? 'overthrown' : 'plot_foiled')->where('actor', $plotter->id)->count())->toBe(1);
})->with(['members who respect little hierarchy' => [0.2, true], 'members who respect it' => [0.9, false]]);

/** An AI club ten times the size of a human-led one, on a day the leaders eye the weak (a seventh day). */
function socialMindsHumanNeighbour(int $leaderId): array
{
    $ours = socialMindsLeader($leaderId, 'OURS');
    $human = User::factory()->create();
    $theirs = app(AllianceService::class)->createAlliance($human->id, 'HUMN', 'Human Club');
    socialMindsPoints($leaderId, 10_000);
    socialMindsPoints($human->id, 1_000);
    $day = (int) floor(now()->getTimestamp() / 86400) + 1;
    test()->travelTo(Date::createFromTimestamp(($day + (7 - $day % 7) % 7) * 86400 + 3600));

    return [$ours, $theirs, $human];
}

function socialMindsSaid(int $from, int $to, string $opening): bool
{
    return ChatMessage::query()->where('sender_id', $from)->where('recipient_id', $to)->where('message', 'like', $opening . '%')->exists();
}

test('a leader courts a human-led club it remembers well and the human answers the offer', function (): void {
    socialMindsGateway(['psychsim/social/partner' => ['accept_pact' => true, 'betray_now' => false, 'help_now' => false, 'p_loyal' => 0.9], 'fatima/social/exchange' => ['choice' => 'OfferPact'], 'cbrkit/social/similar' => ['cases' => []]]);
    [$ours, $theirs, $human] = socialMindsHumanNeighbour($this->currentUserId);
    socialMindsRecord($this->currentUserId, 'recalled', [$human->id => ['grievance' => 0.0, 'gratitude' => 1.0, 'broken' => 0.0, 'kept' => 0.0]]);

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect(socialMindsSaid($this->currentUserId, $human->id, 'We have no quarrel') || socialMindsSaid($this->currentUserId, $human->id, 'Our fleets stay off') || socialMindsSaid($this->currentUserId, $human->id, 'Good neighbours'))->toBeTrue()
        ->and(app(AllianceTreaties::class)->related($ours->id, $theirs->id))->toBeFalse('no engine answers for the human');
    Http::assertNotSent(static fn (Request $request): bool => collect($request->data()['items'] ?? [])->contains(static fn (array $item): bool => $item['route'] === '/social/partner'));

    expect(app(AdvanceAiAlliancePoliticsAction::class)->answerHumanLeader($human->id, $this->currentUserId, 'pact_accept'))->toBeTrue()
        ->and(app(AllianceTreaties::class)->holds($ours->id, $theirs->id, AiAllianceStance::Nap))->toBeTrue();
});

test('a leader threatens a weaker human-led club within the pressure cap and goes to war when the deadline passes', function (string $pressure, bool $pressed): void {
    socialMindsGateway(['fatima/social/exchange' => ['choice' => 'Threaten']]);
    config(['ai.cognition.social.human_pressure' => $pressure]);
    [$ours, $theirs, $human] = socialMindsHumanNeighbour($this->currentUserId);

    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    $treaties = app(AllianceTreaties::class);
    expect(ChatMessage::query()->where('sender_id', $this->currentUserId)->where('recipient_id', $human->id)->count())->toBe($pressed ? 1 : 0)
        ->and($treaties->related($ours->id, $theirs->id))->toBeFalse('the human has the deadline to answer');

    $this->travel(3)->days();
    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect($treaties->holds($ours->id, $theirs->id, AiAllianceStance::War))->toBe($pressed);
})->with(['medium pressure' => ['medium', true], 'low pressure' => ['low', false]]);

test('a human who comes to terms before the deadline is not attacked', function (): void {
    socialMindsGateway(['psychsim/social/partner' => ['accept_pact' => true, 'betray_now' => false, 'help_now' => false, 'p_loyal' => 0.9], 'fatima/social/exchange' => ['choice' => 'Threaten']]);
    [$ours, $theirs, $human] = socialMindsHumanNeighbour($this->currentUserId);
    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect(app(AdvanceAiAlliancePoliticsAction::class)->answerHumanLeader($human->id, $this->currentUserId, 'pact_offer'))->toBeTrue();
    $this->travel(3)->days();
    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect(app(AllianceTreaties::class)->holds($ours->id, $theirs->id, AiAllianceStance::Nap))->toBeTrue();
});

test('with living minds off a weaker human-led club is neither courted nor threatened', function (): void {
    Http::fake();
    [$ours, $theirs, $human] = socialMindsHumanNeighbour($this->currentUserId);

    app(AdvanceAiAlliancePoliticsAction::class)->handle();
    $this->travel(3)->days();
    app(AdvanceAiAlliancePoliticsAction::class)->handle();

    expect(ChatMessage::query()->where('sender_id', $this->currentUserId)->where('recipient_id', $human->id)->exists())->toBeFalse()
        ->and(app(AllianceTreaties::class)->related($ours->id, $theirs->id))->toBeFalse();
    Http::assertNothingSent();
});
