<?php

namespace Modules\AI\Actions\Rl;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Modules\AI\Support\SimulatedTime;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\TransportMission;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\HighscoreService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Throwable;

/**
 * Training only (plan/rl/north-star-plan.md, N1): random account states answered by the host's highscore, the parity
 * cases of the simulator's score module. Each case draws building levels, research, ships and defence on one real
 * account, gives its capital a moon in one case in four and sends 0-3 fleets in flight, then reads the four scores
 * from `HighscoreService` and records the very rows it read them from. Every case runs in a savepoint rolled back
 * before the next, and the whole draw in one transaction rolled back at the end. Objects and units are named by host
 * id only.
 */
class DrawAiScoreCasesAction
{
    public const VERSION = 1;

    /** The most of any unit a draw puts on a planet. */
    private const MAX_AMOUNT = 300;

    /** Stock given to a planet so any drawn fleet can pay its fuel (resources are not scored). */
    private const MAX_STOCK = 5_000_000;

    /** One case in this many gives the account's first planet a moon (the world keeps ships there). */
    private const MOON_IN = 4;

    /** The dispatch form's plain speed; the score does not read it, the host needs one. */
    private const SPEED = 10.0;

    private Randomizer $random;

    private int $maxLevel = 12;

    /**
     * @param  list<PlayerService>  $accounts  the universe's accounts, each with a planet
     * @return array<string, mixed>
     */
    public function handle(array $accounts, int $count, int $maxLevel, int $seed, int $start): array
    {
        $this->random = new Randomizer(new Mt19937($seed));
        $this->maxLevel = $maxLevel;
        $ids = array_map(static fn (PlayerService $player): int => $player->getId(), $accounts);

        DB::beginTransaction();
        $cases = [];
        for ($i = 0; $i < $count; $i++) {
            $cases[] = $this->isolated($ids, $start, $i);
        }
        DB::rollBack();
        SimulatedTime::freezeAt(Date::createFromTimestamp($start));
        app()->forgetInstance(Randomizer::class);
        $this->forget();

        return [
            'version' => self::VERSION,
            'seed' => $seed,
            'max_level' => $maxLevel,
            'start' => $start,
            'accounts' => $ids,
            'cases' => $cases,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function isolated(array $ids, int $start, int $index): array
    {
        DB::beginTransaction();
        try {
            return ['index' => $index] + $this->case($ids, $start);
        } finally {
            DB::rollBack();
            $this->forget();
        }
    }

    /**
     * Draw the state, fly the fleets, read the state back and score it.
     *
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function case(array $ids, int $start): array
    {
        SimulatedTime::freezeAt(Date::createFromTimestamp($start));
        $owner = $this->pick($ids);
        $this->draw($owner, $start);
        $this->forget();
        $this->fly($owner, $ids, $start);
        $this->forget();

        $player = $this->freshPlayer($owner);
        $highscore = app(HighscoreService::class);

        return [
            'account' => $owner,
            'research' => (object) $this->heldResearch($player),
            'planets' => $this->heldPlanets($player),
            'fleets' => $this->heldFleets($owner),
            'score' => [
                'total' => $highscore->getPlayerScore($player),
                'economy' => $highscore->getPlayerScoreEconomy($player),
                'research' => $highscore->getPlayerScoreResearch($player),
                'military' => $highscore->getPlayerScoreMilitary($player),
            ],
        ];
    }

    /** Every level, every unit, and a moon for the first planet in one case in four. */
    private function draw(int $owner, int $start): void
    {
        $player = $this->freshPlayer($owner);
        foreach ($player->planets->allPlanets() as $planet) {
            DB::table('planets')->where('id', $planet->getPlanetId())->update($this->drawnLevels($planet) + $this->drawnUnits() + ['time_last_update' => $start]);
        }

        $research = [];
        foreach (ObjectService::getResearchObjects() as $object) {
            $research[$object->machine_name] = $this->level();
        }
        DB::table('users_tech')->where('user_id', $owner)->update($research);

        $this->forget();
        $this->moon($this->freshPlayer($owner)->planets->first(), $start);
    }

    /** A moon with ships on it, as a fleet save leaves the account; its buildings stay out of the draw (the world has none). */
    private function moon(PlanetService|null $planet, int $start): void
    {
        if ($planet === null || $this->random->getInt(1, self::MOON_IN) !== 1) {
            return;
        }
        $moon = app(PlanetServiceFactory::class)->createMoonForPlanet($planet, 2_000_000, 20);
        DB::table('planets')->where('id', $moon->getPlanetId())->update($this->moonUnits() + ['time_last_update' => $start]);
    }

    /**
     * 0-3 fleets in flight from the account: real transports through the dispatch the fleet controller makes, so the
     * host counts ships the way it counts its own.
     *
     * @param  list<int>  $ids
     */
    private function fly(int $owner, array $ids, int $start): void
    {
        $player = $this->freshPlayer($owner);
        $targets = $this->targets($ids, $owner);
        if ($targets === []) {
            return;
        }
        for ($i = 0, $fleets = $this->random->getInt(0, 3); $i < $fleets; $i++) {
            $origin = $this->pick($player->planets->allPlanets());
            $units = $this->flyable($this->freshPlanet($origin->getPlanetId()));
            if ($units === null) {
                continue;
            }
            DB::table('planets')->where('id', $origin->getPlanetId())->update(['deuterium' => self::MAX_STOCK, 'time_last_update' => $start]);
            $this->forget();
            $origin = $this->freshPlanet($origin->getPlanetId());
            $target = $this->pick($targets);
            try {
                app()->makeWith(FleetMissionService::class, ['player' => $origin->getPlayer()])
                    ->createNewFromPlanet($origin, $target, PlanetType::Planet, TransportMission::getTypeId(), $units, new Resources(0, 0, 0, 0), self::SPEED);
            } catch (Throwable) {
                // A fleet the host refuses is one fewer in flight; the case scores what the host holds either way.
            }
            $this->forget();
        }
    }

    /**
     * A part of every ship the planet holds, so the dispatch is accepted and the planet keeps something behind.
     */
    private function flyable(PlanetService $planet): UnitCollection|null
    {
        $units = new UnitCollection();
        foreach ($planet->getShipUnits()->units as $entry) {
            if ($this->random->getInt(0, 1) === 1) {
                $units->addUnit($entry->unitObject, max(1, intdiv($entry->amount, 2)));
            }
        }

        return $units->getAmount() > 0 ? $units : null;
    }

    /**
     * @param  list<int>  $ids
     * @return list<Coordinate>
     */
    private function targets(array $ids, int $owner): array
    {
        $targets = [];
        foreach (array_diff($ids, [$owner]) as $other) {
            $planet = $this->freshPlayer($other)->planets->first();
            if ($planet !== null) {
                $targets[] = $planet->getPlanetCoordinates();
            }
        }

        return $targets;
    }

    /**
     * The state the host scored, read back from the rows it read.
     *
     * @return list<array<string, mixed>>
     */
    private function heldPlanets(PlayerService $player): array
    {
        $planets = [];
        foreach ($player->planets->allPlanets() as $planet) {
            $planets[] = [
                'id' => $planet->getPlanetId(),
                'levels' => (object) array_filter($this->heldLevels($planet)),
                'ships' => (object) $this->counts($planet->getShipUnits()),
                'defence' => (object) $this->counts($planet->getDefenseUnits()),
                'moon_ships' => (object) ($planet->hasMoon() ? $this->counts($planet->moon()->getShipUnits()) : []),
            ];
        }

        return $planets;
    }

    /** @return array<int, int> building id => level, and nothing for an object a planet cannot hold (a moon's) */
    private function heldLevels(PlanetService $planet): array
    {
        $levels = [];
        foreach ([...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects()] as $object) {
            if (ObjectService::objectValidPlanetType($object->machine_name, $planet)) {
                $levels[$object->id] = $planet->getObjectLevel($object->machine_name);
            }
        }

        return $levels;
    }

    /** @return array<int, int> research id => level, zeros left out */
    private function heldResearch(PlayerService $player): array
    {
        $levels = [];
        foreach (ObjectService::getResearchObjects() as $object) {
            $level = $player->getResearchLevel($object->machine_name);
            if ($level > 0) {
                $levels[$object->id] = $level;
            }
        }

        return $levels;
    }

    /**
     * Every fleet the host counts as in flight for this account: its unprocessed missions, ships by object id.
     *
     * @return list<array<string, mixed>>
     */
    private function heldFleets(int $owner): array
    {
        $fleets = [];
        foreach (FleetMission::query()->where('user_id', $owner)->where('processed', 0)->orderBy('id')->get() as $mission) {
            $ships = [];
            foreach (ObjectService::getShipObjects() as $ship) {
                $amount = (int) ($mission->{$ship->machine_name} ?? 0);
                if ($amount > 0) {
                    $ships[$ship->id] = $amount;
                }
            }
            $fleets[] = ['id' => $mission->id, 'mission' => $mission->mission_type, 'ships' => (object) $ships];
        }

        return $fleets;
    }

    /**
     * Every level a planet may hold drawn, and every object it may not (a moon's) at zero: the catalogue carries no
     * table for those, and the host reads their columns wherever they sit.
     *
     * @return array<string, int>
     */
    private function drawnLevels(PlanetService $planet): array
    {
        $levels = [];
        foreach ([...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects()] as $object) {
            $levels[$object->machine_name] = ObjectService::objectValidPlanetType($object->machine_name, $planet) ? $this->level() : 0;
        }

        return $levels;
    }

    /** @return array<string, int> every ship and defence column drawn for a planet */
    private function drawnUnits(): array
    {
        $columns = $this->drawnShips();
        foreach (ObjectService::getDefenseObjects() as $defence) {
            $columns[$defence->machine_name] = $this->random->getInt(0, 3) === 0 ? 0 : $this->logUniform(self::MAX_AMOUNT);
        }

        return $columns;
    }

    /** @return array<string, int> every ship column drawn for a moon, every defence column at zero */
    private function moonUnits(): array
    {
        $columns = $this->drawnShips();
        foreach (ObjectService::getDefenseObjects() as $defence) {
            $columns[$defence->machine_name] = 0;
        }

        return $columns;
    }

    /** @return array<string, int> every ship column, some of them empty */
    private function drawnShips(): array
    {
        $columns = [];
        foreach (ObjectService::getShipObjects() as $ship) {
            $columns[$ship->machine_name] = $this->random->getInt(0, 3) === 0 ? 0 : $this->logUniform(self::MAX_AMOUNT);
        }

        return $columns;
    }

    private function level(): int
    {
        return $this->random->getInt(1, 3) === 1 ? 0 : $this->random->getInt(1, $this->maxLevel);
    }

    /** @return array<int, int> unit id => amount, zeros left out */
    private function counts(UnitCollection $units): array
    {
        $counts = [];
        foreach ($units->units as $entry) {
            if ($entry->amount > 0) {
                $counts[$entry->unitObject->id] = $entry->amount;
            }
        }
        ksort($counts);

        return $counts;
    }

    private function freshPlayer(int $id): PlayerService
    {
        return app(PlayerServiceFactory::class)->make($id, true);
    }

    private function freshPlanet(int $id): PlanetService
    {
        return app(PlanetServiceFactory::class)->make($id, true) ?? throw new RuntimeException("Planet {$id} not found.");
    }

    /** The host caches services per id; a rolled-back or host-written row must be read again. */
    private function forget(): void
    {
        app(PlayerServiceFactory::class)->clearInstances();
        app(PlanetServiceFactory::class)->clearInstances();
    }

    private function logUniform(int $max): int
    {
        return max(1, min($max, (int) floor(exp($this->random->getFloat(0, log($max + 1))))));
    }

    /**
     * @template T
     * @param  array<int, T>  $values
     * @return T
     */
    private function pick(array $values): mixed
    {
        $values = array_values($values);

        return $values[$this->random->getInt(0, count($values) - 1)];
    }
}
