<?php

namespace Modules\AI\Domain\Decision;

use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Services\ObjectService;

/**
 * Whether the building queue is the right place for a host object.
 *
 * The module plans against the host's catalogue, and the catalogue holds ships and technologies beside
 * buildings, so a planner that offers a production building has to be able to say which of them the
 * building queue will accept. That answer is the host's, asked here once rather than restated as a
 * list of names in every planner that needs it.
 */
final class BuildingQueueObject
{
    public static function accepts(string $machineName): bool
    {
        $type = ObjectService::getObjectByMachineName($machineName)->type;

        return in_array($type, [GameObjectType::Building, GameObjectType::Station], true);
    }
}
