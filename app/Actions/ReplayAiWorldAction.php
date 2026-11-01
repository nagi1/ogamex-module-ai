<?php

namespace Modules\AI\Actions;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Modules\AI\Support\SimulatedTime;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Http\Controllers\GalaxyController;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Highscore;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Training only (plan/rl W14): plays a simulated world - every capital, the colonies it founds and its fleets - on the
 * host in the order the simulator's clock ran it, and reports both sides per planet. Accounts meet (a slot one of them
 * colonised first), so the whole world plays on one clock. The host is the referee: any gap is a simulator error.
 */
class ReplayAiWorldAction
{
    /** At one second the fleets land first, then a login loads the page, places its yard orders, sends and recalls its fleets, then starts its builds. */
    private const ORDER = ['arrive' => 0, 'return' => 0, 'touch' => 1, 'officer' => 2, 'units' => 2, 'launch' => 3, 'recall' => 3];

    /** The host's mission type id of a missile strike, which the galaxy page sends and not the fleet form. */
    private const MISSILE = 10;

    /** @var array<string, int> a planet's coordinates to its host id, colonies from the moment the host founds them */
    private array $ids = [];

    /** @var array<int, int> per account (its capital's id), the timestamp the simulator's second zero falls on */
    private array $origins = [];

    /** @var array<int, FleetMission> the host's mission for each simulated launch, by the simulator's fleet id */
    private array $missions = [];

    /**
     * @param  list<array<string, mixed>>  $planets  the trace's planets
     * @param  list<int>  $yardBlockedBy
     * @param  int  $rank1Points  the simulator's best score, which sets what an expedition can find
     * @param  int  $darkMatter  the dark matter every account starts the simulated world with
     * @return list<array<string, mixed>> per planet: its key, its rows and both final states
     */
    public function handle(array $planets, int $horizonSeconds, array $yardBlockedBy, int $rank1Points, int $darkMatter = 0): array
    {
        Highscore::query()->update(['general' => $rank1Points]);
        DB::table('users')->update(['dark_matter' => $darkMatter]);
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
            $rows[$p][] = match (true) {
                in_array($step['queue'], ['arrive', 'return'], true) => $this->land($step, $origin, $planets),
                $step['queue'] === 'recall' => $this->recall($step, $origin),
                $step['queue'] === 'officer' => $this->hire($step, $origin, (int) $planets[$p]['account']),
                ! isset($this->ids[$key]) => ['start' => (int) $step['start'], 'queue' => $step['queue'], 'host_refusal' => 'the host has no planet there'],
                $step['queue'] === 'launch' && (int) $step['mission'] === self::MISSILE => $this->fire($this->planet($this->ids[$key]), $step, $origin),
                $step['queue'] === 'launch' => $this->launch($this->planet($this->ids[$key]), $step, $origin),
                default => $schedule->step($this->planet($this->ids[$key]), $step, $origin, $yardBlockedBy),
            };
        }

