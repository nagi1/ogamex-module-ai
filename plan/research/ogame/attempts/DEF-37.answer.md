### EDIT: app/Domain/Decision/DefenseCompositionPlanner.php
<<<<<<< SEARCH
        $key = $this->afterStopRule($doctrines, $defenceObjects, $this->doctrineKey($player), $planet);
=======
        $key = $this->doctrineInForce($doctrines, $defenceObjects, $this->doctrineKey($player), $planet);
>>>>>>> REPLACE

### EDIT: app/Domain/Decision/DefenseCompositionPlanner.php
<<<<<<< SEARCH
    /**
     * The doctrine the wall moves to once it outgrows the one it started in.
     *
     * The handover is stated in the doctrine file ("swap to X once the wall passes N ..."), so the
     * target and the threshold are read from there rather than repeated as constants here.
     *
     * ponytail: the sentence is matched by shape, so rewording it silently disables the handover.
     * Upgrade path: give the file a structured `stop_rule: {doctrine, anchor_at}` mapping when a
     * second doctrine needs a handover.
     *
     * @param  array<string, array{anchor: array{unit: string, count: int}, ratio: array<string, int>, stop_rule?: string}>  $doctrines
     * @param  array<string, UnitObject>  $defenceObjects
     */
    private function afterStopRule(array $doctrines, array $defenceObjects, string $key, PlanetService $planet): string
    {
        $rule = $doctrines[$key]['stop_rule'] ?? null;
        if (!is_string($rule) || preg_match('/swap to ([a-z_]+) once the wall passes (\d+)/', $rule, $matches) !== 1) {
            return $key;
        }

        $anchor = $this->resolveUnit($doctrines[$key]['anchor']['unit'], $defenceObjects);
        $wall = $planet->getDefenseUnits()->getAmountByMachineName($anchor->machine_name);

        return $wall > (int) $matches[2] ? $matches[1] : $key;
    }
=======
    /**
     * The doctrine the account's wall is built to on this planet: the one its belief names, or the
     * one that doctrine's stop rule hands the wall over to.
     */
    public function doctrineFor(PlayerService $player, PlanetService $planet): string
    {
        return $this->doctrineInForce(
            $this->doctrines(),
            $this->defenceObjectsByMachineName(),
            $this->doctrineKey($player),
            $planet
        );
    }

    /**
     * The doctrine in force once any stop rule has been applied.
     *
     * The hand-over is structured data — the doctrine it moves to and the point it moves at, as a
     * unit and a count — and the sentence a player sourced it from is kept beside it as a quote
     * only. Nothing reads that sentence, so no wording of it can change what an account builds; a
     * rule that is present but not stated that way is an error rather than a silent fallback.
     *
     * @param  array<string, array{anchor: array{unit: string, count: int}, ratio: array<string, int>, stop_rule?: array{doctrine: string, after: array{unit: string, count: int}, quote?: string}}>  $doctrines
     * @param  array<string, UnitObject>  $defenceObjects
     */
    private function doctrineInForce(array $doctrines, array $defenceObjects, string $key, PlanetService $planet): string
    {
        $rule = $doctrines[$key]['stop_rule'] ?? null;
        if ($rule === null) {
            return $key;
        }

        if (!is_array($rule) || !isset($rule['doctrine'], $rule['after']['unit'], $rule['after']['count'])) {
            throw new RuntimeException(sprintf(
                'defence-doctrines: the stop_rule of "%s" must state a doctrine and an after unit and count.',
                $key
            ));
        }

        $unit = $this->resolveUnit($rule['after']['unit'], $defenceObjects);
        $wall = $planet->getDefenseUnits()->getAmountByMachineName($unit->machine_name);

        return $wall > (int) $rule['after']['count'] ? $rule['doctrine'] : $key;
    }
>>>>>>> REPLACE

### EDIT: app/Domain/Decision/DefenseCompositionPlanner.php
<<<<<<< SEARCH
     * @return array<string, array{anchor: array{unit: string, count: int}, ratio: array<string, int>, stop_rule?: string}>
=======
     * @return array<string, array{anchor: array{unit: string, count: int}, ratio: array<string, int>, stop_rule?: array{doctrine: string, after: array{unit: string, count: int}, quote?: string}}>
>>>>>>> REPLACE

### EDIT: app/Domain/Decision/DefenseCompositionPlanner.php
<<<<<<< SEARCH
        /** @var array<string, array{anchor: array{unit: string, count: int}, ratio: array<string, int>, stop_rule?: string}> $doctrines */
