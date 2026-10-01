### FILE: app/Support/MineUpgradeRatio.php
```php
<?php

namespace Modules\AI\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * The mine-level doctrine: how the metal, crystal and deuterium mines are kept in step.
 *
 * Every ratio and every worked example lives in resources/behavior/mine-upgrade-ratio.yaml,
 * so mine play can be retuned without touching PHP. When the three mines sit on their
 * targets the doctrine has no opinion and returns null: the payback ranking is free to
 * choose.
 */
class MineUpgradeRatio
{
    public const METAL = 'metal';

    public const DEUTERIUM = 'deuterium';

    /** @var array<string, mixed>|null */
    private ?array $doctrine = null;

    /**
     * The mine the doctrine wants upgraded next, or null when the three are in step.
     */
    public function nextUpgrade(int $metalLevel, int $crystalLevel, int $deuteriumLevel): ?string
    {
        if ($metalLevel < $this->metalTarget($crystalLevel)) {
            return self::METAL;
        }

        if ($deuteriumLevel < $this->deuteriumTarget($crystalLevel)) {
            return self::DEUTERIUM;
        }

        return null;
    }

    public function metalTarget(int $crystalLevel): int
    {
        return $crystalLevel + $this->metalLevelOffset();
    }

    public function deuteriumTarget(int $crystalLevel): int
    {
        return intdiv($crystalLevel, $this->crystalLevelsPerDeuteriumLevel());
    }

    private function metalLevelOffset(): int
    {
        return (int) $this->doctrine()['metal_level_offset'];
    }

    private function crystalLevelsPerDeuteriumLevel(): int
    {
        return (int) $this->doctrine()['crystal_levels_per_deuterium_level'];
    }

    /**
     * @return array<string, mixed>
     */
    private function doctrine(): array
    {
        return $this->doctrine ??= $this->parse($this->contents());
    }

    private function contents(): string
    {
        return (string) file_get_contents($this->path());
    }

    private function path(): string
    {
        return dirname(__DIR__, 2) . '/resources/behavior/mine-upgrade-ratio.yaml';
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $contents): array
    {
        return match (true) {
            class_exists(Yaml::class) => (array) Yaml::parse($contents),
            function_exists('yaml_parse') => (array) yaml_parse($contents),
            default => throw new RuntimeException('No YAML parser for ' . $this->path() . '.'),
        };
    }
}
```

### FILE: tests/Feature/MineUpgradeRatioDoctrineTest.php
```php
<?php

use Modules\AI\Support\MineUpgradeRatio;
use Modules\AI\Tests\Support\AiQueueModuleTestCase;

uses(AiQueueModuleTestCase::class);

it('asks for no crystal mine at zero levels', function () {
    $doctrine = app(MineUpgradeRatio::class);

    expect($doctrine->nextUpgrade(0, 0, 0))->toBe(MineUpgradeRatio::METAL)
        ->and($doctrine->deuteriumTarget(0))->toBe(0);
});

it('holds the mines in step at the ratio bound', function () {
    $doctrine = app(MineUpgradeRatio::class);

    expect($doctrine->metalTarget(17))->toBe(19)
        ->and($doctrine->deuteriumTarget(17))->toBe(8)
        ->and($doctrine->nextUpgrade(19, 17, 8))->toBeNull();
});

it('puts metal next in line once the metal mine falls behind', function () {
    $doctrine = app(MineUpgradeRatio::class);

    expect($doctrine->nextUpgrade(18, 17, 8))->toBe(MineUpgradeRatio::METAL)
        ->and($doctrine->nextUpgrade(5, 17, 8))->toBe(MineUpgradeRatio::METAL);
});

it('puts deuterium next in line once the deuterium mine falls behind', function () {
    $doctrine = app(MineUpgradeRatio::class);

    expect($doctrine->nextUpgrade(19, 17, 7))->toBe(MineUpgradeRatio::DEUTERIUM)
        ->and($doctrine->nextUpgrade(19, 17, 0))->toBe(MineUpgradeRatio::DEUTERIUM);
});
```

### FILE: resources/scenarios/mine-ratio-metal-behind.json
```json
{
    "name": "mine-ratio-metal-behind",
    "situation": "A miner keeps metal and crystal within a level of each other, so payback ranking alone would pick crystal next, while the doctrine wants the metal mine two levels above the crystal mine.",
    "status": "unverified",
    "persona": "miner",
    "input": {
        "metal_mine": 15,
        "crystal_mine": 15,
        "deuterium_synthesizer": 7
    },
    "decision_key": "economy.mine_upgrade",
    "expect": {
        "action": "QueueAiBuildingAction",
        "object_machine_name": "metal_mine"
    },
    "checklist": [
        {
            "topic": "metal-offset",
            "question": "Is the metal mine kept exactly two levels above the crystal mine, or does the source allow a band?",
            "status": "unverified"
        },
        {
            "topic": "payback-tiebreak",
            "question": "When the three mines are in step, is the cheapest next step the one the ranking must pick?",
            "status": "unverified"
        }
    ]
}
```

### FILE: resources/scenarios/mine-ratio-deuterium-behind.json
```json
{
    "name": "mine-ratio-deuterium-behind",
    "situation": "A miner sits exactly on the metal target but one deuterium level below the doctrine's target, so the synthesizer must be the next mine even though it is the worst payback of the three.",
    "status": "unverified",
    "persona": "miner",
    "input": {
        "metal_mine": 17,
        "crystal_mine": 15,
        "deuterium_synthesizer": 6
    },
    "decision_key": "economy.mine_upgrade",
    "expect": {
        "action": "QueueAiBuildingAction",
        "object_machine_name": "deuterium_synthesizer"
    },
    "checklist": [
        {
            "topic": "deuterium-rounding",
            "question": "Does the deuterium target round down, so an odd crystal level asks for one fewer synthesizer level?",
            "status": "unverified"
        },
        {
            "topic": "milestone-override",
            "question": "Where the source's own metal/crystal/deuterium trio disagrees with the ratios, does the trio stand as written?",
            "status": "unverified"
        }
    ]
}
```