<?php

namespace Modules\AI\Domain\Rewards;

use Symfony\Component\Yaml\Yaml;

/**
 * Facts of the rewards event. Level thresholds are not stated by the source, so only the tritium a
 * task pays (with the command staff bonus) is computed here.
 */
final class RewardEventPolicy
{
    private const BEHAVIOR_FILE = '/resources/behavior/rewards.yaml';

    /** @return array<string, mixed> */
    private function policy(): array
    {
        return Yaml::parseFile(dirname(__DIR__, 3) . self::BEHAVIOR_FILE);
    }

    public function levels(): int
    {
        return (int) $this->policy()['levels'];
    }

    public function validDuration(int $days): bool
    {
        return in_array($days, $this->policy()['durations_days'], true);
    }

    public function tritium(float $taskTritium, bool $commandStaff): float
    {
        return $commandStaff ? $taskTritium * (1 + (float) $this->policy()['command_staff_tritium_bonus']) : $taskTritium;
    }
}
