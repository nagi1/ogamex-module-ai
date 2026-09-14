<?php

namespace Modules\AI\Actions;

use Modules\AI\Models\AiOperabilitySwitch;
use Modules\AI\Support\AiClock;

/**
 * Records a staff decision to let the population start new work, or to stop it.
 *
 * The decision is appended rather than written over, so the newest row answers "is AI work
 * running" and the rows before it answer "who stopped it, when and why".
 */
class SetAiWorkSwitchAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(bool $enabled, string $reason, int|null $actorPlayerId): AiOperabilitySwitch
    {
        return AiOperabilitySwitch::query()->create([
            'enabled' => $enabled,
            'reason' => $reason,
            'actor_player_id' => $actorPlayerId,
            'changed_at' => $this->clock->now(),
        ]);
    }
}
