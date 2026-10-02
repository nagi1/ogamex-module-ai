<?php

use Illuminate\Foundation\Application;
use OGame\Services\ModuleSlotService;
use Symfony\Component\Yaml\Yaml;
use Tests\IsolatedAccountTestCase;

class AiTemperatureModuleTestCase extends IsolatedAccountTestCase
{
    private string $statusesFile;

    public function createApplication(): Application
    {
        $trackedFile = dirname(__DIR__, 4) . '/modules_statuses.json';
        $statuses = json_decode((string) file_get_contents($trackedFile), true);
        $statuses['AI'] = true;
        $this->statusesFile = sys_get_temp_dir() . '/modules_statuses_' . uniqid('', true) . '.json';
        file_put_contents($this->statusesFile, json_encode($statuses, JSON_PRETTY_PRINT));
        putenv('MODULES_STATUSES_FILE=' . $this->statusesFile);

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        if (is_file($this->statusesFile)) {
            unlink($this->statusesFile);
        }

        putenv('MODULES_STATUSES_FILE');

        // The slot registry is static, so a test that registered the module's nav link would
        // leak it into the next one.
        ModuleSlotService::resetSlots();

        parent::tearDown();
    }
}

uses(AiTemperatureModuleTestCase::class);

// The temperature rule is data the account reads, so the two decisions below are derived from
// the same file the AI consumes: retuning resources/behavior/temperature.yaml changes them.
$policy = Yaml::parseFile(dirname(__DIR__, 2) . '/resources/behavior/temperature.yaml');

$energyPerSatellite = static function (int $maxTemperature) use ($policy): int {
    $satellite = $policy['solar_satellite'];
    $unclamped = intdiv($maxTemperature + $satellite['energy_offset'], $satellite['degrees_per_energy']);

    return min($unclamped, $satellite['max_energy']);
};

$ranking = static function (string $building, array $planets) use ($policy): array {
    $wantsHottest = $policy[$building]['prefers'] === 'hottest';

    usort($planets, static function (array $left, array $right) use ($wantsHottest): int {
        $hotterFirst = $right['max_temperature'] <=> $left['max_temperature'];

        return $wantsHottest ? $hotterFirst : -$hotterFirst;
    });

    return array_column($planets, 'name');
};

it('clamps a solar satellite to 65 energy on the hottest slot', function () use ($policy, $energyPerSatellite) {
    $colderSlotEnergy = $energyPerSatellite(-40);

    expect($policy['solar_satellite']['max_energy'])->toBe(65)
        ->and($policy['solar_satellite']['prefers'])->toBe('hottest')
        // At the ceiling: 510 max temperature reaches 65 energy and no more.
        ->and($energyPerSatellite(510))->toBe(65)
        // Past the ceiling: a hotter slot is still capped at 65.
        ->and($energyPerSatellite(900))->toBe(65)
        // A cold slot is nowhere near the cap, so the ceiling is not a flat value.
        ->and($colderSlotEnergy)->toBeLessThan(65)
        ->and($colderSlotEnergy)->toBeGreaterThan(0);
});

it('ranks the solar satellite on the hot planet and the deuterium synthesizer on the cold one', function () use ($policy, $ranking) {
    $hotPlanet = ['name' => 'hot', 'max_temperature' => 510];
    $coldPlanet = ['name' => 'cold', 'max_temperature' => -40];

    expect($policy['deuterium_synthesizer']['prefers'])->toBe('coldest')
        ->and($ranking('solar_satellite', [$coldPlanet, $hotPlanet]))->toBe(['hot', 'cold'])
        ->and($ranking('deuterium_synthesizer', [$coldPlanet, $hotPlanet]))->toBe(['cold', 'hot']);
});

it('flips both rankings when the two planets swap temperatures', function () use ($ranking) {
    $planetThatWasHot = ['name' => 'hot', 'max_temperature' => -40];
    $planetThatWasCold = ['name' => 'cold', 'max_temperature' => 510];

    expect($ranking('solar_satellite', [$planetThatWasCold, $planetThatWasHot]))->toBe(['cold', 'hot'])
        ->and($ranking('deuterium_synthesizer', [$planetThatWasCold, $planetThatWasHot]))->toBe(['hot', 'cold']);
});
