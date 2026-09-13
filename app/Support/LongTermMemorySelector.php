<?php

namespace Modules\AI\Support;

use Illuminate\Support\Facades\Log;
use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Domain\Conversation\NativeLongTermMemory;
use Modules\AI\Enums\AiMemoryDriver;

/**
 * Chooses the long-term recall implementation from module configuration.
 *
 * Recall is a driver swap point: the module's facts table stays canonical and the
 * selected implementation only answers retrieval, so swapping one in never changes
 * what is true. Native scoped recall is the fallback for an unrecognised driver name.
 *
 * The arm below is deliberately exhaustive rather than defaulted. A driver added to
 * {@see AiMemoryDriver} without an implementation must fail loudly, because a silent
 * fallback would make a behaviour comparison measure native against itself.
 */
class LongTermMemorySelector
{
    public function resolve(): LongTermMemory
    {
        $configured = (string) config('ai.cognition.memory.driver', AiMemoryDriver::Native->value);
        $driver = AiMemoryDriver::tryFrom($configured);

        if ($driver === null) {
            Log::warning('Unrecognised AI memory driver; using native scoped recall.', ['driver' => $configured]);

            return app(NativeLongTermMemory::class);
        }

        return match ($driver) {
            AiMemoryDriver::Native => app(NativeLongTermMemory::class),
        };
    }
}
