<?php

namespace Modules\AI\Ai\Defence;

/**
 * Plans the defence units a planet may queue.
 *
 * The source states a hard fact: a planet may hold only one Small Shield Dome
 * and only one Large Shield Dome, and a dome is a single unit of fodder rather
 * than an ability that multiplies anything else.
 */
final class QueueableDefencePlanner
{
    public const SMALL_SHIELD_DOME = 'SmallShieldDome';

    public const LARGE_SHIELD_DOME = 'LargeShieldDome';

    /**
     * Defaults only. The set is injected so a universe that allows more than one
     * dome can relax the guard without editing this class.
     */
    private const SINGLE_INSTANCE_DEFENCES = [
        self::SMALL_SHIELD_DOME,
        self::LARGE_SHIELD_DOME,
    ];

    /** @var list<string> */
    private readonly array $singleInstanceDefences;

    /**
     * @param list<string> $singleInstanceDefences
     */
    public function __construct(array $singleInstanceDefences = self::SINGLE_INSTANCE_DEFENCES)
    {
        $this->singleInstanceDefences = array_values($singleInstanceDefences);
    }

    /**
     * @param array<string, int> $built     completed units per defence type
     * @param array<string, int> $queued    units per defence type already waiting in the build queue
     * @param list<string>       $requested defence types the caller wants to queue
     *
     * @return list<string> the defence types that may be queued
     */
    public function plan(array $built, array $queued, array $requested): array
    {
        $queue = [];

        foreach ($requested as $defence) {
            if ($this->isSingleInstance($defence) && $this->alreadyPresent($defence, $built, $queued, $queue)) {
                continue;
            }

            $queue[] = $defence;
        }

        return $queue;
    }

    /**
     * One unit of fodder per unit, domes included: a dome never multiplies the
     * fodder value of the rest of the planet's defences.
     *
     * @param array<string, int> $defences units per defence type
     */
    public function fodderUnits(array $defences): int
    {
        $units = 0;

        foreach ($defences as $count) {
            $units += $count;
        }

        return $units;
    }

    private function isSingleInstance(string $defence): bool
    {
        return in_array($defence, $this->singleInstanceDefences, true);
    }

    /**
     * @param array<string, int> $built
     * @param array<string, int> $queued
     * @param list<string>       $queue
     */
    private function alreadyPresent(string $defence, array $built, array $queued, array $queue): bool
    {
        return ($built[$defence] ?? 0) > 0
            || ($queued[$defence] ?? 0) > 0
            || in_array($defence, $queue, true);
    }
}
