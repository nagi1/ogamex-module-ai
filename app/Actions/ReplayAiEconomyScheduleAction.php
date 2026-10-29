<?php

namespace Modules\AI\Actions;

use Exception;
use Illuminate\Support\Facades\Date;
use Modules\AI\Domain\Choice\EconomyChoiceEncoder;
use Modules\AI\Domain\Decision\BuildCandidate;
use Modules\AI\Support\SimulatedTime;
use OGame\Models\BuildingQueue;
use OGame\Models\ResearchQueue;
use OGame\Models\UnitQueue;
use OGame\Services\BuildingQueueService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\ResearchQueueService;
use OGame\Services\SettingsService;
use OGame\Services\UnitQueueService;

/**
 * Training only (plan/rl, ogsim): plays a build order the fast simulator produced on a real host planet, through
 * the host's own queue and clock, and reports what the host had at every step. The host is the referee: any gap
 * between the two is a simulator error.
 */
class ReplayAiEconomyScheduleAction
{
    /**
     * @param  list<array{start: float, id: int, end: float, stock: list<float>, decision?: float, queue?: string}>  $steps  seconds from the planet's last update
     * @param  list<int>  $objectIds  the catalogue's objects in its order; a step with a `decision` also reports the encoder's features then
     * @param  list<int>  $researchObjectIds  the catalogue's researches in its order, offered at a research step's `decision`
     * @param  list<int>  $yardBlockedBy  objects whose upgrade makes the shipyard page refuse an order
     * @return array{steps: list<array<string, mixed>>, final: array{stock: list<float>, levels: array<int, int>}, names: array{state: list<string>, candidate: list<string>}}
     */
    public function handle(PlanetService $planet, array $steps, int $horizonSeconds, array $objectIds = [], array $researchObjectIds = [], array $yardBlockedBy = []): array
    {
        $origin = $planet->getUpdatedAt()->timestamp;
        $queue = app(BuildingQueueService::class);
        $rows = [];
        $features = [];
        // A queue can decide while another queue's pick is still saving up, so decisions and starts are replayed in clock order.
        foreach ($this->timeline($steps) as [$kind, $index]) {
            $step = $steps[$index];
            $research = ($step['queue'] ?? 'building') === 'research';
            if ($kind === 'decision') {
                $features[$index] = $this->features($planet, $origin + (int) $step['decision'], $research ? $researchObjectIds : $objectIds, $research);

                continue;
            }
            $this->at($planet, $origin + (int) $step['start']);
            if (($step['queue'] ?? 'building') === 'touch') {
                // W14: a login that started nothing still loads the page, which settles the planet.
                continue;
            }
            $stock = $this->stock($planet);
            if (($step['queue'] ?? 'building') === 'units') {
                // W14: a yard order is compared by what the host queued, not by a duration.
                $rows[$index] = ['id' => (int) $step['id'], 'start' => (int) $step['start'], 'queue' => 'units', 'sim_stock' => $step['stock'] ?? null,
                    'host_stock' => $stock, 'sim_queued' => (int) $step['queued'], 'host_queued' => $this->order($planet, $step, $yardBlockedBy)];

                continue;
            }
            $refusal = null;
            try {
                $duration = $this->enqueue($planet, $queue, $step);
            } catch (Exception $refused) {
                // The host refusing a start is a difference to report, not a reason to stop the replay.
                [$duration, $refusal] = [null, $refused->getMessage()];
            }
            $rows[$index] = [
                'id' => (int) $step['id'],
                'start' => (int) $step['start'],
                'sim_stock' => $step['stock'],
                'host_stock' => $stock,
                'sim_seconds' => (int) $step['end'] - (int) $step['start'],
                'host_seconds' => $duration,
                'host_features' => null,
                'sim_state' => $step['sim_state'] ?? null,
                'sim_cands' => $step['sim_cands'] ?? null,
                'sim_legal' => $step['sim_legal'] ?? null,
                'host_refusal' => $refusal,
            ];
        }
        ksort($rows);
        foreach ($rows as $index => $row) {
            $rows[$index]['host_features'] = $features[$index] ?? null;
        }
        $rows = array_values($rows);
        $this->at($planet, $origin + $horizonSeconds);
        $levels = [];
        foreach ([...ObjectService::getBuildingObjects(), ...ObjectService::getStationObjects()] as $object) {
            $levels[$object->id] = $planet->getObjectLevel($object->machine_name);
        }
        foreach (ObjectService::getResearchObjects() as $object) {
            $levels[$object->id] = $planet->getPlayer()->getResearchLevel($object->machine_name);
        }

        $units = [];
        foreach ([...ObjectService::getShipObjects(), ...ObjectService::getDefenseObjects()] as $object) {
            $units[$object->id] = $planet->getObjectAmount($object->machine_name);
        }

        return [
            'steps' => $rows,
            'final' => ['stock' => $this->stock($planet), 'levels' => $levels, 'units' => $units],
            'names' => ['state' => EconomyChoiceEncoder::stateNames(), 'candidate' => EconomyChoiceEncoder::candidateNames()],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array{0: string, 1: int}> every start and every decision by time; a step decides before it starts, after the steps ahead of it have
     */
    private function timeline(array $steps): array
    {
        $events = [];
        foreach ($steps as $index => $step) {
            $events[] = [(int) $step['start'], 2 * $index + 1, $index, 'start'];
            if (isset($step['decision'])) {
                $events[] = [(int) $step['decision'], 2 * $index, $index, 'decision'];
            }
        }
        usort($events, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(static fn (array $event): array => [$event[3], $event[2]], $events);
    }

    /**
     * What the encoder emits for every listed object at the moment the free queue asks, in full width.
     *
     * @param  list<int>  $objectIds
     * @return array{state: list<float>, cands: list<list<float>>}
     */
    private function features(PlanetService $planet, int $timestamp, array $objectIds, bool $research): array
    {
        $this->at($planet, $timestamp);
        $encoder = app(EconomyChoiceEncoder::class);
        $names = EconomyChoiceEncoder::stateNames();
        $account = array_fill(0, count($names) - count($encoder->planetState($planet, $research)), 0.0);
        $account[array_search('speed_economy', $names, true)] = log(max(1, app(SettingsService::class)->economySpeed()));
        $rows = array_map(static fn (int $id): array => ['candidate' => new BuildCandidate($id, 'parity'), 'pass' => 'routine', 'legal' => true, 'teacherOk' => true, 'spendable' => true], $objectIds);
        $point = $encoder->point($planet->getPlayer()->getId(), $account, ['planet' => $planet, 'research' => $research, 'candidates' => $rows, 'teacher' => null], 'parity');

        return ['state' => $point->state, 'cands' => array_map(static fn ($candidate): array => $candidate->features, array_slice($point->candidates, 1))];
    }

    /**
     * Queues the step on the host's building or research queue and returns the seconds the host gave it, or null
     * when the host did not start it.
     *
     * @param  array<string, mixed>  $step
     */
    private function enqueue(PlanetService $planet, BuildingQueueService $buildings, array $step): ?int
    {
        if (($step['queue'] ?? 'building') === 'research') {
            // The research queue pays from the player's own copy of the planet, which a long replay leaves stale; a
            // request in the game loads it fresh.
            $planet->getPlayer()->planets->getById($planet->getPlanetId())->reloadPlanet();
            app(ResearchQueueService::class)->add($planet->getPlayer(), $planet, (int) $step['id']);
            $item = ResearchQueue::query()->where('planet_id', $planet->getPlanetId())->orderByDesc('id')->first();

            return $item?->building ? (int) $item->time_duration : null;
        }
        $buildings->add($planet, (int) $step['id']);
        $item = BuildingQueue::query()->where('planet_id', $planet->getPlanetId())->orderByDesc('id')->first();

        return $item?->building ? (int) $item->time_duration : null;
    }

    /**
     * Places a yard order the way the shipyard page does and returns the amount the host queued.
     *
     * @param  array<string, mixed>  $step
     * @param  list<int>  $yardBlockedBy
     */
    private function order(PlanetService $planet, array $step, array $yardBlockedBy): int
    {
        foreach ($yardBlockedBy as $id) {
            if ($planet->isBuildingObject(ObjectService::getObjectById($id)->machine_name)) {
                return 0;
            }
        }
        $last = (int) UnitQueue::query()->where('planet_id', $planet->getPlanetId())->max('id');
        app(UnitQueueService::class)->add($planet, (int) $step['id'], (int) $step['amount']);
        $item = UnitQueue::query()->where('planet_id', $planet->getPlanetId())->where('id', '>', $last)->first();

        return $item === null ? 0 : (int) $item->object_amount;
    }

    /** Moves the host clock to the timestamp, stopping at each research that finishes on the way so its level counts from then on. */
    private function at(PlanetService $planet, int $timestamp): void
    {
        while (($end = $this->nextResearchEnd($planet, $timestamp)) !== null) {
            $this->settle($planet, $end);
        }
        $this->settle($planet, $timestamp);
    }

    private function settle(PlanetService $planet, int $timestamp): void
    {
        SimulatedTime::freezeAt(Date::createFromTimestamp($timestamp));
        $planet->update();
        $planet->getPlayer()->updateResearchQueue();
        $planet->update();
    }

    private function nextResearchEnd(PlanetService $planet, int $until): ?int
    {
        $end = ResearchQueue::query()->where('planet_id', $planet->getPlanetId())->where('building', 1)->where('processed', 0)->where('canceled', 0)
            ->where('time_end', '<', $until)->min('time_end');

        return $end === null ? null : (int) $end;
    }

    /** @return list<float> */
    private function stock(PlanetService $planet): array
    {
        return [$planet->metal()->get(), $planet->crystal()->get(), $planet->deuterium()->get()];
    }
}
