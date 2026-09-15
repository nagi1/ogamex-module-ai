<?php

namespace Modules\AI\Support;

use Illuminate\Support\Facades\Log;
use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Domain\Cognition\NativeAffectEngine;
use Modules\AI\Enums\AiCognitionDriver;
use Modules\AI\Enums\AiCognitionMode;
use Modules\AI\Infrastructure\Cognition\FatimaAffectEngine;
use Modules\AI\Infrastructure\Cognition\FatimaCognitionSession;
use Modules\AI\Infrastructure\Cognition\HybridAffectEngine;

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
        $mode = AiCognitionMode::tryFrom((string) config('ai.cognition.mode', AiCognitionMode::External->value));

        if ($driver === null) {
            Log::warning('Unrecognised AI cognition driver; using the native affect engine.', ['driver' => $configured]);
        }

        if ($mode === AiCognitionMode::Native || $driver !== AiCognitionDriver::Fatima) {
            return app(NativeAffectEngine::class);
        }

        if ($mode === AiCognitionMode::Hybrid) {
            return app()->makeWith(HybridAffectEngine::class, [
                'native' => app(NativeAffectEngine::class),
                'driver' => $this->fatima(),
            ]);
        }

        return $this->fatima();
    }

    private function fatima(): AffectEngine
    {
        return app()->makeWith(FatimaAffectEngine::class, [
            'fallback' => app(NativeAffectEngine::class),
            'session' => app(FatimaCognitionSession::class),
        ]);
    }
}
