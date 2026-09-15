<?php

namespace Modules\AI\Support;

use Illuminate\Support\Facades\Log;
use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Domain\Conversation\NativeLongTermMemory;
use Modules\AI\Enums\AiCognitionMode;
use Modules\AI\Enums\AiMemoryDriver;
use Modules\AI\Infrastructure\Memory\AgentOsClient;
use Modules\AI\Infrastructure\Memory\AgentOsLongTermMemory;

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
        $mode = AiCognitionMode::tryFrom((string) config('ai.cognition.mode', AiCognitionMode::External->value));

        if ($driver === null) {
            Log::warning('Unrecognised AI memory driver; using native scoped recall.', ['driver' => $configured]);

            return app(NativeLongTermMemory::class);
        }

        // The memory adapter already composes native candidates with the driver's ranking, so
        // `external` and `hybrid` resolve to the same implementation; only `native` forces the
        // driver off.
        if ($mode === AiCognitionMode::Native || $driver === AiMemoryDriver::Native) {
            return app(NativeLongTermMemory::class);
        }

        return app()->makeWith(AgentOsLongTermMemory::class, [
            'fallback' => app(NativeLongTermMemory::class),
            'client' => app()->makeWith(AgentOsClient::class, [
                'circuit' => app()->makeWith(DriverCircuitBreaker::class, ['driver' => AiMemoryDriver::AgentOs->value]),
            ]),
        ]);
    }
}
