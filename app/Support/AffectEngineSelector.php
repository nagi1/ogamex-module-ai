<?php

namespace Modules\AI\Support;

use Illuminate\Support\Facades\Log;
use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Domain\Cognition\NativeAffectEngine;
use Modules\AI\Enums\AiCognitionDriver;
use Modules\AI\Infrastructure\Cognition\FatimaAffectEngine;
use Modules\AI\Infrastructure\Cognition\FatimaCognitionSession;

/**
 * Chooses the affect engine from module configuration.
 *
 * The native engine is the permanent fallback: an unrecognised driver name is reported
 * and ignored rather than silently removing appraisal, and the driver is always built
 * around its native counterpart so it can degrade per call.
 */
class AffectEngineSelector
{
    public function resolve(): AffectEngine
    {
        $configured = (string) config('ai.cognition.driver', AiCognitionDriver::Native->value);
        $driver = AiCognitionDriver::tryFrom($configured);

        if ($driver === null) {
            Log::warning('Unrecognised AI cognition driver; using the native affect engine.', ['driver' => $configured]);
        }

        return match ($driver) {
            AiCognitionDriver::Fatima => app()->makeWith(FatimaAffectEngine::class, [
                'fallback' => app(NativeAffectEngine::class),
                'session' => app(FatimaCognitionSession::class),
            ]),
            default => app(NativeAffectEngine::class),
        };
    }
}