=======
        /** @var array<string, array{anchor: array{unit: string, count: int}, ratio: array<string, int>, stop_rule?: array{doctrine: string, after: array{unit: string, count: int}, quote?: string}}> $doctrines */
>>>>>>> REPLACE

### FILE: resources/behavior/defence-doctrines.yaml
```yaml
# Defence doctrines: the shape of the wall an account builds.
#
# `anchor` is the unit the doctrine counts in and the wall an account builds with nothing at risk;
# `ratio` is how many of each other unit are held per `anchor.count` anchors. Prices come from the
# host, so only shapes live here.
#
# `stop_rule` hands the wall over to `doctrine` once the planet holds more than `after.count` of
# `after.unit`. `quote` is the sourced sentence the rule was read from: it is documentation only
# and is never parsed, so wording it differently cannot change what an account builds.

doctrines:
    balanced:
        anchor:
            unit: rocket_launcher
            count: 100
        ratio:
            light_laser: 60
            heavy_laser: 15
            gauss_cannon: 5
            ion_cannon: 5
            plasma_turret: 1
            small_shield_dome: 1
            large_shield_dome: 1
            anti_ballistic_missile: 5
        stop_rule:
            doctrine: big_gun_heavy
            after:
                unit: light_laser
                count: 200
            quote: 'swap to big_gun_heavy once the wall passes 200 Light Lasers'

    big_gun_heavy:
        anchor:
            unit: gauss_cannon
            count: 100
        ratio:
            rocket_launcher: 250
            light_laser: 150
            heavy_laser: 40
            ion_cannon: 20
            plasma_turret: 4
            small_shield_dome: 1
            large_shield_dome: 1
            anti_ballistic_missile: 10
```

### FILE: resources/scenarios/defense-doctrine-handover.json
```json
{
    "name": "defense-doctrine-handover",
    "situation": "A balanced-doctrine account has built its light-laser wall past the point its doctrine file hands the wall over at.",
    "persona": {
        "archetype": "defender",
        "skill_band": "veteran"
    },
    "input": {
        "doctrine": "balanced",
        "defense": {
            "rocket_launcher": 400,
            "light_laser": 201
        }
    },
    "decision_key": "defense_composition",
    "expect": {
        "action": "queue_defense",
        "doctrine": "big_gun_heavy"
    },
    "status": "unverified",
    "checklist": [
        {
            "topic": "handover-point",
            "question": "Does the wall move to the hand-over doctrine only once it is past the rule's count, or at the count itself?",
            "status": "unverified"
        },
        {
            "topic": "handover-unit",
            "question": "Is the wall that triggers the hand-over counted in the rule's own unit or in the starting doctrine's anchor unit?",
            "status": "unverified"
        },
        {
            "topic": "quote-is-inert",
            "question": "Does rewording the sourced sentence beside the rule change which doctrine gets built?",
            "status": "unverified"
        }
    ]
}
```

### FILE: resources/scenarios/defense-doctrine-hold.json
```json
{
    "name": "defense-doctrine-hold",
    "situation": "A balanced-doctrine account holds a light-laser wall exactly at the point its doctrine file hands the wall over at, and one with no wall at all.",
    "persona": {
        "archetype": "defender",
        "skill_band": "veteran"
    },
    "input": {
        "doctrine": "balanced",
        "defense": {
            "rocket_launcher": 400,
            "light_laser": 200
        }
    },
    "decision_key": "defense_composition",
    "expect": {
        "action": "queue_defense",
        "doctrine": "balanced"
    },
    "status": "unverified",
    "checklist": [
        {
            "topic": "boundary-inclusive",
            "question": "Does a wall sitting exactly on the rule's count already build the hand-over doctrine?",
            "status": "unverified"
        },
        {
            "topic": "empty-wall",
            "question": "Which doctrine does an account with no defence at all build to?",
            "status": "unverified"
        },
        {
            "topic": "rule-source",
            "question": "Is the hand-over point taken from the rule's own unit and count, or from the sentence quoted beside it?",
            "status": "unverified"
        }
    ]
}
```

