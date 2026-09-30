<?php

namespace Modules\AI\Domain\Operability;

/**
 * Retention health read off each windowed table's oldest row, so a prune that has stopped
 * running announces itself as a row older than the window it is supposed to be inside.
 *
 * The account's own storage health is reported as two verdicts derived from the observed
 * snapshot: whether anything is being produced at all, and whether a store sits at its capacity.
 * Observed resource deltas ride along as observations only -- a Trader call is an exchange with an
 * NPC, not the account's own output, so it never counts as production and never fills a store.
 *
 * @property list<array{model:string, count:int, retentionDays:int, oldestAgeDays:int|null, behind:bool}> $tables
 */
readonly class AiStorageHealthOverview
{
    /**
     * Delta kinds that move resources between the account and an NPC instead of producing them.
     *
     * @var list<string>
     */
    public const EXTERNAL_DELTA_KINDS = ['trader_exchange'];

    /** Some resource has a positive hourly rate: the store keeps filling on its own. */
    public const PRODUCTION_SURPLUS = 'surplus';

    /** No resource has a positive hourly rate: the store only moves because someone moved it. */
    public const PRODUCTION_IDLE = 'idle';

    /** A resource store holds at least its capacity. */
    public const OVERFLOW_FULL = 'full';

    /** Every resource store has room left. */
    public const OVERFLOW_ROOM = 'room';

    /**
     * @param list<array{model:string, count:int, retentionDays:int, oldestAgeDays:int|null, behind:bool}> $tables
     * @param list<array{kind:string, external:bool, priced:bool, ratio:null, fee:null, metal:int, crystal:int, deuterium:int}> $deltas
     */
    public function __construct(
        public array $tables = [],
        public array $deltas = [],
        public string $productionVerdict = self::PRODUCTION_IDLE,
        public string $overflowVerdict = self::OVERFLOW_ROOM,
    ) {
    }
}
