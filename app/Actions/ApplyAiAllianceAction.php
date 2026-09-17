<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Domain\Social\AllianceChoice;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiActionResult;
use OGame\Services\AllianceService;

/**
 * Applies the account to the alliance its tier would actually join, through the host's own
 * application path. The choice is re-read at apply time so the pass and the host state never
 * disagree; the host still decides whether the application is accepted.
 */
class ApplyAiAllianceAction
{
    public function handle(int $playerId): AiActionResult
    {
        $alliance = app(AllianceChoice::class)->choose($playerId);

        if ($alliance === null) {
            return AiActionResult::rejected(AiQueueActionReason::NoSuitableAlliance);
        }

        try {
            app(AllianceService::class)->applyToAlliance($playerId, $alliance->id, $this->applicationMessage($playerId));

            return AiActionResult::succeeded(AiQueueActionReason::AllianceApplied);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }

    private function applicationMessage(int $playerId): string
    {
        $archetype = AiArchetype::tryFrom((int) AiProfile::query()->where('player_id', $playerId)->value('archetype'));

        return match ($archetype) {
            AiArchetype::Miner => 'Active miner looking for a stable alliance to trade deuterium for protection.',
            AiArchetype::Fleeter => 'Active fleeter looking for an alliance to coordinate hits and ACS.',
            AiArchetype::Turtle => 'Defensive player seeking a quiet alliance for mutual protection.',
            AiArchetype::Trader => 'Trader seeking an alliance to move resources and share market opportunities.',
            AiArchetype::Casual => 'Casual player looking for an active, friendly alliance.',
            default => 'Looking for an active alliance.',
        };
    }
}
