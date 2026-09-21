<?php

namespace Modules\AI\Actions;

use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;

/**
 * Stops or resumes one account, not the population. The reason travels with the account in its
 * own settings rather than a new table, so whoever looks later sees who, why and when. Stopping
 * is a flag, never a delete: the account keeps its memory, relationships and obligations.
 */
class SetAiAccountEnabledAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(int $playerId, bool $enabled, string $reason, int|null $actorPlayerId): AiProfile
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->firstOrFail();
        $settings = $profile->settings ?? [];

        $settings['account_switch'] = [
            'enabled' => $enabled,
            'reason' => $reason,
            'actor_player_id' => $actorPlayerId,
            'changed_at' => $this->clock->now()->toDateTimeString(),
        ];

        $profile->update(['enabled' => $enabled, 'settings' => $settings]);

        return $profile->refresh();
    }
}
