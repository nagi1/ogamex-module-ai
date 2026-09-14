<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiStopReason;
use Modules\AI\Models\AiStopCounter;
use Modules\AI\Support\AiClock;

/**
 * Counts one refusal, so a capped population can explain itself later.
 *
 * The counter is per reason, scope and day: a pass that is limited every minute writes one
 * growing row rather than a minute-by-minute log, which is what makes the pilot report
 * readable and keeps the table bounded no matter how loud the reason is.
 */
class RecordAiStopReasonAction
{
    /**
     * The first real scope is the whole universe. A scope string instead of a boolean keeps
     * room for a per-player cap without a second table when one is needed.
     */
    public const SCOPE_UNIVERSE = 'universe';

    public function __construct(private readonly AiClock $clock)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function handle(AiStopReason $reason, array $context): AiStopCounter
    {
        $now = $this->clock->now();
        $counter = AiStopCounter::query()->firstOrCreate(
            [
                'reason' => $reason->value,
                'scope' => self::SCOPE_UNIVERSE,
                'observed_on' => $now->toDateString(),
            ],
            [
                'occurrences' => 0,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ],
        );

        $counter->update([
            'occurrences' => $counter->occurrences + 1,
            'last_context' => $context,
            'last_seen_at' => $now,
        ]);

        return $counter->refresh();
    }
}
