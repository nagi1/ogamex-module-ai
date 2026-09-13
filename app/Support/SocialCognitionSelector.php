<?php

namespace Modules\AI\Support;

use Modules\AI\Contracts\SocialCognition;
use Modules\AI\Domain\Conversation\NativeSocialCognition;
use Modules\AI\Enums\AiCognitionDriver;
use Modules\AI\Infrastructure\Cognition\FatimaCognitionSession;
use Modules\AI\Infrastructure\Cognition\FatimaSocialCognition;

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

        // The affect selector already reports an unrecognised driver name, so this
        // selector stays silent to avoid logging the same misconfiguration twice.
        return match ($driver) {
            AiCognitionDriver::Fatima => app()->makeWith(FatimaSocialCognition::class, [
                'fallback' => app(NativeSocialCognition::class),
                'session' => app(FatimaCognitionSession::class),
            ]),
            default => app(NativeSocialCognition::class),
        };
    }
}
