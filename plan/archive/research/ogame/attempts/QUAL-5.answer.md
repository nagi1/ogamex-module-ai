### EDIT: app/Domain/Decision/PillageCap.php
<<<<<<< SEARCH
 * WIK-039's headline mentions rolling for defence rebuilds; the validated plan
 * scopes that out (no section owns defence-rebuild) and binds only the pillage
 * cap, so nothing else lives here.
 */
final class PillageCap
=======
 * WIK-091's headline splits a raid into two waves, and each wave's cargo obeys the
 * same half cap, so the wave split lives here beside the cap rather than in a class
 * no runtime code calls.
 */
final class PillageCap
>>>>>>> REPLACE
<<<<<<< SEARCH
    /**
     * Flooring the half keeps the take from creeping over half when a stock is
     * odd; the source states the cap, never the rounding.
     */
    private static function take(int $stock, int $cargoCapacity): int
=======
    /**
     * The two waves one raid on one target flies: the frontline sizes against the
     * defender's fleet and the defence the survival figure leaves exposed, the
     * follow-up re-plunders whatever the frontline's cargo left behind. Both waves
     * take at most half of the stock in front of them, so the split and this cap
     * are decided together.
     *
     * @return list<array{
     *     wave: int,
     *     light_fighters: int,
     *     heavy_fighters: int,
     *     stored: array{metal: int, crystal: int, deuterium: int},
     *     cargo: array{metal: int, crystal: int, deuterium: int}
     * }>
     */
    public static function waves(int $fleetUnits, int $defenseUnits, int $metal, int $crystal, int $deuterium): array
    {
        return app(WaveFarmPlanner::class)->plan($fleetUnits, $defenseUnits, $metal, $crystal, $deuterium);
    }

    /**
     * Flooring the half keeps the take from creeping over half when a stock is
     * odd; the source states the cap, never the rounding.
     */
    private static function take(int $stock, int $cargoCapacity): int
>>>>>>> REPLACE

### EDIT: app/Domain/Decision/WaveFarmPlanner.php
<<<<<<< SEARCH
 * The source fixes the split at 7:5 military units and caps each wave's cargo at half of
 * the stock still stored when that wave lands. No combat technology is ingested, so one
 * composition unit is valued as one enemy military unit and wave one is sized at the
 * defender's fleet plus the 30% of its defense the 70% survival figure leaves exposed.
 */
final class WaveFarmPlanner
{
    private const FRONTLINE_SHARE = 7;

    private const FOLLOW_UP_SHARE = 5;

    private const LIGHT_FIGHTER_SHARE = 5;

    private const HEAVY_FIGHTER_SHARE = 1;

    private const DEFENSE_SURVIVAL_PERCENT = 70;

    private const PLUNDER_CAP_PERCENT = 50;
=======
 * The source fixes the split and each wave's cargo cap, and both come from
 * resources/behavior/wave-farm.yaml, so a modder retunes them there rather than in this
 * class. No combat technology is ingested, so one composition unit is valued as one enemy
 * military unit and wave one is sized at the defender's fleet plus the share of its
 * defense the survival figure leaves exposed.
 */
final class WaveFarmPlanner
{
    private const POLICY_FILE = '/resources/behavior/wave-farm.yaml';

    private const ENTRY_SEPARATOR = ':';

    private const COMMENT_PREFIX = '#';

    private const PERCENT_SCALE = 100;
>>>>>>> REPLACE
<<<<<<< SEARCH
        $frontlineUnits = $fleetUnits + intdiv($defenseUnits * (100 - self::DEFENSE_SURVIVAL_PERCENT), 100);
        $followUpUnits = intdiv($frontlineUnits * self::FOLLOW_UP_SHARE, self::FRONTLINE_SHARE);

