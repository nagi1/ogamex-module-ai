<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Enums\AiThreatResponse;

/**
 * What one inbound hostile fleet asks the account to do, per body it is aimed at.
 *
 * A plan is a decision, not a work item: the account answers with several of these at once, and each
 * is carried out by the planner that owns that order, so a login can place a save, a ferry and a wall
 * for the same attack. `underAttack` is the host's own reading and travels with the responses, because
 * an inbound a probe alone sent is a threat the account looks at but moves nothing for.
 */
final readonly class ThreatResponsePlan
{
    /**
     * @param array<int, list<AiThreatResponse>> $responses the responses per own planet id
     * @param array<int, QueueableTransfer> $evacuations the ferry each evacuating body flies, already planned
     */
    public function __construct(public bool $underAttack, public array $responses = [], public array $evacuations = [])
    {
    }

    /** Whether this body answers the inbound with this response. */
    public function holds(int $planetId, AiThreatResponse $kind): bool
    {
        return in_array($kind, $this->responses[$planetId] ?? [], true);
    }

    /** Whether any body of the account answers the inbound with this response. */
    public function holdsAnywhere(AiThreatResponse $kind): bool
    {
        return $this->planets($kind) !== [];
    }

    /** @return list<int> the bodies that answer with this response, in planet order */
    public function planets(AiThreatResponse $kind): array
    {
        $planetIds = [];

        foreach ($this->responses as $planetId => $kinds) {
            if (in_array($kind, $kinds, true)) {
                $planetIds[] = $planetId;
            }
        }

        return $planetIds;
    }

    /** The ferry a body that carries its stock off flies, or null when that body keeps what it holds. */
    public function evacuation(int $planetId): ?QueueableTransfer
    {
        return $this->evacuations[$planetId] ?? null;
    }
}
