<?php

use Modules\AI\Actions\DecideAiErrandAction;
use Modules\AI\Contracts\ChoicePolicy;
use Modules\AI\Domain\Choice\ChoiceCandidate;
use Modules\AI\Domain\Choice\ChoicePoint;
use Modules\AI\Domain\Choice\ErrandPolicyModel;
use Modules\AI\Domain\Choice\TeacherChoicePolicy;
use Modules\AI\Domain\Choice\WideErrandEncoder;
use Modules\AI\Domain\Decision\DecisionEngine;
use Modules\AI\Domain\Decision\QueueableRaid;
use Modules\AI\Domain\Decision\RaidPlanner;
use Modules\AI\Domain\Login\LoginPlans;
use Modules\AI\Domain\Perception\PlayerPerceptionBuilder;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Enums\CharacterClass;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\DebrisField;
use OGame\Models\EspionageReport;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\MessageService;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// plan/rl 5 Nov "PHP v3 errand encoder": the errand model trained in the simulator (feature version 3, arms `wide late`)
// runs on the host. The fixture (tests/Fixtures/errand-v3-parity.json) carries the trainer's own torch scores for a small
// model of the same layout and for the trained bc-late-c2 model, and the order-row unit columns computed from the
// simulator's own tables (combat-u1.json, units-u1.json) at fixed research levels.

/** @return array<string, mixed> */
function errandV3Fixture(): array
{
    return json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/errand-v3-parity.json'), true);
}

/** @param array<string, mixed> $point */
function errandV3Point(array $point): ChoicePoint
{
    $candidates = array_map(static fn (array $features, bool $legal): ChoiceCandidate => app()->makeWith(ChoiceCandidate::class, ['objectId' => null, 'pass' => null, 'reason' => 'fixture', 'legal' => $legal, 'features' => $features]), $point['cands'], $point['legal']);

    return app()->makeWith(ChoicePoint::class, ['playerId' => 0, 'planetId' => 0, 'kind' => ChoicePoint::ERRAND, 'state' => $point['state'], 'candidates' => $candidates, 'teacherIndex' => 0, 'key' => 'fixture']);
}

/** @param array<string, mixed> $policy */
function errandV3ModelFile(array $policy): string
{
    $path = tempnam(sys_get_temp_dir(), 'errand-v3-') . '.json';
    file_put_contents($path, json_encode($policy));

    return $path;
}

/** The worst score gap and the argmax agreement of the PHP network on the fixture's points. */
function errandV3Gap(ErrandPolicyModel $model, array $points): array
{
    $gap = 0.0;
    $agree = 0;
    foreach ($points as $point) {
        $choice = errandV3Point($point);
        foreach ($model->scores($choice) as $row => $score) {
            $gap = max($gap, abs($score - $point['scores'][$row]));
        }
        $agree += $model->best($choice) === $point['best'] ? 1 : 0;
    }

    return [$gap, $agree];
}

function errandV3Account(int $playerId): AiProfile
{
    return AiProfile::create([
        'player_id' => $playerId,
        'archetype' => AiArchetype::Raider,
        'skill_band' => AiSkillBand::Standard,
        'random_seed' => 52_000 + $playerId,
        'enabled' => true,
    ]);
}

