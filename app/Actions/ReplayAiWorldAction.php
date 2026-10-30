<?php

namespace Modules\AI\Actions;

use Exception;
use Illuminate\Support\Facades\Date;
use Modules\AI\Support\SimulatedTime;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;

/**
 * Training only (plan/rl W14): plays a simulated world - every capital, the colonies it founds and its fleets - on the
 * host in the order the simulator's clock ran it, and reports both sides per planet. Accounts meet (a slot one of them
 * colonised first), so the whole world plays on one clock. The host is the referee: any gap is a simulator error.
 */
class ReplayAiWorldAction
{
    /** At one second the fleets land first, then a login loads the page, places its yard orders, sends its fleets, then starts its builds. */
    private const ORDER = ['arrive' => 0, 'return' => 0, 'touch' => 1, 'units' => 2, 'launch' => 3];

    /** @var array<string, int> a planet's coordinates to its host id, colonies from the moment the host founds them */
    private array $ids = [];

    /** @var array<int, int> per account (its capital's id), the timestamp the simulator's second zero falls on */
    private array $origins = [];

    /** @var array<string, FleetMission> the host's mission for each simulated launch, by its planet and the second it left */
    private array $missions = [];

    /**
     * @param  list<array<string, mixed>>  $planets  the trace's planets
     * @param  list<int>  $yardBlockedBy
     * @return list<array<string, mixed>> per planet: its key, its rows and both final states
     */
    public function handle(array $planets, int $horizonSeconds, array $yardBlockedBy): array
    {
        $schedule = app(ReplayAiEconomyScheduleAction::class);
        foreach ($planets as $simulated) {
            $this->origins[(int) $simulated['account']] ??= $this->planet((int) $simulated['account'])->getUpdatedAt()->timestamp;
            if ($simulated['planet_id'] !== null) {
                $this->ids[$this->key($simulated['coord'])] = (int) $simulated['planet_id'];
            }
        }
        $rows = array_fill(0, count($planets), []);
        foreach ($this->timeline($planets) as [$p, $step]) {
            $key = $this->key($planets[$p]['coord']);
            $origin = $this->origins[(int) $planets[$p]['account']];
            $rows[$p][] = match ($step['queue']) {
                'launch' => $this->launch($this->planet($this->ids[$key]), $key, $step, $origin),
                'arrive', 'return' => $this->land($key, $step, $origin, $planets),
                default => isset($this->ids[$key]) ? $schedule->step($this->planet($this->ids[$key]), $step, $origin, $yardBlockedBy)
                    : ['start' => (int) $step['start'], 'queue' => $step['queue'], 'host_refusal' => 'the host has no planet there'],
            };
        }

        return array_map(fn (array $simulated, int $p): array => [
            'planet_id' => $simulated['planet_id'] ?? $this->ids[$this->key($simulated['coord'])] ?? null,
            'coord' => $simulated['coord'],
            'steps' => $rows[$p],
            'host_final' => isset($this->ids[$this->key($simulated['coord'])])
                ? $schedule->final($this->planet($this->ids[$this->key($simulated['coord'])]), $this->origins[(int) $simulated['account']] + $horizonSeconds) : null,
            'sim_final' => $simulated['final'],
        ], $planets, array_keys($planets));
    }

    /**
     * Every planet's steps in one clock order, each with its planet's index.
     *
     * @param  list<array<string, mixed>>  $planets
     * @return list<array{0: int, 1: array<string, mixed>}>
     */
    private function timeline(array $planets): array
    {
        $steps = [];
        foreach ($planets as $p => $simulated) {
            foreach ($simulated['steps'] as $step) {
                $steps[] = [$p, $step + ['start' => $step['t']]];
            }
        }
        usort($steps, static fn (array $a, array $b): int => [(int) $a[1]['t'], self::ORDER[$a[1]['queue']] ?? 4] <=> [(int) $b[1]['t'], self::ORDER[$b[1]['queue']] ?? 4]);

        return $steps;
    }

