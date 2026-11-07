<?php

namespace Modules\AI\Domain\Research;

use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\GameObjects\Models\Enums\GameObjectType;
use OGame\Services\ObjectService;

/**
 * The highest level of a research worth having, read from the catalogue (gate 1: no technology is named).
 *
 * A research the catalogue prices in energy alone produces nothing and makes nothing stronger by itself: it is bought
 * only because other objects require it. Its useful levels end at the highest level any catalogue requirement asks of
 * it (WIK-175, WIK-139: one level unlocks everything that needs it; further levels only cost the energy of thousands
 * of solar satellites, the cause of the millions of satellites per planet in the 5 Nov late-game runs). A research with
 * a mined price has no ceiling here.
 */
final class ResearchCeiling
{
    /** @var array<string, int>|null the highest required level per machine name, over the whole catalogue */
    private static array|null $required = null;

    public function of(GameObject $object): int|null
    {
        if ($object->type !== GameObjectType::Research || !$this->energyOnly($object)) {
            return null;
        }

        return self::requiredLevels()[$object->machine_name] ?? 0;
    }

    /** Whether one more level than $level is still useful. */
    public function allows(GameObject $object, int $level): bool
    {
        $ceiling = $this->of($object);

        return $ceiling === null || $level < $ceiling;
    }

    private function energyOnly(GameObject $object): bool
    {
        $price = $object->price->resources;

        return $price->metal->get() <= 0 && $price->crystal->get() <= 0 && $price->deuterium->get() <= 0 && $price->energy->get() > 0;
    }

    /** @return array<string, int> */
    private static function requiredLevels(): array
    {
        if (self::$required !== null) {
            return self::$required;
        }

        $required = [];
        foreach (ObjectService::getObjects() as $object) {
            foreach ($object->requirements as $requirement) {
                $required[$requirement->object_machine_name] = max($required[$requirement->object_machine_name] ?? 0, $requirement->level);
            }
        }

        return self::$required = $required;
    }
}
