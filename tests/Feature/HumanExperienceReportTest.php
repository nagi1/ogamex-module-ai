<?php

use Illuminate\Support\Facades\Artisan;
use Modules\AI\Enums\AiAllianceStance;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiAllianceRelation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiSocialMind;
use OGame\Models\BattleReport;
use OGame\Models\ChatMessage;
use OGame\Models\FleetMission;
use OGame\Models\Highscore;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// The human-experience gauge (SOCIAL-WORLD-DRAFT.md part 17 item 2, validation.md "Measure humans separately"):
// meaningful interactions humans had with AI accounts, the pressure AI accounts put on them, and AI accounts never
// counted as humans.

/** @return array<string, mixed> */
function humanExperienceJson(): array
{
    Artisan::call('ai:human-experience', ['--days' => 7, '--json' => true]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

function humanExperienceAi(User $user): void
{
    AiProfile::create(['player_id' => $user->id, 'archetype' => AiArchetype::Fleeter, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 1, 'enabled' => true]);
}

function humanExperienceBattle(int $attacker, int $defender, int $defenderLoss = 0, int $loot = 0): void
{
    BattleReport::unguarded(fn (): BattleReport => BattleReport::create([
        'planet_galaxy' => 1, 'planet_system' => 1, 'planet_position' => 1, 'planet_user_id' => $defender,
        'attacker' => ['player_id' => $attacker, 'resource_loss' => 0],
        'defender' => ['player_id' => $defender, 'resource_loss' => $defenderLoss],
        'loot' => ['metal' => $loot, 'crystal' => 0, 'deuterium' => 0],
        'created_at' => now()->subHour(),
    ]));
}

function humanExperienceFleet(int $sender, int $target, int $type): void
{
    FleetMission::unguarded(fn (): FleetMission => FleetMission::create([
        'user_id' => $sender,
        'planet_id_from' => Planet::query()->where('user_id', $sender)->value('id'),
        'planet_id_to' => Planet::query()->where('user_id', $target)->value('id'),
        'galaxy_to' => 1, 'system_to' => 1, 'position_to' => 1, 'type_to' => 1,
        'mission_type' => $type,
        'time_departure' => now()->subHours(2)->getTimestamp(),
        'time_arrival' => now()->subHour()->getTimestamp(),
        'processed' => 1,
    ]));
}

function humanExperienceChat(int $from, int $to): void
{
    ChatMessage::unguarded(fn (): ChatMessage => ChatMessage::create(['sender_id' => $from, 'recipient_id' => $to, 'message' => 'Hello there']));
    test()->travel(1)->minutes();
}

test('the gauge counts interactions and pressure for humans only', function (): void {
    $human = User::query()->whereKey($this->currentUserId)->firstOrFail();
    $other = $this->createUser();
    $raider = $this->createUser();
    $neighbour = $this->createUser();
    humanExperienceAi($raider);
    humanExperienceAi($neighbour);

    $ours = app(AllianceService::class)->createAlliance($human->id, 'HUMN', 'Humans');
    $theirs = app(AllianceService::class)->createAlliance($raider->id, 'BOTS', 'Bots');
    $third = app(AllianceService::class)->createAlliance($neighbour->id, 'MORE', 'More Bots');
    Highscore::query()->updateOrCreate(['player_id' => $human->id], ['general' => 100]);
    config(['ai.cognition.social.human_pressure' => 'medium']);

    $before = humanExperienceJson();

    // Battles: the human defends against an AI raid and loses most of what they had, the other human raids an AI, and
    // two AI accounts fight each other (not a human interaction).
    humanExperienceBattle($raider->id, $human->id, defenderLoss: 300_000, loot: 200_000);
    humanExperienceBattle($other->id, $neighbour->id);
    humanExperienceBattle($raider->id, $neighbour->id, defenderLoss: 50_000);

    // Diplomacy: a pact between the human club and an AI club counts, one between two AI clubs does not; an AI club
    // declares war on the human club.
    foreach ([[$ours->id, $theirs->id, AiAllianceStance::Nap], [$theirs->id, $third->id, AiAllianceStance::Nap], [$third->id, $ours->id, AiAllianceStance::War]] as [$one, $two, $stance]) {
        AiAllianceRelation::create(['alliance_id' => $one, 'other_alliance_id' => $two, 'stance' => $stance, 'cause' => 'test', 'started_at' => now()]);
    }

    // Fleets: three AI attacks and a probe on humans, an AI attack on an AI, and help a human sent an AI.
    humanExperienceFleet($raider->id, $human->id, 1);
    humanExperienceFleet($raider->id, $human->id, 1);
    humanExperienceFleet($neighbour->id, $other->id, 1);
    humanExperienceFleet($raider->id, $human->id, 6);
    humanExperienceFleet($raider->id, $neighbour->id, 1);
    humanExperienceFleet($other->id, $neighbour->id, 5);

    // Chat: the human writes and the AI answers; an AI greets the other human who never answers; two AI accounts talk.
    humanExperienceChat($human->id, $raider->id);
    humanExperienceChat($raider->id, $human->id);
    humanExperienceChat($neighbour->id, $other->id);
    humanExperienceChat($raider->id, $neighbour->id);

    // The AI leader threatened a human-led club this week, which is its whole medium cap.
    AiSocialMind::create(['user_id' => $raider->id, 'state' => ['pressed' => [now()->subDay()->getTimestamp() / 3600.0]]]);
    User::query()->whereKey($human->id)->update(['time' => (string) now()->getTimestamp()]);

    $after = humanExperienceJson();
    $delta = static fn (string $section, string $key): int|float => $after[$section][$key] - $before[$section][$key];

    expect($after['humans'])->toBe($before['humans'])
        ->and($after['humans'])->toBe(User::query()->count() - AiProfile::query()->count())
        ->and($delta('interactions', 'raids_on_ai'))->toBe(1)
        ->and($delta('interactions', 'defenses_against_ai'))->toBe(1)
        ->and($delta('interactions', 'pacts_with_ai_alliances'))->toBe(1)
        ->and($delta('interactions', 'assistance'))->toBe(1)
        ->and($delta('interactions', 'two_way_conversations'))->toBe(1)
        ->and($delta('interactions', 'total'))->toBe(5)
        ->and($delta('interactions', 'humans_with_any'))->toBe(2)
        ->and($delta('opportunities', 'ai_attacks_launched'))->toBe(3)
        ->and($delta('opportunities', 'conversation_contacts'))->toBe(2)
        ->and($delta('pressure', 'hostile_missions'))->toBe(3)
        ->and($delta('pressure', 'threats_to_human_leaders'))->toBe(1)
        ->and($delta('pressure', 'wars_on_human_alliances'))->toBe(1)
        ->and($delta('pressure', 'ai_leaders_at_cap'))->toBe(1)
        ->and($after['pressure']['cap_per_leader_week'])->toBe(1)
        ->and($delta('resilience', 'hit_hard'))->toBe(1)
        ->and($delta('resilience', 'active_after'))->toBe(1);
});

test('the table rendering names every section', function (): void {
    $this->artisan('ai:human-experience', ['--days' => 3])
        ->expectsOutputToContain('Human experience: last 3 day(s)')
        ->expectsOutputToContain('per_human_week')
        ->expectsOutputToContain('ai_leaders_at_cap')
        ->assertSuccessful();
});