    /**
     * Sends the fleet through the dispatch form's own call and reports the host's flight and fuel next to the simulator's.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function launch(PlanetService $planet, string $key, array $step, int $origin): array
    {
        app(ReplayAiEconomyScheduleAction::class)->at($planet, $origin + (int) $step['t']);
        $units = new UnitCollection();
        foreach ($step['ships'] as [$id, $amount]) {
            $units->addUnit(ObjectService::getUnitObjectById((int) $id), (int) $amount);
        }
        $row = ['start' => (int) $step['t'], 'queue' => 'launch', 'mission' => (int) $step['mission'], 'sim_arrive' => (int) $step['arrive'] - (int) $step['t'],
            'sim_fuel' => (int) $step['fuel'], 'host_arrive' => null, 'host_fuel' => null, 'host_refusal' => null];
        try {
            $mission = app()->makeWith(FleetMissionService::class, ['player' => $planet->getPlayer()])->createNewFromPlanet($planet, new Coordinate(...$step['to']), PlanetType::Planet,
                (int) $step['mission'], $units, new Resources(...[...$step['cargo'], 0]), (float) $step['speed']);
        } catch (Exception $refused) {
            return ['host_refusal' => $refused->getMessage()] + $row;
        }
        $this->missions[$key.'@'.$step['t']] = $mission;

        return ['host_arrive' => (int) $mission->time_arrival - (int) $mission->time_departure, 'host_fuel' => (int) $mission->deuterium_consumption] + $row;
    }

    /**
     * Processes the host's mission at the second the host lands it (its own clock, so a flight gap shows as a gap and is not
     * hidden). A colony the mission founds takes the size and temperature the simulator drew: both draw from the host's
     * own table for the slot, and its production depends on them.
     *
     * @param  array<string, mixed>  $step
     * @param  list<array<string, mixed>>  $planets
     * @return array<string, mixed>
     */
    private function land(string $key, array $step, int $origin, array $planets): array
    {
        $sent = $this->missions[$key.'@'.$step['departed']] ?? null;
        $mission = $step['queue'] === 'return' && $sent !== null ? FleetMission::query()->where('parent_id', $sent->id)->first() : $sent;
        $row = ['start' => (int) $step['t'], 'queue' => $step['queue'], 'sim_outcome' => $step['outcome'], 'host_outcome' => null, 'host_at' => null];
        if ($mission === null) {
            return ['host_outcome' => 'no mission'] + $row;
        }
        SimulatedTime::freezeAt(Date::createFromTimestamp((int) $mission->time_arrival));
        $this->forget();
        app()->makeWith(FleetMissionService::class, ['player' => $this->planet((int) $mission->planet_id_from)->getPlayer()])->processDueMissionEventsForMissionId($mission->id);
        $this->forget();
        $row['host_at'] = (int) $mission->time_arrival - $origin;
        $processed = FleetMission::query()->find($mission->id);
        $row['host_outcome'] = match (true) {
            ! $processed?->processed => 'Pending',
            (bool) $processed->canceled => 'Cancelled',
            default => 'Done',
        };
        if ($row['host_outcome'] === 'Done' && $step['queue'] === 'arrive' && (int) $mission->mission_type === 7) {
            $this->found($mission, $planets);
        }

        return $row;
    }

    /**
     * Registers the colony the host founded at the mission's target, with the simulator's draw for its size and temperature.
     *
     * @param  list<array<string, mixed>>  $planets
     */
    private function found(FleetMission $mission, array $planets): void
    {
        $coord = [(int) $mission->galaxy_to, (int) $mission->system_to, (int) $mission->position_to];
        $colony = app(PlanetServiceFactory::class)->makePlanetForCoordinate(new Coordinate(...$coord), false);
        $simulated = array_values(array_filter($planets, fn (array $p): bool => $this->key($p['coord']) === $this->key($coord)))[0] ?? null;
        if ($colony === null || $simulated === null || $simulated['site'] === null) {
            return;
        }
        $fields = (int) $simulated['site']['field_max'];
        Planet::query()->whereKey($colony->getPlanetId())->update(['temp_max' => $simulated['site']['temp_max'], 'temp_min' => $simulated['site']['temp_max'] - 40,
            'field_max' => $fields, 'diameter' => (int) (36.14 * $fields + 5697.23)]);
        $this->forget();
        $this->ids[$this->key($coord)] = $colony->getPlanetId();
    }

    private function planet(int $id): PlanetService
    {
        return app(PlanetServiceFactory::class)->make($id, true) ?? throw new Exception("Planet {$id} not found.");
    }

    /** A mission processed through other instances leaves cached planets and players stale. */
    private function forget(): void
    {
        app(PlayerServiceFactory::class)->clearInstances();
        app(PlanetServiceFactory::class)->clearInstances();
    }

    /** @param  list<int>  $coord */
    private function key(array $coord): string
    {
        return implode(':', $coord);
    }
}
