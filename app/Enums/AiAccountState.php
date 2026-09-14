<?php

namespace Modules\AI\Enums;

/** What an account currently is, as the host reports it. */
enum AiAccountState: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Empty = 'empty';
    case Final = 'final';

    /**
     * Whether the account keeps waking.
     *
     * A suspended account does: a ban and a vacation both end, and nothing else
     * would wake the chain when they do. An account with no planets and one the
     * host no longer has do not: neither has anything to come back to, so the
     * chain stops instead of deciding nothing forever.
     */
    public function schedules(): bool
    {
        return match ($this) {
            self::Active, self::Suspended => true,
            self::Empty, self::Final => false,
        };
    }
}
