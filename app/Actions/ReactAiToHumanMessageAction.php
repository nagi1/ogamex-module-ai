<?php

namespace Modules\AI\Actions;

use Modules\AI\Domain\Routine\SessionPlanner;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiSchedule;
use Modules\AI\Models\AiWorkItem;
use OGame\Models\ChatMessage;

/**
 * What a human's message changes before the account's next session: a message that wants an answer now ("I'm coming
 * for you tonight", "fleet on your moon in 20 min") brings that session forward, as a player checks the game when
 * a threat lands. Slow answers to threats are the reaction latency a human notices first (account-authenticity.md).
 * An asleep account stays asleep: a player does not wake for a message.
 */
class ReactAiToHumanMessageAction
{
    /** A player reads the message and logs in within minutes, never at once. */
    private const MIN_DELAY_MINUTES = 3;

    private const MAX_DELAY_MINUTES = 15;

    public function handle(int $chatMessageId): bool
    {
        $message = ChatMessage::query()->find($chatMessageId);
        $profile = $message?->recipient_id === null ? null : AiProfile::query()->where('player_id', $message->recipient_id)->where('enabled', true)->first();
        if ($message === null || $profile === null) {
            return false;
        }

        $reading = app(ReadAiHumanMessageAction::class)->handle((int) $message->sender_id, (string) $message->message);
        if ($reading?->level('urgency') !== ReadAiHumanMessageAction::URGENCY_NOW) {
            return false;
        }

        $span = self::MAX_DELAY_MINUTES - self::MIN_DELAY_MINUTES + 1;
        $wakeAt = now()->toImmutable()->addMinutes(self::MIN_DELAY_MINUTES + crc32($profile->random_seed . ':' . $chatMessageId) % $span);
        if (!app(SessionPlanner::class)->isAwake($profile, $wakeAt)) {
            return false;
        }

        $schedule = AiSchedule::query()->where('player_id', $profile->player_id)->first();
        if ($schedule === null || $schedule->next_due_at->lessThanOrEqualTo($wakeAt)) {
            return false;
        }

        // The pending session of the current generation moves earlier; the chain itself is untouched.
        $moved = AiWorkItem::query()->where('player_id', $profile->player_id)->where('kind', AiWorkKind::RunSession)
            ->where('state', AiWorkState::Pending)->where('schedule_generation', $schedule->generation)
            ->update(['due_at' => $wakeAt]);
        $schedule->update(['next_due_at' => $wakeAt]);

        return $moved > 0;
    }
}