        $frontline = $this->wave(1, $frontlineUnits, $metal, $crystal, $deuterium);
        $followUp = $this->wave(
            2,
            $followUpUnits,
            $metal - $frontline['cargo']['metal'],
            $crystal - $frontline['cargo']['crystal'],
            $deuterium - $frontline['cargo']['deuterium'],
        );
=======
        $policy = self::policy();
        $frontlineUnits = $fleetUnits + intdiv($defenseUnits * (self::PERCENT_SCALE - $policy['defense_survival_percent']), self::PERCENT_SCALE);
        $followUpUnits = intdiv($frontlineUnits * $policy['follow_up_share'], $policy['frontline_share']);

        $frontline = $this->wave(1, $frontlineUnits, $metal, $crystal, $deuterium, $policy);
        $followUp = $this->wave(
            2,
            $followUpUnits,
            $metal - $frontline['cargo']['metal'],
            $crystal - $frontline['cargo']['crystal'],
            $deuterium - $frontline['cargo']['deuterium'],
            $policy,
        );
>>>>>>> REPLACE
<<<<<<< SEARCH
     *     cargo: array{metal: int, crystal: int, deuterium: int}
     * }
     */
    private function wave(int $number, int $units, int $metal, int $crystal, int $deuterium): array
    {
        $lightFighters = intdiv($units * self::LIGHT_FIGHTER_SHARE, self::LIGHT_FIGHTER_SHARE + self::HEAVY_FIGHTER_SHARE);

        return [
            'wave' => $number,
            'light_fighters' => $lightFighters,
            'heavy_fighters' => $units - $lightFighters,
            'stored' => ['metal' => $metal, 'crystal' => $crystal, 'deuterium' => $deuterium],
            'cargo' => $this->plunderCap($metal, $crystal, $deuterium),
        ];
    }

    /**
     * The 50% figure is the limit the source states, so a wave plans that share of the stock
     * in front of it and never empties the planet, however much is lying there.
     *
     * @return array{metal: int, crystal: int, deuterium: int}
     */
    private function plunderCap(int $metal, int $crystal, int $deuterium): array
    {
        return [
            'metal' => intdiv($metal * self::PLUNDER_CAP_PERCENT, 100),
            'crystal' => intdiv($crystal * self::PLUNDER_CAP_PERCENT, 100),
            'deuterium' => intdiv($deuterium * self::PLUNDER_CAP_PERCENT, 100),
        ];
    }
=======
     *     cargo: array{metal: int, crystal: int, deuterium: int}
     * }
     *
     * @param array<string, int> $policy
     */
    private function wave(int $number, int $units, int $metal, int $crystal, int $deuterium, array $policy): array
    {
        $lightFighters = intdiv($units * $policy['light_fighter_share'], $policy['light_fighter_share'] + $policy['heavy_fighter_share']);

        return [
            'wave' => $number,
            'light_fighters' => $lightFighters,
            'heavy_fighters' => $units - $lightFighters,
            'stored' => ['metal' => $metal, 'crystal' => $crystal, 'deuterium' => $deuterium],
            'cargo' => $this->plunderCap($metal, $crystal, $deuterium, $policy),
        ];
    }

    /**
     * The cap is the limit the source states, so a wave plans that share of the stock in
     * front of it and never empties the planet, however much is lying there.
     *
     * @param array<string, int> $policy
     *
     * @return array{metal: int, crystal: int, deuterium: int}
     */
    private function plunderCap(int $metal, int $crystal, int $deuterium, array $policy): array
    {
        return [
            'metal' => intdiv($metal * $policy['plunder_cap_percent'], self::PERCENT_SCALE),
            'crystal' => intdiv($crystal * $policy['plunder_cap_percent'], self::PERCENT_SCALE),
            'deuterium' => intdiv($deuterium * $policy['plunder_cap_percent'], self::PERCENT_SCALE),
        ];
    }

    /**
     * The policy file is flat `key: value` YAML so a modder can read and edit it, and the
     * module parses it without pulling in a YAML parser.
     *
     * @return array<string, int>
     */
    private static function policy(): array
    {
        $entries = [];

        foreach (preg_split('/\R/', (string) file_get_contents(dirname(__DIR__, 3) . self::POLICY_FILE)) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, self::COMMENT_PREFIX) || ! str_contains($line, self::ENTRY_SEPARATOR)) {
                continue;
            }

            [$key, $value] = array_map('trim', explode(self::ENTRY_SEPARATOR, $line, 2));

            $entries[$key] = (int) $value;
        }

        return $entries;
    }
