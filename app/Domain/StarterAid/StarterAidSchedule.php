<?php

namespace Modules\AI\Domain\StarterAid;

use DateTimeImmutable;
use Symfony\Component\Yaml\Yaml;

/**
 * Which registration day an account is on and whether that day pays a starter-aid reward. The
 * registration day itself is day 1.
 */
final class StarterAidSchedule
{
    private const BEHAVIOR_FILE = '/resources/behavior/starter-aid.yaml';

    public function dayOf(DateTimeImmutable $registeredAt, DateTimeImmutable $now): int
    {
        return (int) $registeredAt->setTime(0, 0)->diff($now->setTime(0, 0))->format('%a') + 1;
    }

    public function collectable(DateTimeImmutable $registeredAt, DateTimeImmutable $now): bool
    {
        $policy = Yaml::parseFile(dirname(__DIR__, 3) . self::BEHAVIOR_FILE);
        $day = $this->dayOf($registeredAt, $now);

        return $day >= (int) $policy['first_day'] && $day <= (int) $policy['last_day'];
    }
}
