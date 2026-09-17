<?php

namespace Modules\AI\Enums;

/**
 * How the optional external drivers are used relative to the native engines.
 *
 * `native` forces every contract onto its native implementation and ignores the driver
 * settings, which is the baseline an ablation compares against without unsetting a driver.
 * `external` swaps the selected driver in with native as the per-call fallback, which is the
 * driver-swap comparison. `hybrid` runs native always and the selected driver alongside it
 * when the driver is healthy and can contribute, then a per-contract combiner merges the two
 * answers — this is the default.
 */
enum AiCognitionMode: string
{
    case Native = 'native';
    case External = 'external';
    case Hybrid = 'hybrid';
}
