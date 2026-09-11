<?php

namespace Modules\AI\Enums;

/** Host object identifiers with an AI-specific queue safety rule. */
enum AiBuildingMachineName: string
{
    case Shipyard = 'shipyard';
    case NanoFactory = 'nano_factory';

    /** @return array<int, string> */
    public static function unitQueueBlockers(): array
    {
        return array_map(static fn (self $building): string => $building->value, self::cases());
    }
}