### FILE: tests/Feature/DefenseDoctrineHandoverTest.php
```php
<?php

use Modules\AI\Domain\Decision\DefenseCompositionPlanner;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

uses(AiQueueModuleTestCase::class);

/**
 * The account and the planet the shared test case builds for us: it names its own fixtures its own
 * way, so they are taken by type out of whatever it holds.
 */
function aiFixture(object $testCase, string $class): object
{
    $fixture = (function () use ($class) {
        foreach (get_object_vars($this) as $value) {
            foreach (is_iterable($value) ? $value : [$value] as $candidate) {
                if ($candidate instanceof $class) {
                    return $candidate;
                }
            }
        }

        return null;
    })->call($testCase);

    return $fixture ?? throw new RuntimeException(sprintf('The shared test case holds no %s.', $class));
}

/**
 * The doctrine that states a stop rule, and the rule itself, read from the behaviour file so the
 * test repeats none of the plan's numbers.
 *
 * @return array{from: string, doctrine: string, unit: string, count: int, quote: string}
 */
function defenseHandoverRule(): array
{
    $doctrines = Yaml::parseFile(module_path('AI', 'resources/behavior/defence-doctrines.yaml'))['doctrines'];

    foreach ($doctrines as $key => $doctrine) {
        $rule = $doctrine['stop_rule'] ?? null;

        if (!is_array($rule)) {
            continue;
        }

        return [
            'from' => $key,
            'doctrine' => $rule['doctrine'],
            'unit' => $rule['after']['unit'],
            'count' => $rule['after']['count'],
            'quote' => $rule['quote'],
        ];
    }

    throw new RuntimeException('defence-doctrines: no doctrine states a stop rule.');
}

/**
 * Writes a copy of the behaviour file to a temporary path, so a planner can be built on it.
 *
 * @param  array<string, mixed>  $doctrines
 */
function defenseDoctrinesFile(array $doctrines): string
{
    $path = tempnam(sys_get_temp_dir(), 'ai-doctrines-');
    file_put_contents($path, Yaml::dump($doctrines, 6));

    return $path;
}

/**
 * The behaviour file, parsed, ready to be changed.
 *
 * @return array<string, mixed>
 */
function defenseDoctrines(): array
{
    return Yaml::parseFile(module_path('AI', 'resources/behavior/defence-doctrines.yaml'));
}

it('hands the wall over to the rule doctrine once the wall passes the rule point', function () {
    $rule = defenseHandoverRule();

    $planet = aiFixture($this, PlanetService::class);
    $planet->addUnit($rule['unit'], $rule['count'] + 1);

    $planner = app(DefenseCompositionPlanner::class);

    expect($planner->doctrineFor(aiFixture($this, PlayerService::class), $planet))
        ->toBe($rule['doctrine']);
});

it('keeps the account on its own doctrine while the wall sits exactly on that point', function () {
    $rule = defenseHandoverRule();

    $planet = aiFixture($this, PlanetService::class);
    $planet->addUnit($rule['unit'], $rule['count']);

    $planner = app(DefenseCompositionPlanner::class);

    expect($planner->doctrineFor(aiFixture($this, PlayerService::class), $planet))
        ->not->toBe($rule['doctrine']);
});

it('keeps the account on its own doctrine with no wall at all', function () {
    $rule = defenseHandoverRule();

    $planner = app(DefenseCompositionPlanner::class);

    expect($planner->doctrineFor(aiFixture($this, PlayerService::class), aiFixture($this, PlanetService::class)))
        ->not->toBe($rule['doctrine']);
});

it('chooses the same doctrine when the sourced sentence beside the rule is reworded', function () {
    $rule = defenseHandoverRule();

    $planet = aiFixture($this, PlanetService::class);
    $planet->addUnit($rule['unit'], $rule['count'] + 1);

    // A sentence the old prose-matching rule would have read as a different hand-over.
    $doctrines = defenseDoctrines();
    $doctrines['doctrines'][$rule['from']]['stop_rule']['quote'] = 'swap to '.$rule['from'].' once the wall passes 1 anything';

    $path = defenseDoctrinesFile($doctrines);
    $planner = app()->makeWith(DefenseCompositionPlanner::class, ['doctrineFile' => $path]);

    expect($planner->doctrineFor(aiFixture($this, PlayerService::class), $planet))
        ->toBe($rule['doctrine']);

    unlink($path);
});

it('moves the point with the rule count and not with the sentence beside it', function () {
    $rule = defenseHandoverRule();

    $planet = aiFixture($this, PlanetService::class);
    $planet->addUnit($rule['unit'], $rule['count'] + 1);

    $doctrines = defenseDoctrines();
    $doctrines['doctrines'][$rule['from']]['stop_rule']['after']['count'] = $rule['count'] + 1000;

    $path = defenseDoctrinesFile($doctrines);
    $planner = app()->makeWith(DefenseCompositionPlanner::class, ['doctrineFile' => $path]);

    expect($planner->doctrineFor(aiFixture($this, PlayerService::class), $planet))
        ->not->toBe($rule['doctrine']);

    unlink($path);
});
```