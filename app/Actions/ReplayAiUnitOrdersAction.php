<?php

namespace Modules\AI\Actions;

use Illuminate\Support\Facades\Date;
use Modules\AI\Support\SimulatedTime;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\GameObjects\Models\UnitObject;
use OGame\Models\Planet;
use OGame\Models\UnitQueue;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\UnitQueueService;

/**
 * Training only (plan/rl/world-sim-spec.md, units parity): puts a real host planet in a seeded random state, places
 * random ship and defence orders through the host's own unit queue on the host's clock, and records what the host
 * accepted, charged and handed over. The simulator must reproduce every number; the host is the referee.
 */
class ReplayAiUnitOrdersAction
{
    /**
     * @return array<string, mixed>
     */
    public function handle(PlanetService $planet, int $seed, int $orderCount, int $horizonSeconds, int $maxLevel): array
    {
        mt_srand($seed);
        $units = array_values(ObjectService::getUnitObjects());
        $setup = $this->randomSetup($units, $maxLevel);
        $origin = $planet->getUpdatedAt()->getTimestamp();
        $this->applySetup($planet, $setup);
        $this->at($planet, $origin);

        // Orders and looks share one clock, so they are played in time order; at the same second the order goes first.
        $events = [...array_map(static fn (int $at): array => [$at, 0], $this->sortedSeconds($orderCount, $horizonSeconds)), ...array_map(static fn (int $at): array => [$at, 1], [...$this->sortedSeconds($orderCount, $horizonSeconds * 2), $horizonSeconds * 4])];
        sort($events);
        $orders = [];
        $observations = [];
        foreach ($events as [$at, $kind]) {
            if ($kind === 1) {
                $observations[] = ['at' => $at, 'units' => $this->unitCounts($this->at($planet, $origin + $at), $units)];

                continue;
            }
            $unit = $units[mt_rand(0, count($units) - 1)];
            $amount = mt_rand(0, 3) === 0 ? mt_rand(1, 5000) : mt_rand(1, 30);
            $orders[] = ['at' => $at, 'id' => $unit->id, 'amount' => $amount] + $this->order($planet, $origin + $at, $origin, $unit, $amount);
        }

        return ['planet_id' => $planet->getPlanetId(), 'seed' => $seed, 'origin' => $origin, 'setup' => $setup, 'orders' => $orders, 'observations' => $observations];
    }

    /**
     * Levels for every building, station and research up to a random tier, a random stock and a few units already
     * built, so orders meet requirements, prices and capacity caps both ways.
     *
     * @param  list<UnitObject>  $units
     * @return array{levels: array<int, int>, resources: list<float>, units: array<int, int>}
     */
    private function randomSetup(array $units, int $maxLevel): array
    {
        $tier = mt_rand(3, $maxLevel);
        $levels = [];
        foreach ([...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects(), ...ObjectService::getResearchObjects()] as $object) {
            $levels[(string) $object->id] = mt_rand(0, $tier);
        }
        $scale = 10 ** mt_rand(3, 6);
        $counts = [];
        foreach ($units as $unit) {
            $counts[(string) $unit->id] = mt_rand(0, 4) === 0 ? mt_rand(0, 3) : 0;
        }

        return ['levels' => $levels, 'resources' => [(float) mt_rand(0, $scale), (float) mt_rand(0, $scale), (float) mt_rand(0, $scale)], 'units' => $counts];
    }

    /** @param array{levels: array<int, int>, resources: list<float>, units: array<int, int>} $setup */
    private function applySetup(PlanetService $planet, array $setup): void
    {
        $columns = ['metal' => $setup['resources'][0], 'crystal' => $setup['resources'][1], 'deuterium' => $setup['resources'][2]];
        foreach ($setup['levels'] as $id => $level) {
            $object = ObjectService::getObjectById((int) $id);
            if ($object->type === GameObjectType::Research) {
                $planet->getPlayer()?->setResearchLevel($object->machine_name, $level);

                continue;
            }
            $columns[$object->machine_name] = $level;
        }
        foreach ($setup['units'] as $id => $count) {
            $columns[ObjectService::getObjectById((int) $id)->machine_name] = $count;
        }
        Planet::query()->whereKey($planet->getPlanetId())->update($columns);
    }

    /** @return list<int> */
    private function sortedSeconds(int $count, int $horizonSeconds): array
    {
        $seconds = array_map(static fn (): int => mt_rand(0, $horizonSeconds), range(1, max(1, $count)));
        sort($seconds);

        return $seconds;
    }

    /**
     * Places one order the way the shipyard page does and reports the host's answer: the amount it queued, its
     * seconds from the origin, and the stock before paying.
     *
     * @return array{stock: list<float>, accepted: int, start: int|null, end: int|null}
     */
    private function order(PlanetService $planet, int $timestamp, int $origin, UnitObject $unit, int $amount): array
    {
        $this->at($planet, $timestamp);
        $stock = [$planet->metal()->get(), $planet->crystal()->get(), $planet->deuterium()->get()];
        $last = (int) UnitQueue::query()->where('planet_id', $planet->getPlanetId())->max('id');
        app(UnitQueueService::class)->add($planet, $unit->id, $amount);
        $item = UnitQueue::query()->where('planet_id', $planet->getPlanetId())->where('id', '>', $last)->first();

        return [
            'stock' => $stock,
            'accepted' => $item === null ? 0 : (int) $item->object_amount,
            'start' => $item === null ? null : (int) $item->time_start - $origin,
            'end' => $item === null ? null : (int) $item->time_end - $origin,
        ];
    }

    private function at(PlanetService $planet, int $timestamp): PlanetService
    {
        SimulatedTime::freezeAt(Date::createFromTimestamp($timestamp));
        $planet->update();

        return $planet;
    }

    /**
     * @param  list<UnitObject>  $units
     * @return array<int, int>
     */
    private function unitCounts(PlanetService $planet, array $units): array
    {
        return array_combine(array_map(static fn (UnitObject $unit): string => (string) $unit->id, $units), array_map(static fn (UnitObject $unit): int => $planet->getObjectAmount($unit->machine_name), $units));
    }
}
