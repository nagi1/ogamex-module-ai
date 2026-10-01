<?php

namespace Modules\AI\Support;

use RuntimeException;

/**
 * The source fixes a solar system at 15 colonizable planet slots plus one Outer Space slot
 * that is never colonizable. Those numbers live in resources/behavior/solar_system_slots.json
 * so a universe with a different layout is changed there instead of here.
 */
final class SolarSystemSlot
{
    private const LAYOUT_FILE = '/resources/behavior/solar_system_slots.json';

    private const FIRST_SLOT_KEY = 'first_slot';
    private const LAST_COLONIZABLE_SLOT_KEY = 'last_colonizable_slot';
    private const OUTER_SPACE_SLOT_KEY = 'outer_space_slot';

    /** @var array<string, mixed>|null */
    private static ?array $layout = null;

    public static function firstSlot(): int
    {
        return self::intValue(self::FIRST_SLOT_KEY);
    }

    public static function lastColonizableSlot(): int
    {
        return self::intValue(self::LAST_COLONIZABLE_SLOT_KEY);
    }

    public static function outerSpaceSlot(): int
    {
        return self::intValue(self::OUTER_SPACE_SLOT_KEY);
    }

    public static function isColonizable(int $slot): bool
    {
        return $slot >= self::firstSlot() && $slot <= self::lastColonizableSlot();
    }

    public static function isOuterSpace(int $slot): bool
    {
        return $slot === self::outerSpaceSlot();
    }

    public static function isKnownSlot(int $slot): bool
    {
        return self::isColonizable($slot) || self::isOuterSpace($slot);
    }

    /**
     * @return array<string, mixed>
     */
    private static function layout(): array
    {
        if (self::$layout !== null) {
            return self::$layout;
        }

        $path = dirname(__DIR__, 2) . self::LAYOUT_FILE;

        if (! is_file($path)) {
            throw new RuntimeException('Solar system slot layout is missing: ' . $path);
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Solar system slot layout is not a JSON object: ' . $path);
        }

        self::$layout = $decoded;

        return self::$layout;
    }

    private static function intValue(string $key): int
    {
        $layout = self::layout();

        if (! array_key_exists($key, $layout) || ! is_int($layout[$key])) {
            throw new RuntimeException('Solar system slot layout needs an integer "' . $key . '".');
        }

        return $layout[$key];
    }
}
