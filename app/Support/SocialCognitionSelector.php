<?php

namespace Modules\AI\Support;

use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\NativeSocialCognition;
use Modules\AI\Enums\AiCognitionDriver;
use Modules\AI\Enums\AiCognitionMode;
use Modules\AI\Infrastructure\Cognition\FatimaCognitionSession;
use Modules\AI\Infrastructure\Cognition\FatimaSocialCognition;
use Modules\AI\Infrastructure\Cognition\HybridSocialCognition;

/**
 * Chooses the social-cognition engine from module configuration.
 *
 * This resolves the same driver setting and the same shared session as
 * {@see AffectEngineSelector}, so both contracts advance one integrated character
 * state. Selecting them independently would let two character states diverge.
 */
class SocialCognitionSelector
{
    public function resolve(): SocialCognition
    {
        $driver = AiCognitionDriver::tryFrom((string) config('ai.cognition.driver', AiCognitionDriver::Native->value));
        $mode = AiCognitionMode::tryFrom((string) config('ai.cognition.mode', AiCognitionMode::External->value));

        // The affect selector already reports an unrecognised driver name, so this
        // selector stays silent to avoid logging the same misconfiguration twice.
        if ($mode === AiCognitionMode::Native || $driver !== AiCognitionDriver::Fatima) {
            return app(NativeSocialCognition::class);
        }

        if ($mode === AiCognitionMode::Hybrid) {
            return app()->makeWith(HybridSocialCognition::class, [
                'native' => app(NativeSocialCognition::class),
                'driver' => $this->fatima(),
            ]);
        }

        return $this->fatima();
    }

    private function fatima(): SocialCognition
    {
        return app()->makeWith(FatimaSocialCognition::class, [
            'fallback' => app(NativeSocialCognition::class),
            'session' => app(FatimaCognitionSession::class),
        ]);
    }
}