/** A defended neighbour the account holds a full report on, with a debris field over it. */
function errandV3Target(int $playerId): array
{
    $owner = User::factory()->create();
    $planet = Planet::factory()->create(['user_id' => $owner->id, 'galaxy' => 1, 'system' => 40, 'planet' => 7, 'metal' => 400_000, 'crystal' => 200_000, 'deuterium' => 50_000,
        'rocket_launcher' => 20, 'light_fighter' => 10, 'time_last_update' => now()->getTimestamp()]);
    $report = new EspionageReport();
    $report->planet_galaxy = 1;
    $report->planet_system = 40;
    $report->planet_position = 7;
    $report->planet_type = 1;
    $report->planet_user_id = $owner->id;
    $report->resources = ['metal' => 400_000, 'crystal' => 200_000, 'deuterium' => 50_000, 'energy' => 0];
    $report->debris = [];
    $report->buildings = [];
    $report->research = [];
    $report->ships = ['light_fighter' => 10];
    $report->defense = ['rocket_launcher' => 20];
    $report->player_info = ['player_name' => 'Target', 'player_status' => ''];
    $report->save();
    app(MessageService::class)->sendEspionageReportMessageToPlayer(app(PlayerServiceFactory::class)->make($playerId, true), $report->id);
    DebrisField::query()->create(['galaxy' => 1, 'system' => 40, 'planet' => 7, 'metal' => 60_000, 'crystal' => 40_000, 'deuterium' => 0]);

    return ['owner' => $owner, 'planet' => $planet, 'report' => $report];
}

function errandV3Encode(AiProfile $profile): array
{
    $perception = app(PlayerPerceptionBuilder::class)->build($profile->player_id, 30);
    $trace = app(DecisionEngine::class)->decide($profile, $perception, 'test:errand-v3');
    $player = app(PlayerServiceFactory::class)->make($profile->player_id, true);

    return ['perception' => $perception, 'trace' => $trace, 'point' => app(WideErrandEncoder::class)->point($profile->player_id, $player, $trace, 'test:errand-v3')];
}

test('the wide header is the simulator\'s, column for column', function (): void {
    $names = errandV3Fixture()['small_policy']['_names'];

    expect(WideErrandEncoder::STATE)->toBe($names['state'])
        ->and(WideErrandEncoder::candidateNames())->toBe($names['candidate'])
        ->and(count(WideErrandEncoder::candidateNames()))->toBe(47);
});

test('the PHP network scores every row as the trainer\'s own network does', function (): void {
    $fixture = errandV3Fixture();
    $model = ErrandPolicyModel::parse((string) json_encode($fixture['small_policy']));
    expect($model)->not->toBeNull();

    [$gap, $agree] = errandV3Gap($model, $fixture['small_points']);

    expect($gap)->toBeLessThan(1e-6)
        ->and($agree)->toBe(count($fixture['small_points']));
});

test('the trained late-game errand model loads and scores as the trainer does', function (): void {
    $fixture = errandV3Fixture();
    $path = base_path($fixture['trained_policy']);
    if (!is_file($path)) {
        $this->markTestSkipped('the trained model is not on this machine');
    }
    $model = ErrandPolicyModel::parse((string) file_get_contents($path));
    expect($model)->not->toBeNull();

    [$gap, $agree] = errandV3Gap($model, $fixture['trained_points']);

    expect($gap)->toBeLessThan(1e-4)
        ->and($agree)->toBe(count($fixture['trained_points']));
});

test('a narrow or unnamed errand model is not loaded, and the errand stays the engine\'s', function (): void {
    $policy = errandV3Fixture()['small_policy'];
    $narrow = ['_errand_feature_version' => 2] + $policy;
    $unnamed = array_diff_key($policy, ['_names' => true]);

    expect(ErrandPolicyModel::parse((string) json_encode($narrow)))->toBeNull()
        ->and(ErrandPolicyModel::parse((string) json_encode($unnamed)))->toBeNull();

    $profile = errandV3Account($this->currentUserId);
    $this->planetAddUnit('small_cargo', 5);
    $this->planetAddUnit('espionage_probe', 3);
    config(['ai.rl.errand_model' => errandV3ModelFile($narrow), 'ai.rl.record' => null]);
    app()->bind(ChoicePolicy::class, TeacherChoicePolicy::class);
    $perception = app(PlayerPerceptionBuilder::class)->build($profile->player_id, 30);
    $engine = app(DecisionEngine::class)->decide($profile, $perception, 'test:errand-v3');

    expect(app(DecideAiErrandAction::class)->handle($profile, $perception, $engine, 'test:errand-v3'))->toBe($engine);
});

