<?php

namespace Modules\AI\Actions;

/**
 * The construction queue rule: a build order may only be committed against a queue state the
 * account actually reported. Absent or empty evidence is an unknown state, never a free queue,
 * so nothing is derived from it -- no wait time, no slot count, no queue limit.
 */
class GuardConstructionQueue
{
    public const DECISION_COMMIT = 'commit';

    public const DECISION_HOLD = 'hold';

    public const REASON_CONSTRUCTION_QUEUE_UNKNOWN = 'construction_queue_unknown';

    /**
     * @return array{decision: string, reason?: string, snapshot?: array<mixed>}
     */
    public function handle(mixed $snapshot): array
    {
        // An empty payload carries no evidence about the queue, so it is unknown as well.
        if (! is_array($snapshot) || $snapshot === []) {
            return [
                'decision' => self::DECISION_HOLD,
                'reason' => self::REASON_CONSTRUCTION_QUEUE_UNKNOWN,
            ];
        }

        return [
            'decision' => self::DECISION_COMMIT,
            'snapshot' => $snapshot,
        ];
    }
}
