<?php

use Modules\AI\Contracts\AllianceTreaties;
use Modules\AI\Domain\Decision\QueueableMissile;
use Modules\AI\Domain\Decision\QueueableMissilePlanner;
use Modules\AI\Domain\Decision\QueueableSpy;
use Modules\AI\Domain\Decision\QueueableSpyPlanner;
use Modules\AI\Domain\Decision\RaidPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Tests\Support\RaidStory;
use Modules\AI\Tests\Support\Situation;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\MoonDestructionMission;
use OGame\Models\Alliance;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\AllianceService;
use OGame\Services\PlanetService;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';
require_once __DIR__ . '/../../Support/RaidStory.php';

uses(IsolatedAccountTestCase::class);

// A pact binds every member of both clubs: no attack, joint attack, missile, moon destruction or probe flies at a
// partner while the pact holds, notice window included. Every hostile planner reads the one list of players the account
// spares (Enemies::spared); the pact ends only on the politics path, and then the partner is fair game again.

/**
 * The account leads one club, a farm beside its home belongs to another; $pact signs a pact between the clubs.
 *
 * @return array{0: Situation, 1: PlanetService, 2: Alliance, 3: Alliance}
 */
function pactStory(object $test, bool $pact, AiArchetype $archetype = AiArchetype::Raider): array
{
    $userId = (fn (): int => $this->currentUserId)->call($test);
    $situation = Situation::of($test)
        ->archetype($archetype)
        ->research('combustion_drive', 8)
        ->research('espionage_technology', 4)
        ->ships('espionage_probe', 30)
        ->ships('large_cargo', 60)
        ->ships('light_fighter', 60)
        ->resources(500_000, 500_000, 2_000_000)
        ->colony();
    $farm = RaidStory::farm($test);
    $home = Planet::query()->findOrFail((fn (): int => $this->currentPlanetId)->call($test));
    $taken = Planet::query()->where('galaxy', $home->galaxy)->where('system', $home->system)->pluck('planet')->all();
    $free = collect(range(4, 12))->first(static fn (int $position): bool => !in_array($position, $taken, true));
    Planet::query()->whereKey($farm->getPlanetId())->update(['galaxy' => $home->galaxy, 'system' => $home->system, 'planet' => $free]);
    $farm = app(PlanetServiceFactory::class)->make($farm->getPlanetId(), true);

    $ours = app(AllianceService::class)->createAlliance($userId, 'OURS', 'Ours');
    $theirs = app(AllianceService::class)->createAlliance($farm->getPlayer()->getId(), 'THEM', 'Them');
    if ($pact) {
        app(AllianceTreaties::class)->openPact($ours->id, $theirs->id, 'test', $userId);
    }

    return [$situation, $farm, $ours, $theirs];
}

function pactScouted(int $playerId, PlanetService $farm): bool
{
    $plan = app(QueueableSpyPlanner::class)->plan($playerId);
    $at = $farm->getPlanetCoordinates();

    return $plan instanceof QueueableSpy && [$plan->targetGalaxy, $plan->targetSystem, $plan->targetPosition] === [$at->galaxy, $at->system, $at->position];
}

test('a pact partner\'s farm is not raided; without the pact it is', function (bool $pact): void {
    [$situation, $farm] = pactStory($this, $pact);
    RaidStory::report($this, $farm);

    $pact ? $situation->session()->expectNoMission('Attack') : $situation->session()->expectMission('Attack');
})->with(['pact' => [true], 'no pact' => [false]]);

test('a member who joins the partner club is spared too', function (): void {
    [$situation, , , $theirs] = pactStory($this, true);
    $member = RaidStory::farm($this);
    User::query()->whereKey($member->getPlayer()->getId())->update(['alliance_id' => $theirs->id]);
    RaidStory::report($this, $member);

    $situation->session()->expectNoMission('Attack');
});

test('a pact partner is not scouted; without the pact it is', function (bool $pact): void {
    [, $farm] = pactStory($this, $pact);

    expect(pactScouted($this->currentUserId, $farm))->toBe(!$pact);
})->with(['pact' => [true], 'no pact' => [false]]);

test('no missile flies at a pact partner\'s wall; without the pact it does', function (bool $pact): void {
    [, $farm] = pactStory($this, $pact, AiArchetype::Turtle);
    $this->playerSetResearchLevel('impulse_drive', 6);
    $this->planetSetObjectLevel('missile_silo', 4);
    $this->planetAddUnit(QueueableMissilePlanner::MISSILE, 10);
    $farm->addUnit('rocket_launcher', 50);
    RaidStory::report($this, $farm);

    expect(app(QueueableMissilePlanner::class)->plan($this->currentUserId) instanceof QueueableMissile)->toBe(!$pact);
})->with(['pact' => [true], 'no pact' => [false]]);

test('no moon destruction flies at a pact partner\'s moon, grudge or not; without the pact it does', function (bool $pact): void {
    [, $farm] = pactStory($this, $pact);
    $this->playerSetResearchLevel('astrophysics', 23);
    $this->planetAddUnit('deathstar', 2);
    $this->planetAddUnit('cruiser', 40);
    $moon = app(PlanetServiceFactory::class)->createMoonForPlanet($farm, 2_000_000, 20);
    AiRelationship::unguarded(fn () => AiRelationship::create(['player_id' => $this->currentUserId, 'other_player_id' => $farm->getPlayer()->getId(), 'threat' => '1.0000', 'trust' => '-1.0000', 'revision' => 0]));
    $report = RaidStory::report($this, $moon);

    $plan = app(RaidPlanner::class)->plan($this->currentUserId, $report->id);

    expect($plan?->missionType === MoonDestructionMission::getTypeId())->toBe(!$pact);
})->with(['pact' => [true], 'no pact' => [false]]);