test('an order row reads the unit from the host as the simulator reads it from its tables', function (): void {
    $fixture = errandV3Fixture();
    // The tables were exported for a Collector (its cargo and speed bonus are in them), so this account is one too.
    User::query()->whereKey($this->currentUserId)->update(['character_class' => CharacterClass::COLLECTOR->value]);
    foreach ($fixture['order_research'] as $id => $level) {
        $this->playerSetResearchLevel(ObjectService::getObjectById((int) $id)->machine_name, $level);
    }
    $player = app(PlayerServiceFactory::class)->make($this->currentUserId, true);
    $names = WideErrandEncoder::candidateNames();

    $gap = 0.0;
    foreach (ObjectService::getUnitObjects() as $unit) {
        $expected = $fixture['order_columns'][$unit->machine_name] ?? null;
        if ($expected === null) {
            continue;
        }
        $row = app(WideErrandEncoder::class)->orderRow($unit, $player);
        foreach ($expected as $column => $value) {
            $gap = max($gap, abs($row[array_search($column, $names, true)] - $value));
        }
        expect($row[array_search('order', $names, true)])->toBe(1.0)
            ->and($row[array_search('order_share', $names, true)])->toBe(1.0);
    }

    expect($gap)->toBeLessThan(1e-9);
});

test('a login is encoded in the wide layout from the engine\'s own candidates, waiting first', function (): void {
    $profile = errandV3Account($this->currentUserId);
    $this->planetSetObjectLevel('shipyard', 6);
    $this->playerSetResearchLevel('combustion_drive', 6);
    $this->planetAddUnit('small_cargo', 40);
    $this->planetAddUnit('light_fighter', 200);
    $this->planetAddUnit('espionage_probe', 5);
    $this->planetAddUnit('recycler', 4);
    $this->planetAddResources(new Resources(2_000_000, 1_000_000, 500_000));
    errandV3Target($this->currentUserId);

    ['trace' => $trace, 'point' => $point] = errandV3Encode($profile);
    $kinds = WideErrandEncoder::KINDS;
    $names = WideErrandEncoder::candidateNames();

    expect($point->state)->toHaveCount(17)
        ->and(count($point->candidates))->toBeLessThanOrEqual(WideErrandEncoder::MAX_ROWS)
        ->and($point->candidates[0]->objectId)->toBeNull()
        ->and($point->candidates[0]->reason)->toBe('wait');
    foreach ($point->candidates as $row => $candidate) {
        $hot = array_sum(array_map(static fn (string $kind): float => $candidate->features[array_search($kind, $names, true)], $kinds));
        expect($candidate->features)->toHaveCount(47)
            ->and($hot)->toBe(1.0)
            ->and($candidate->features[array_search($candidate->reason, $names, true)])->toBe(1.0);
        if ($row > 0) {
            expect($trace->candidates[$candidate->objectId] ?? null)->not->toBeNull();
        }
    }
    $raids = array_values(array_filter($point->candidates, static fn (ChoiceCandidate $candidate): bool => $candidate->reason === 'raid'));
    if ($raids !== []) {
        $raid = $raids[0]->features;
        expect($raid[array_search('report_age_hours', $names, true)])->toBeLessThan(1.0)
            ->and($raid[array_search('target_stock', $names, true)])->toBeGreaterThan(13.0)
            ->and($raid[array_search('field', $names, true)])->toBeGreaterThan(0.0)
            ->and($raid[array_search('speed', $names, true)])->toBe(1.0);
    }
});

