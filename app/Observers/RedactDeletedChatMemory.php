<?php

namespace Modules\AI\Observers;

use Modules\AI\Actions\RedactAiMemoryFactsAction;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Models\AiObservation;
use Modules\AI\Support\AiClock;
use OGame\Models\ChatMessage;

class RedactDeletedChatMemory
{
    public function deleted(ChatMessage $chatMessage): void
    {
        AiObservation::query()
            ->where('source_type', AiObservationSource::ChatMessage)
            ->where('source_id', $chatMessage->id)
            ->get()
            ->each(fn (AiObservation $observation) => app(RedactAiMemoryFactsAction::class)->handle(
                $observation->player_id,
                $observation->id,
                app(AiClock::class)->now(),
            ));
    }
}