test('a pact in its notice window still binds; once the notice runs out the partner is raided', function (): void {
    [$situation, $farm, $ours, $theirs] = pactStory($this, true);
    $treaties = app(AllianceTreaties::class);
    $treaties->cancelPact($ours->id, $theirs->id, now()->addHours(48));
    RaidStory::report($this, $farm);

    $situation->session()->expectNoMission('Attack');

    $this->travel(49)->hours();
    $treaties->endNotices();
    RaidStory::report($this, $farm);
    $situation->session()->expectMission('Attack');
});

// No cheating (rule 10): the pact is the public record; the partner's hidden stock and fleet change nothing.
test('a pact partner\'s hidden riches do not tempt the raid or the probe', function (): void {
    [$situation, $farm] = pactStory($this, true);
    RaidStory::report($this, $farm);
    $farm->addResources(new OGame\Models\Resources(50_000_000, 50_000_000, 50_000_000));
    $farm->addUnit('battle_ship', 5);

    expect(pactScouted($this->currentUserId, $farm))->toBeFalse();
    $situation->session()->expectNoMission('Attack');
});

// The race the live run showed: the raid or the volley was decided a moment before the pact was signed (or the target
// joined the partner club), and the launch came after. The launch reads the same spared list and does not fly.
test('a raid decided before the pact does not launch after it', function (): void {
    [, $farm, $ours, $theirs] = pactStory($this, false);
    $plan = app(RaidPlanner::class)->plan($this->currentUserId, RaidStory::report($this, $farm)->id);
    expect($plan)->not->toBeNull();

    app(AllianceTreaties::class)->openPact($ours->id, $theirs->id, 'test', $this->currentUserId);
    $result = app(Modules\AI\Actions\QueueAiRaidAction::class)->handle($this->currentUserId, $plan->originPlanetId, $plan->targetGalaxy, $plan->targetSystem, $plan->targetPosition, $plan->targetType, $plan->launchUnits, $plan->missionType);

    expect($result->reason)->toBe(Modules\AI\Enums\AiQueueActionReason::TargetSparedAtDispatch->value);
});

test('a volley decided before the pact does not launch after it', function (): void {
    [, $farm, $ours, $theirs] = pactStory($this, false, AiArchetype::Turtle);
    $this->playerSetResearchLevel('impulse_drive', 6);
    $this->planetSetObjectLevel('missile_silo', 4);
    $this->planetAddUnit(QueueableMissilePlanner::MISSILE, 10);
    $farm->addUnit('rocket_launcher', 50);
    RaidStory::report($this, $farm);
    $plan = app(QueueableMissilePlanner::class)->plan($this->currentUserId);
    expect($plan)->toBeInstanceOf(QueueableMissile::class);

    app(AllianceTreaties::class)->openPact($ours->id, $theirs->id, 'test', $this->currentUserId);
    $result = app(Modules\AI\Actions\QueueAiMissileAction::class)->handle($this->currentUserId, $plan->originPlanetId, $plan->targetGalaxy, $plan->targetSystem, $plan->targetPosition, $plan->targetType, $plan->missiles);

    expect($result->reason)->toBe(Modules\AI\Enums\AiQueueActionReason::TargetSparedAtDispatch->value);
});

test('a probe decided before the pact does not launch after it', function (): void {
    [, $farm, $ours, $theirs] = pactStory($this, false);
    $plan = app(QueueableSpyPlanner::class)->plan($this->currentUserId);
    expect(pactScouted($this->currentUserId, $farm))->toBeTrue();

    app(AllianceTreaties::class)->openPact($ours->id, $theirs->id, 'test', $this->currentUserId);
    $result = app(Modules\AI\Actions\QueueAiSpyAction::class)->handle($this->currentUserId, $plan->planetId, $plan->targetGalaxy, $plan->targetSystem, $plan->targetPosition, $plan->targetType, $plan->probeCount);

    expect($result->reason)->toBe(Modules\AI\Enums\AiQueueActionReason::TargetSparedAtDispatch->value);
});

test('a moon destruction decided before the pact does not launch after it', function (): void {
    [, $farm, $ours, $theirs] = pactStory($this, false);
    $this->playerSetResearchLevel('astrophysics', 23);
    $this->planetAddUnit('deathstar', 2);
    $this->planetAddUnit('cruiser', 40);
    $moon = app(PlanetServiceFactory::class)->createMoonForPlanet($farm, 2_000_000, 20);
    AiRelationship::unguarded(fn () => AiRelationship::create(['player_id' => $this->currentUserId, 'other_player_id' => $farm->getPlayer()->getId(), 'threat' => '1.0000', 'trust' => '-1.0000', 'revision' => 0]));
    $plan = app(RaidPlanner::class)->plan($this->currentUserId, RaidStory::report($this, $moon)->id);
    expect($plan?->missionType)->toBe(MoonDestructionMission::getTypeId());

    app(AllianceTreaties::class)->openPact($ours->id, $theirs->id, 'test', $this->currentUserId);
    $result = app(Modules\AI\Actions\QueueAiRaidAction::class)->handle($this->currentUserId, $plan->originPlanetId, $plan->targetGalaxy, $plan->targetSystem, $plan->targetPosition, $plan->targetType, $plan->launchUnits, $plan->missionType);

    expect($result->reason)->toBe(Modules\AI\Enums\AiQueueActionReason::TargetSparedAtDispatch->value);
});
