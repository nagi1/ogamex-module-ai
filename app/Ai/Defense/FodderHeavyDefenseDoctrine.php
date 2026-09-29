<?php

declare(strict_types=1);

namespace Modules\AI\Defense;

/**
 * The source's Fodder Heavy wall as a value object: the fodder count in, the target wall out.
 *
 * The page states ratios and never a silo level, so "maximum ABMs" stays a flag the planner
 * resolves against the real silo instead of a number invented here.
 *
 * The chained equality N == 5 * heavyLasers == 20 * gaussCannons == 20 * ionCannons ==
 * 50 * plasmaTurrets holds only when N is a multiple of 100 (lcm(5, 20, 50)); at any other
 * fodder count the per-fodder ratio wins and each count truncates, because a wall is built from
 * whole units and half a cannon cannot be built.
 *
 * The page also names rocket launchers as the fodder the wall is built from, so the doctrine
 * carries the tier membership and the planner carries the build order. Without the tier a planner
 * can only score rocket launchers on attack per cost like any other unit, which is exactly how a
 * wall ends up full of turrets and empty of the cheap losses-absorbers that make it a wall.
 */
final readonly class FodderHeavyDefenseDoctrine
{
    /**
     * The unit the wall's fodder is built from. It is public because the build order belongs to
     * the planner, not to this value object.
     */
    public const FODDER_UNIT = 'rocket_launcher';

    public const HEAVY_LASER = 'heavy_laser';

    public const GAUSS_CANNON = 'gauss_cannon';

    public const ION_CANNON = 'ion_cannon';

    public const PLASMA_TURRET = 'plasma_turret';

    public const SMALL_SHIELD_DOME = 'small_shield_dome';

    public const LARGE_SHIELD_DOME = 'large_shield_dome';

    private const FODDER_PER_HEAVY_LASER = 5;

    private const FODDER_PER_GAUSS_CANNON = 20;

    private const FODDER_PER_ION_CANNON = 20;

    private const FODDER_PER_PLASMA_TURRET = 50;

    private const SHIELD_DOME_COUNT = 1;

    private function __construct(
        public int $fodder,
        public int $heavyLasers,
        public int $gaussCannons,
        public int $ionCannons,
        public int $plasmaTurrets,
        public int $smallShieldDomes,
        public int $largeShieldDomes,
        public bool $antiBallisticMissilesMaximized,
    ) {
    }

    public static function forFodder(int $fodder): self
    {
        return new self(
            fodder: $fodder,
            heavyLasers: intdiv($fodder, self::FODDER_PER_HEAVY_LASER),
            gaussCannons: intdiv($fodder, self::FODDER_PER_GAUSS_CANNON),
            ionCannons: intdiv($fodder, self::FODDER_PER_ION_CANNON),
            plasmaTurrets: intdiv($fodder, self::FODDER_PER_PLASMA_TURRET),
            smallShieldDomes: self::SHIELD_DOME_COUNT,
            largeShieldDomes: self::SHIELD_DOME_COUNT,
            antiBallisticMissilesMaximized: true,
        );
    }

    /**
     * The tier the wall is bought with: for this doctrine the fodder count is the rocket launcher
     * count, unit for unit, exactly as the page states it.
     *
     * @return array<string, int> unit key => target count
     */
    public function fodderTier(): array
    {
        return [self::FODDER_UNIT => $this->fodder];
    }

    /**
     * The units the fodder buy, in the order the wall thickens.
     *
     * @return array<string, int> unit key => target count
     */
    public function supportTier(): array
    {
        return [
            self::HEAVY_LASER => $this->heavyLasers,
            self::GAUSS_CANNON => $this->gaussCannons,
            self::ION_CANNON => $this->ionCannons,
            self::PLASMA_TURRET => $this->plasmaTurrets,
            self::SMALL_SHIELD_DOME => $this->smallShieldDomes,
            self::LARGE_SHIELD_DOME => $this->largeShieldDomes,
        ];
    }
}
