<?php

namespace Modules\AI\Actions;

use Illuminate\Support\Facades\Date;
use Modules\AI\Domain\Choice\EconomyChoiceEncoder;
use Modules\AI\Domain\Decision\BuildCandidate;
use Modules\AI\Support\SimulatedTime;
use OGame\Models\BuildingQueue;
use OGame\Services\BuildingQueueService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\SettingsService;

/**
 * Training only (plan/rl, ogsim): plays a build order the fast simulator produced on a real host planet, through
 * the host's own queue and clock, and reports what the host had at every step. The host is the referee: any gap
 * between the two is a simulator error.
 */
class ReplayAiEconomyScheduleAction
{
    /**
     * @param  list<array{start: float, id: int, end: float, stock: list<float>, decision?: float}>  $steps  seconds from the planet's last update
     * @param  list<int>  $objectIds  the catalogue's objects in its order; a step with a `decision` also reports the encoder's features then
     * @return array{steps: list<array<string, mixed>>, final: array{stock: list<float>, levels: array<int, int>}, names: array{state: list<string>, candidate: list<string>}}
     */
    public function handle(PlanetService $planet, array $steps, int $horizonSeconds, array $objectIds = []): array
    {
        $origin = $planet->getUpdatedAt()->timestamp;
        $queue = app(BuildingQueueService::class);
        $rows = [];
        foreach ($steps as $step) {
            $features = isset($step['decision']) ? $this->features($planet, $origin + (int) $step['decision'], $objectIds) : null;
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
                'host_features' => $features,
                'sim_state' => $step['sim_state'] ?? null,
                'sim_cands' => $step['sim_cands'] ?? null,
                'sim_legal' => $step['sim_legal'] ?? null,
            ];
        }
        $this->at($planet, $origin + $horizonSeconds);
        $levels = [];
        foreach ([...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects()] as $object) {
            $levels[$object->id] = $planet->getObjectLevel($object->machine_name);
        }

        return [
            'steps' => $rows,
            'final' => ['stock' => $this->stock($planet), 'levels' => $levels],
            'names' => ['state' => EconomyChoiceEncoder::stateNames(), 'candidate' => EconomyChoiceEncoder::candidateNames()],
        ];
    }

    /**
     * What the encoder emits for every listed object at the moment the free queue asks, in full width.
     *
     * @param  list<int>  $objectIds
     * @return array{state: list<float>, cands: list<list<float>>}
     */
    private function features(PlanetService $planet, int $timestamp, array $objectIds): array
    {
        $this->at($planet, $timestamp);
        $encoder = app(EconomyChoiceEncoder::class);
        $names = EconomyChoiceEncoder::stateNames();
        $account = array_fill(0, count($names) - count($encoder->planetState($planet, false)), 0.0);
        $account[array_search('speed_economy', $names, true)] = log(max(1, app(SettingsService::class)->economySpeed()));
        $rows = array_map(static fn (int $id): array => ['candidate' => new BuildCandidate($id, 'parity'), 'pass' => 'routine', 'legal' => true, 'teacherOk' => true, 'spendable' => true], $objectIds);
        $point = $encoder->point($planet->getPlayer()->getId(), $account, ['planet' => $planet, 'research' => false, 'candidates' => $rows, 'teacher' => null], 'parity');

        return ['state' => $point->state, 'cands' => array_map(static fn ($candidate): array => $candidate->features, array_slice($point->candidates, 1))];
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
