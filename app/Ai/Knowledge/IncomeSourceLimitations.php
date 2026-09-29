<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Knowledge;

/**
 * The income sources WIK-016 enumerates: line 12 Mining, line 16 Attacking.
 */
enum AiIncomeSource: string
{
    case Mining = 'mining';

    case Attacking = 'attacking';
}

/**
 * WIK-016 income taxonomy, pinned to the raw-snapshot lines it was read from.
 *
 * The snapshot enumerates the sources but states no basic-income amount and no expedition
 * outcome, so those two queries answer with {@see IncomeSourceLimitations::UNKNOWN}. Emitting a
 * number would invent one, and emitting a zero would let a caller plan income WIK-016 never
 * promised.
 */
final class IncomeSourceLimitations
{
    /** WIK-016 line 12. */
    public const MINING_LINE = 12;

    /** WIK-016 line 16. */
    public const ATTACKING_LINE = 16;

    /** Answer for a mechanic the snapshot leaves unstated; deliberately not numeric. */
    public const UNKNOWN = 'unknown';

    /**
     * @return array<string, int> source value => the WIK-016 line that enumerates it
     */
    public static function sources(): array
    {
        return [
            AiIncomeSource::Mining->value => self::MINING_LINE,
            AiIncomeSource::Attacking->value => self::ATTACKING_LINE,
        ];
    }

    /**
     * WIK-016 states no basic-income amount, so no amount can be returned.
     *
     * $source is accepted so the caller asks about a named taxonomy entry rather than a
     * hardcoded one; the answer does not vary because the snapshot states no amount.
     */
    public static function basicIncomeAmount(AiIncomeSource $source): string
    {
        return self::UNKNOWN;
    }

    /**
     * WIK-016 states no expedition outcome, so no outcome can be returned.
     */
    public static function expeditionOutcome(AiIncomeSource $source): string
    {
        return self::UNKNOWN;
    }
}
