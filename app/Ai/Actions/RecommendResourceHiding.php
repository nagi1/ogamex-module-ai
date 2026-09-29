<?php

declare(strict_types=1);

namespace Modules\AI\Actions;

use DateTimeInterface;

/**
 * Recommends hiding a planet's stockpile by spending it into an already-available sink that
 * finishes before the earliest inbound hostile fleet lands.
 *
 * Arrival times and sink completion times are caller-supplied: no domain object surfaces both
 * in one place, and this action owns no threshold of its own -- it neither splits keep/dump
 * nor ranks sinks, because the source states no uncontested number for either.
 */
final class RecommendResourceHiding
{
    /**
     * @param  array<int|string, array{id: int|string, arrival_at: DateTimeInterface|null}>  $inboundHostileFleets
     * @param  array<int|string, array{id: int|string, type: string, completes_at: DateTimeInterface}>  $sinks
     * @return array{sink: array{id: int|string, type: string, completes_at: DateTimeInterface}, amount: int}|null
     */
    public function execute(array $inboundHostileFleets, array $sinks, int $stockpile): ?array
    {
        // Nothing stored means nothing to hide, so there is nothing to recommend.
        if ($stockpile <= 0) {
            return null;
        }

        $arrival = $this->earliestKnownArrival($inboundHostileFleets);

        // Fleets without a known arrival time give nothing to race against.
        if ($arrival === null) {
            return null;
        }

        foreach ($sinks as $sink) {
            // Strictly before: a sink finishing at the landing instant is not finished in time.
            if ($sink['completes_at'] < $arrival) {
                return [
                    'sink' => $sink,
                    'amount' => $stockpile,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, array{id: int|string, arrival_at: DateTimeInterface|null}>  $inboundHostileFleets
     */
    private function earliestKnownArrival(array $inboundHostileFleets): ?DateTimeInterface
    {
        $earliest = null;

        foreach ($inboundHostileFleets as $fleet) {
            if ($fleet['arrival_at'] === null) {
                continue;
            }

            if ($earliest === null || $fleet['arrival_at'] < $earliest) {
                $earliest = $fleet['arrival_at'];
            }
        }

        return $earliest;
    }
}
