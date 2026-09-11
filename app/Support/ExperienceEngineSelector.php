<?php

namespace Modules\AI\Support;

use Illuminate\Support\Facades\Log;
use Modules\AI\Contracts\ExperienceEngine;
use Modules\AI\Domain\Experience\NativeExperienceEngine;
use Modules\AI\Enums\AiExperienceDriver;
use Modules\AI\Infrastructure\Experience\CbrKitClient;
use Modules\AI\Infrastructure\Experience\CbrKitExperienceEngine;

/**
 * Chooses the experience engine from module configuration.
 *
 * The native engine is the permanent fallback: an unrecognised driver name is
 * reported and ignored rather than silently removing experience evidence from
 * ordinary decisions, and an external driver is always constructed with its native
 * counterpart so it can degrade without losing the ranking.
 */
class ExperienceEngineSelector
{
    public function resolve(): ExperienceEngine
    {
        $configured = (string) config('ai.cognition.experience.driver', AiExperienceDriver::Native->value);
        $driver = AiExperienceDriver::tryFrom($configured);

        if ($driver === null) {
            Log::warning('Unrecognised AI experience driver; using the native engine.', ['driver' => $configured]);
        }

        return match ($driver) {
            AiExperienceDriver::CbrKit => app()->makeWith(CbrKitExperienceEngine::class, [
                'fallback' => app(NativeExperienceEngine::class),
                'client' => app()->makeWith(CbrKitClient::class, [
                    'circuit' => app()->makeWith(DriverCircuitBreaker::class, ['driver' => AiExperienceDriver::CbrKit->value]),
                ]),
            ]),
            default => app(NativeExperienceEngine::class),
        };
    }
}
