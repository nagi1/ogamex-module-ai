### EDIT: app/Domain/Decision/DefenseCompositionPlanner.php
<<<<<<< SEARCH
    private function resolveUnit(string $name, array $defenceObjects): UnitObject
    {
        $machineName = strtolower(preg_replace('/[\s-]+/', '_', $name) ?? '');

        return $defenceObjects[$machineName] ?? throw new RuntimeException(
            sprintf('defence-doctrines: no host defence object named "%s".', $name)
        );
    }
=======
    private function resolveUnit(string $name, array $defenceObjects): UnitObject
    {
        $machineName = self::machineName($name);

        return $defenceObjects[$machineName] ?? throw new RuntimeException(
            sprintf('defence-doctrines: no host defence object named "%s".', $name)
        );
    }

    /**
     * Any spelling of a host object's name folded onto the registry's machine-name form.
     *
     * One unit is written three ways across the module's data -- `small_shield_dome` in the
     * behaviour file, `SmallShieldDome` in the doctrine constants and a scenario, `Small Shield
     * Dome` as the display title -- and only the registry's own key is stable, so every spelling
     * is folded onto it here rather than normalised in one file and missed in the next.
     */
    public static function machineName(string $name): string
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $name) ?? $name;

        return strtolower(preg_replace('/[\s-]+/', '_', $spaced) ?? $spaced);
    }
>>>>>>> REPLACE

### FILE: tests/Feature/DefenseCompositionDomeNameTest.php
```php
<?php

use Modules\AI\Domain\Decision\DefenseCompositionPlanner;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;
use OGame\Services\ObjectService;
use Symfony\Component\Yaml\Yaml;

uses(AiQueueModuleTestCase::class);

it('folds every unit name in the behaviour data and the scenarios onto a host object', function () {
    $host = [];
    $words = [];

    // The host registry is gate 1: both the keys the planner looks a name up under and the words a
    // name may be spelled with come from it, never from a list here.
    foreach ([ObjectService::getDefenseObjects(), ObjectService::getShipObjects()] as $objects) {
        foreach ($objects as $object) {
            $machineName = DefenseCompositionPlanner::machineName($object->machine_name);
            $host[$machineName] = $object->machine_name;

            foreach (explode('_', $machineName) as $word) {
                $words[$word] = true;
            }
        }
    }

    $known = array_keys($words);

    $strings = function (mixed $document) use (&$strings): array {
        if (is_string($document)) {
            return [$document];
        }

        if (!is_array($document)) {
            return [];
        }

        $found = [];

        foreach ($document as $key => $value) {
            $found = [...$found, ...(is_string($key) ? [$key] : []), ...$strings($value)];
        }

        return $found;
    };

    $documents = ['behaviour' => [], 'scenario' => []];

    foreach (glob(module_path('AI', 'resources/behavior/*.yaml')) ?: [] as $file) {
        $documents['behaviour'][$file] = $strings(Yaml::parseFile($file));
    }

    foreach (glob(module_path('AI', 'resources/scenarios/*.json')) ?: [] as $file) {
        $documents['scenario'][$file] = $strings(json_decode((string) file_get_contents($file), true));
    }

    $checked = ['behaviour' => 0, 'scenario' => 0];
    $unresolved = [];

    foreach ($documents as $kind => $files) {
        foreach ($files as $file => $names) {
            foreach ($names as $name) {
                $parts = explode('_', DefenseCompositionPlanner::machineName($name));

                // A unit name is two or three of the registry's own words; prose, reason strings
                // and doctrine keys are longer, or carry a word the registry never uses.
                if (count($parts) < 2 || count($parts) > 3 || array_diff($parts, $known) !== []) {
                    continue;
                }

                $checked[$kind]++;

                if (!isset($host[implode('_', $parts)])) {
                    $unresolved[] = basename($file) . ': ' . $name;
                }
            }
        }
    }

    expect($checked['behaviour'])->toBeGreaterThan(0);
    expect($checked['scenario'])->toBeGreaterThan(0);
    expect(implode(', ', $unresolved))->toBe('');
});
```

### FILE: resources/scenarios/defense-dome-spelling.json
```json
{
    "name": "defense-dome-spelling",
    "situation": "A doctrine wants shield domes. The behaviour file names the unit by machine name, this scenario and the doctrine constants name the same unit in StudlyCase. They are one host object, so the unit the account queues must still be the small shield dome.",
    "status": "unverified",
    "persona": "defender",
    "input": {
        "doctrine": "balanced",
        "planet": {
            "defence": {
                "SmallShieldDome": 0
            },
            "resources": {
                "metal": 40000,
                "crystal": 40000,
                "deuterium": 0
            }
        }
    },
    "decision_key": "defense:balanced:small_shield_dome",
    "expect": {
        "action": "QueueAiBuildingAction",
        "decision_key": "defense:balanced:small_shield_dome",
        "unit": "small_shield_dome"
    },
    "checklist": [
        {
            "topic": "spelling-gate",
            "question": "Does the planner fold SmallShieldDome, small_shield_dome and the display title onto the one host defence object?",
            "status": "unverified"
        },
        {
            "topic": "doctrine-constant",
            "question": "Is a name taken from the doctrine constants queued as the same unit the behaviour file names by machine name?",
            "status": "unverified"
        }
    ]
}
```

### FILE: resources/scenarios/defense-doctrine-unknown-unit.json
```json
{
    "name": "defense-doctrine-unknown-unit",
    "situation": "A doctrine names a defence unit the host registry does not have. Folding spellings must not turn that into a silent skip: the planner fails loudly instead of planning a wall it cannot price.",
    "status": "unverified",
    "persona": "defender",
    "input": {
        "doctrine": "balanced",
        "planet": {
            "defence": {
                "SmallShieldDoom": 0
            }
        }
    },
    "decision_key": "defense:balanced:unknown_object",
    "expect": {
        "action": "none",
        "throws": "defence-doctrines: no host defence object named \"SmallShieldDoom\"."
    },
    "checklist": [
        {
            "topic": "unknown-unit",
            "question": "Does a name the host registry does not have still raise instead of being folded onto some other object?",
            "status": "unverified"
        },
        {
            "topic": "no-silent-default",
            "question": "Is there any fallback unit the planner queues when the named one is unknown?",
            "status": "unverified"
        }
    ]
}
```