>>>>>>> REPLACE

### FILE: resources/behavior/wave-farm.yaml
```yaml
# Raid wave split: one raid on one target flies a frontline wave and a follow-up re-plunder
# wave. The military units split 7:5 and each wave's cargo is capped at this share of the
# stock still stored when that wave lands, so neither wave empties the planet.
frontline_share: 7
follow_up_share: 5
light_fighter_share: 5
heavy_fighter_share: 1
defense_survival_percent: 70
plunder_cap_percent: 50
```

### FILE: tests/Feature/RaidWavePlanTest.php
```php
<?php

use Modules\AI\Domain\Decision\PillageCap;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// The raid's wave split rides the loot cap the account's raid path already applies, so the
// story here is the same one: how much of the stock a wave may take and how the units split.

it('plans a frontline wave sized against the defence and a follow-up two thirds its size', function () {
    $waves = PillageCap::waves(fleetUnits: 1200, defenseUnits: 200, metal: 1_000_000, crystal: 500_000, deuterium: 100_000);

    expect($waves)->toHaveCount(2)
        ->and($waves[0]['wave'])->toBe(1)
        // 1200 units plus the 30% of 200 defense the 70% survival figure leaves exposed.
        ->and($waves[0]['light_fighters'])->toBe(1050)
        ->and($waves[0]['heavy_fighters'])->toBe(210)
        ->and($waves[1]['wave'])->toBe(2)
        ->and($waves[1]['light_fighters'])->toBe(750)
        ->and($waves[1]['heavy_fighters'])->toBe(150);
});

it('caps each wave at half of the stock still stored when it lands', function () {
    $waves = PillageCap::waves(1200, 0, 1_000_000, 500_000, 100_000);

    expect($waves[0]['cargo'])->toBe(['metal' => 500_000, 'crystal' => 250_000, 'deuterium' => 50_000])
        ->and($waves[1]['stored'])->toBe(['metal' => 500_000, 'crystal' => 250_000, 'deuterium' => 50_000])
        ->and($waves[1]['cargo'])->toBe(['metal' => 250_000, 'crystal' => 125_000, 'deuterium' => 25_000]);
});

it('leaves the planet stock standing after both waves', function () {
    [$frontline, $followUp] = PillageCap::waves(1200, 0, 1_000_000, 500_000, 100_000);

    expect(1_000_000 - $frontline['cargo']['metal'] - $followUp['cargo']['metal'])->toBe(250_000)
        ->and(500_000 - $frontline['cargo']['crystal'] - $followUp['cargo']['crystal'])->toBe(125_000);
});

it('holds the frontline cargo to the same half the loot cap applies', function () {
    $waves = PillageCap::waves(900, 300, 700_001, 3, 0);

    expect($waves[0]['cargo'])->toBe(PillageCap::apply(700_001, 3, 0, PHP_INT_MAX));
});

it('plans no fighters when no unit is committed, and a whole one once a unit is', function () {
    $none = PillageCap::waves(0, 0, 100, 100, 0);

    expect($none[0]['light_fighters'])->toBe(0)
        ->and($none[0]['heavy_fighters'])->toBe(0)
        ->and($none[1]['light_fighters'])->toBe(0)
        ->and($none[1]['heavy_fighters'])->toBe(0);

    $one = PillageCap::waves(1, 0, 100, 100, 0);

    expect($one[0]['light_fighters'])->toBe(0)
        ->and($one[0]['heavy_fighters'])->toBe(1);
});
```