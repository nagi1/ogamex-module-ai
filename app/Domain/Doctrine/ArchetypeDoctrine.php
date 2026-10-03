<?php

namespace Modules\AI\Domain\Doctrine;

use Modules\AI\Domain\Decision\BuildCandidate;
use Modules\AI\Enums\AiArchetype;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * An archetype's written doctrine (`resources/doctrine/<archetype>.yaml`): the opening build order, the
 * research path, and the fleet and defence templates (architecture step 4).
 *
 * The files name objects, as data (Gate 1 as amended 3 Oct 2026). This class names none: an entry the host
 * does not have is skipped, and when a list is done or a template holds nothing the host can build, the
 * caller's generic rule decides, so a modded object stays reachable.
 */
class ArchetypeDoctrine
{
    private const DIRECTORY = '/resources/doctrine/';

    /** @var array<string, array<string, mixed>> */
    private static array $files = [];

    /**
     * The next opening step this planet has not reached, as a building candidate: the build list runs in
     * order, a step already done is skipped, and research steps are left to the research path.
     *
     * @return list<BuildCandidate>
     */
    public function openingStep(AiArchetype $archetype, PlanetService $planet): array
    {
        foreach ($this->steps($archetype, 'opening') as [$machineName, $level]) {
            $object = $this->object($machineName);
            if ($object === null || $object->type === GameObjectType::Research) {
                continue;
            }
            if (!ObjectService::objectValidPlanetType($machineName, $planet)) {
                continue;
            }
            if ($planet->getObjectLevel($machineName) >= $level) {
                continue;
            }

            return [new BuildCandidate($object->id, 'doctrine:opening:' . $machineName . ':' . $level)];
        }

        return [];
    }

    /**
     * The next technology on the archetype's path the account has not reached (the opening's research rows
     * first, then the path), as a candidate for the lab.
     *
     * @return list<BuildCandidate>
     */
    public function researchStep(AiArchetype $archetype, PlayerService $player): array
    {
        foreach ([...$this->steps($archetype, 'opening'), ...$this->steps($archetype, 'research_path')] as [$machineName, $level]) {
            $object = $this->object($machineName);
            if ($object === null || $object->type !== GameObjectType::Research) {
                continue;
            }
            if ($player->getResearchLevel($machineName) >= $level) {
                continue;
            }

            return [new BuildCandidate($object->id, 'doctrine:research:' . $machineName . ':' . $level)];
        }

        return [];
    }

    /**
     * The template's hull furthest below its share of what the account owns, among the hulls `$buildable`
     * accepts; null when the archetype has no template or nothing in it can be built.
     *
     * @param array<string, int> $owned machine name => units the account owns
     * @param callable(string): bool $buildable
     */
    public function nextTemplateUnit(AiArchetype $archetype, string $template, array $owned, callable $buildable): ?string
    {
        $shares = $this->file($archetype)[$template] ?? [];
        if (!is_array($shares) || $shares === []) {
            return null;
        }

        $total = max(1, array_sum(array_map(static fn (string $name): int => $owned[$name] ?? 0, array_keys($shares))));
        $weight = max(1, array_sum($shares));
        $best = null;
        $bestGap = -INF;

        foreach ($shares as $machineName => $share) {
            if ($this->object((string) $machineName) === null || !$buildable((string) $machineName)) {
                continue;
            }

            $gap = ((float) $share / $weight) - (($owned[$machineName] ?? 0) / $total);
            if ($gap > $bestGap) {
                $best = (string) $machineName;
                $bestGap = $gap;
            }
        }

        return $best;
    }

    /** @return list<array{0: string, 1: int}> */
    private function steps(AiArchetype $archetype, string $key): array
    {
        $rows = $this->file($archetype)[$key] ?? [];
        $steps = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row[0], $row[1])) {
                $steps[] = [(string) $row[0], (int) $row[1]];
            }
        }

        return $steps;
    }

    private function object(string $machineName): ?object
    {
        try {
            return ObjectService::getObjectByMachineName($machineName);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function file(AiArchetype $archetype): array
    {
        $name = match ($archetype) {
            AiArchetype::Trader => 'miner',
            AiArchetype::Casual => 'hybrid',
            default => strtolower($archetype->name),
        };

        if (!array_key_exists($name, self::$files)) {
            $path = dirname(__DIR__, 3) . self::DIRECTORY . $name . '.yaml';
            $parsed = is_file($path) ? Yaml::parseFile($path) : [];
            self::$files[$name] = is_array($parsed) ? $parsed : [];
        }

        return self::$files[$name];
    }
}