test('a model picks only a row it was offered, and the login runs that very candidate', function (): void {
    $profile = errandV3Account($this->currentUserId);
    $this->planetSetObjectLevel('shipyard', 6);
    $this->playerSetResearchLevel('combustion_drive', 6);
    $this->planetAddUnit('small_cargo', 40);
    $this->planetAddUnit('light_fighter', 200);
    $this->planetAddUnit('espionage_probe', 5);
    $this->planetAddResources(new Resources(2_000_000, 1_000_000, 500_000));
    errandV3Target($this->currentUserId);
    config(['ai.rl.errand_model' => errandV3ModelFile(errandV3Fixture()['small_policy']), 'ai.rl.learner_share' => 1.0]);

    ['perception' => $perception, 'trace' => $engine, 'point' => $point] = errandV3Encode($profile);
    $model = ErrandPolicyModel::configured();
    $chosen = $model->best($point);
    $trace = app(DecideAiErrandAction::class)->handle($profile, $perception, $engine, 'test:errand-v3');

    $expected = $point->candidates[$chosen]->objectId;
    expect(in_array($trace->selected, $engine->candidates, true))->toBeTrue();
    if ($chosen === $point->teacherIndex) {
        expect($trace)->toBe($engine);

        return;
    }
    expect($trace->selected)->toBe($expected === null ? $trace->selected : $engine->candidates[$expected]);
});

// The encoder reuses the plans the engine just read (LoginPlans) instead of asking each planner again; the columns are the
// ones a fresh ask gives, row for row.
test('the errand reuses the engine\'s plans and encodes exactly what fresh plans encode', function (): void {
    $profile = errandV3Account($this->currentUserId);
    $this->planetSetObjectLevel('shipyard', 6);
    $this->playerSetResearchLevel('combustion_drive', 6);
    $this->planetAddUnit('small_cargo', 40);
    $this->planetAddUnit('light_fighter', 200);
    $this->planetAddUnit('espionage_probe', 5);
    $this->planetAddUnit('recycler', 4);
    $this->planetAddResources(new Resources(2_000_000, 1_000_000, 500_000));
    ['report' => $report] = errandV3Target($this->currentUserId);

    ['trace' => $trace, 'point' => $reused] = errandV3Encode($profile);
    $player = app(PlayerServiceFactory::class)->make($profile->player_id, true);
    $kept = app(LoginPlans::class)->reuse(RaidPlanner::class, [$profile->player_id, $report->id], static fn (): string => 'asked again');
    app(LoginPlans::class)->reset();
    $fresh = app(WideErrandEncoder::class)->point($profile->player_id, $player, $trace, 'test:errand-v3');

    $rows = static fn (ChoicePoint $point): array => array_map(static fn (ChoiceCandidate $candidate): array => [$candidate->objectId, $candidate->reason, $candidate->features], $point->candidates);
    expect($kept)->toBeInstanceOf(QueueableRaid::class)
        ->and(array_column($rows($reused), 1))->toContain('raid')
        ->and($rows($reused))->toBe($rows($fresh))
        ->and($reused->state)->toBe($fresh->state)
        ->and($reused->teacherIndex)->toBe($fresh->teacherIndex);
});

// The owner's rule: an errand reads only what the account saw. The target's live research, fleet and stock change after
// the report; nothing the account encodes may move.
test('the encoded errand does not read the target\'s unreported state', function (): void {
    $profile = errandV3Account($this->currentUserId);
    $this->planetAddUnit('small_cargo', 40);
    $this->planetAddUnit('light_fighter', 200);
    $this->planetAddUnit('espionage_probe', 5);
    $this->planetAddResources(new Resources(2_000_000, 1_000_000, 500_000));
    ['owner' => $owner, 'planet' => $planet] = errandV3Target($this->currentUserId);
    $this->travelTo(now()->startOfSecond());

    $before = errandV3Encode($profile)['point'];
    $planet->forceFill(['light_fighter' => 900, 'rocket_launcher' => 600, 'metal' => 5, 'crystal' => 5, 'deuterium' => 5])->save();
    $hidden = app(PlayerServiceFactory::class)->make($owner->id, true);
    foreach (['weapon_technology', 'shielding_technology', 'armor_technology'] as $research) {
        $hidden->setResearchLevel($research, 12);
    }
    $after = errandV3Encode($profile)['point'];

    $rows = static fn (ChoicePoint $point): array => array_map(static fn (ChoiceCandidate $candidate): array => [$candidate->reason, $candidate->features], $point->candidates);
    expect($rows($after))->toBe($rows($before))
        ->and($after->state)->toBe($before->state);
});