        return array_map(fn (array $simulated, int $p): array => [
            'planet_id' => $simulated['planet_id'] ?? $this->ids[$this->key($simulated['coord'])] ?? null,
            'account' => (int) $simulated['account'],
            'coord' => $simulated['coord'],
            'steps' => $rows[$p],
            'host_final' => isset($this->ids[$this->key($simulated['coord'])])
                ? $schedule->final($this->planet($this->ids[$this->key($simulated['coord'])]), $this->origins[(int) $simulated['account']] + $horizonSeconds)
                    + ['moon' => $this->planet($this->ids[$this->key($simulated['coord'])])->hasMoon(),
                        'dark_matter' => (int) $this->planet($this->ids[$this->key($simulated['coord'])])->getPlayer()->getUser()->dark_matter] : null,
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
    private function launch(PlanetService $planet, array $step, int $origin): array
    {
        app(ReplayAiEconomyScheduleAction::class)->at($planet, $origin + (int) $step['t']);
        $units = new UnitCollection();
        foreach ($step['ships'] as [$id, $amount]) {
            $units->addUnit(ObjectService::getUnitObjectById((int) $id), (int) $amount);
        }
        $row = ['start' => (int) $step['t'], 'queue' => 'launch', 'mission' => (int) $step['mission'], 'sim_arrive' => (int) $step['arrive'] - (int) $step['t'],
            'sim_fuel' => (int) $step['fuel'], 'host_arrive' => null, 'host_fuel' => null, 'host_refusal' => null];
        try {
            $mission = app()->makeWith(FleetMissionService::class, ['player' => $planet->getPlayer()])->createNewFromPlanet($planet, new Coordinate(...$step['to']), $this->targetType($step),
                (int) $step['mission'], $units, new Resources(...[...$step['cargo'], 0]), (float) $step['speed'], (int) ($step['holding_hours'] ?? 0));
        } catch (Exception $refused) {
            return ['host_refusal' => $refused->getMessage()] + $row;
        }
        $this->missions[(int) $step['fleet']] = $mission;

        return ['host_arrive' => (int) $mission->time_arrival - (int) $mission->time_departure, 'host_fuel' => (int) $mission->deuterium_consumption] + $row;
    }

    /**
     * Fires the missiles through the galaxy page's own call (`GalaxyController::missileAttack`) from the launching planet.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function fire(PlanetService $planet, array $step, int $origin): array
    {
        app(ReplayAiEconomyScheduleAction::class)->at($planet, $origin + (int) $step['t']);
        $row = ['start' => (int) $step['t'], 'queue' => 'launch', 'mission' => self::MISSILE, 'sim_arrive' => (int) $step['arrive'] - (int) $step['t'],
            'sim_fuel' => 0, 'host_arrive' => null, 'host_fuel' => 0, 'host_refusal' => null];
        DB::table('users')->where('id', $planet->getPlayer()->getId())->update(['planet_current' => $planet->getPlanetId()]);
        $this->forget();
        $player = app(PlayerServiceFactory::class)->make($planet->getPlayer()->getId());
        $request = Request::create('/', 'POST', ['galaxy' => $step['to'][0], 'system' => $step['to'][1], 'position' => $step['to'][2],
            'type' => $this->targetType($step)->value, 'missile_count' => array_sum(array_column($step['ships'], 1)), 'target_priority' => (int) $step['priority']]);
        $data = app(GalaxyController::class)->missileAttack($request, $player, app(PlanetServiceFactory::class), app(SettingsService::class))->getData(true);
        $this->forget();
        if (! ($data['success'] ?? false)) {
            return ['host_refusal' => (string) ($data['error'] ?? 'refused')] + $row;
        }
        $mission = FleetMission::query()->findOrFail((int) $data['mission_id']);
        $this->missions[(int) $step['fleet']] = $mission;

        return ['host_arrive' => (int) $mission->time_arrival - (int) $mission->time_departure] + $row;
    }

    /**
     * Hires the officer through the host's own purchase, at the second the simulator did.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function hire(array $step, int $origin, int $account): array
    {
        SimulatedTime::freezeAt(Date::createFromTimestamp($origin + (int) $step['t']));
        $this->forget();
        $result = app(QueueAiOfficerAction::class)->handle($this->planet($account)->getPlayer()->getId(), (string) $step['key'], (int) $step['days']);
        $this->forget();

        return ['start' => (int) $step['t'], 'queue' => 'officer', 'host_refusal' => $result->successful ? null : $result->reason];
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
    private function land(array $step, int $origin, array $planets): array
    {
        $sent = $this->missions[(int) $step['fleet']] ?? null;
        $mission = $step['queue'] === 'return' && $sent !== null ? FleetMission::query()->where('parent_id', $sent->id)->first() : $sent;
        $row = ['start' => (int) $step['t'], 'queue' => $step['queue'], 'sim_outcome' => $step['outcome'], 'host_outcome' => null, 'host_at' => null,
            'sim_gained' => $step['gained'] ?? null, 'host_gained' => null];
        if ($mission === null) {
            return ['host_outcome' => 'no mission'] + $row;
        }
        // An expedition acts at the end of its hold.
        $lands = (int) $mission->time_arrival + (int) $mission->time_holding;
        SimulatedTime::freezeAt(Date::createFromTimestamp($lands));
        $this->forget();
        // The arrival draws from the simulator's stream for it (an expedition's find, a counter-espionage roll), so it is
        // processed alone: the host's batch for a destination would roll a second fleet landing there on the same stream.
        app()->instance(Randomizer::class, new Randomizer(new Mt19937((int) ($step['seed'] ?? 0))));
        app()->makeWith(FleetMissionService::class, ['player' => app(PlayerServiceFactory::class)->make((int) $mission->user_id)])->updateMission($mission);
        app()->forgetInstance(Randomizer::class);
        $this->forget();
        $row['host_at'] = $lands - $origin;
        $processed = FleetMission::query()->find($mission->id);
        $row['host_outcome'] = match (true) {
            ! $processed?->processed => 'Pending',
            (bool) $processed->canceled => 'Cancelled',
            default => 'Done',
        };
        // A fleet lands with what its mission row holds: an expedition's find, a harvest, loot or the half fuel.
        if ($step['queue'] === 'return') {
            $row['host_gained'] = [(float) $mission->metal, (float) $mission->crystal, (float) $mission->deuterium];
        }
        if ($row['host_outcome'] === 'Done' && $step['queue'] === 'arrive' && (int) $mission->mission_type === 7) {
            $this->found($mission, $planets);
        }

        return $row;
    }

    /**
     * Turns the fleet home through the fleet page's own call at the second the simulator recalled it.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function recall(array $step, int $origin): array
    {
        $row = ['start' => (int) $step['t'], 'queue' => 'recall', 'host_refusal' => null];
        $sent = $this->missions[(int) $step['fleet']] ?? null;
        if ($sent === null) {
            return ['host_refusal' => 'no mission'] + $row;
        }
        SimulatedTime::freezeAt(Date::createFromTimestamp($origin + (int) $step['t']));
        $this->forget();
        try {
            app()->makeWith(FleetMissionService::class, ['player' => app(PlayerServiceFactory::class)->make((int) $sent->user_id)])->cancelMission(FleetMission::query()->findOrFail($sent->id));
        } catch (Exception $refused) {
            return ['host_refusal' => $refused->getMessage()] + $row;
        }
        $this->forget();

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

    /** @param  array<string, mixed>  $step */
    private function targetType(array $step): PlanetType
    {
        return match (true) {
            (int) $step['mission'] === 8 => PlanetType::DebrisField,
            (bool) ($step['moon'] ?? false) => PlanetType::Moon,
            default => PlanetType::Planet,
        };
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
