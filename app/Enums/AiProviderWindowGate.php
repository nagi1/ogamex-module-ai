<?php

namespace Modules\AI\Enums;

/**
 * When a routing rung is worth using, relative to its vendor's own peak window.
 *
 * The gate exists because a vendor's price moves with the clock: the same model is half price in
 * its off-peak hours and twice price in its peak ones, so "prefer this vendor" is a statement
 * about time rather than a rank.
 */
enum AiProviderWindowGate: string
{
    case Any = 'any';
    case Peak = 'peak';
    case OffPeak = 'off_peak';

    public function allows(bool $isPeak): bool
    {
        return match ($this) {
            self::Any => true,
            self::Peak => $isPeak,
            self::OffPeak => !$isPeak,
        };
    }
}
