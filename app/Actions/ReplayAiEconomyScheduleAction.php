<?php

namespace Modules\AI\Actions;

use Illuminate\Support\Facades\Date;
use Modules\AI\Support\SimulatedTime;
use OGame\Models\BuildingQueue;
use OGame\Services\BuildingQueueService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;

/**
 * Training only (plan/rl, ogsim): plays a build order the fast simulator produced on a real host planet, through
 * the host's own queue and clock, and reports what the host had at every step. The host is the referee: any gap
 * between the two is a simulator error.
 */
class ReplayAiEconomyScheduleAction
{
    /**
     * @param  list<array{start: float, id: int, end: float, stock: list<float>}>  $steps  seconds from the planet's last update
     * @return array{steps: list<array<string, mixed>>, final: array{stock: list<float>, levels: array<int, int>}}
     */
    public function handle(PlanetService $planet, array $steps, int $horizonSeconds): array
    {
        $origin = $planet->getUpdatedAt()->timestamp;
        $queue = app(BuildingQueueService::class);
        $rows = [];
        foreach ($steps as $step) {
            $this->at($planet, $origin + (int) $step['start']);
            $stock = $this->stock($planet);
            $queue->add($planet, (int) $step['id']);
            $item = BuildingQueue::query()->where('planet_id', $planet->getPlanetId())->orderByDesc('id')->first();
            $rows[] = [
                'id' => (int) $step['id'],
                'start' => (int) $step['start'],
                'sim_stock' => $step['stock'],
                'host_stock' => $stock,
                'sim_seconds' => (int) $step['end'] - (int) $step['start'],
                'host_seconds' => $item?->building ? (int) $item->time_duration : null,
            ];
        }
        $this->at($planet, $origin + $horizonSeconds);
        $levels = [];
        foreach ([...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects()] as $object) {
            $levels[$object->id] = $planet->getObjectLevel($object->machine_name);
        }

        return ['steps' => $rows, 'final' => ['stock' => $this->stock($planet), 'levels' => $levels]];
    }

    private function at(PlanetService $planet, int $timestamp): void
    {
        SimulatedTime::freezeAt(Date::createFromTimestamp($timestamp));
        $planet->update();
    }

    /** @return list<float> */
    private function stock(PlanetService $planet): array
    {
        return [$planet->metal()->get(), $planet->crystal()->get(), $planet->deuterium()->get()];
    }
}
