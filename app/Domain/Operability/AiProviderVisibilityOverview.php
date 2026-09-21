<?php

namespace Modules\AI\Domain\Operability;

/**
 * Per-vendor lane visibility. A vendor is a lane, never a persona, so the rows are grouped by
 * provider and never by account.
 *
 * @property bool $configured
 * @property list<array{provider:string, attempts:int, avgLatencyMs:int|null, tokens:int, cost:float}> $vendors
 */
readonly class AiProviderVisibilityOverview
{
    /**
     * @param list<array{provider:string, attempts:int, avgLatencyMs:int|null, tokens:int, cost:float}> $vendors
     */
    public function __construct(public bool $configured = false, public array $vendors = [])
    {
    }
}
