<?php

namespace Modules\AI\Domain\Outlaw;

use DateTimeImmutable;
use Symfony\Component\Yaml\Yaml;

/**
 * An outlaw status runs for the documented fixed duration from its application. Recalling the mission
 * that caused it changes nothing, so the expiry is derived from the application time alone.
 */
final class OutlawStatusRule
{
    private const BEHAVIOR_FILE = '/resources/behavior/outlaw.yaml';

    public function expiresAt(DateTimeImmutable $appliedAt): DateTimeImmutable
    {
        $days = (int) Yaml::parseFile(dirname(__DIR__, 3) . self::BEHAVIOR_FILE)['duration_days'];

        return $appliedAt->modify('+' . $days . ' days');
    }

    public function isActive(DateTimeImmutable $appliedAt, DateTimeImmutable $now): bool
    {
        return $now < $this->expiresAt($appliedAt);
    }
}
