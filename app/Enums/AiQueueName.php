<?php

namespace Modules\AI\Enums;

/**
 * Module-owned queue lanes.
 *
 * The module registers these with Horizon from its service provider while it is
 * enabled, so disabling the module removes every AI queue, supervisor, wait and
 * worker with no AI-specific configuration left in the host.
 */
enum AiQueueName: string
{
    /** Deterministic AI work: sessions, building, social and experience jobs. */
    case Ai = 'ai';

    /** Bounded foreground language generation; one provider request per sealed reply. */
    case AiLanguage = 'ai-language';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $queue): string => $queue->value, self::cases());
    }
}
