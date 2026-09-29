<?php

namespace Modules\AI\Ai\ResourceSaving;

/**
 * WIK-027: starting a research project or building earmarks its resources, but only while that
 * queue item is pending. This guard is the one place that answers whether a resource saving is
 * still backed by a pending queue item, so an AI holding a def-stockpile cannot keep treating the
 * earmark as durable after the item completed or was cancelled.
 */
final class EarmarkLimitGuard
{
    private const STATUS_PENDING = 'pending';

    /**
     * @param array{queueItem?: array{status?: string}} $saving
     */
    public function isProtected(array $saving): bool
    {
        // The protection lasts only while the item is pending, so anything else (completed,
        // cancelled, evicted, never queued) is not protected.
        return ($saving['queueItem']['status'] ?? null) === self::STATUS_PENDING;
    }

    /**
     * The spend this saving still authorises: the earmarked resources while the item is pending,
     * nothing at all once the item has left the queue.
     *
     * @param array{resources?: array<string, int>, queueItem?: array{status?: string}} $saving
     * @return array<string, int>
     */
    public function spendPlan(array $saving): array
    {
        if (! $this->isProtected($saving)) {
            return [];
        }

        $plan = [];

        foreach ($saving['resources'] ?? [] as $resource => $amount) {
            $plan[(string) $resource] = (int) $amount;
        }

        return $plan;
    }
}
