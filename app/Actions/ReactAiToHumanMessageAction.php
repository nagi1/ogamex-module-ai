<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Decision\Enemies;
use Modules\AI\Domain\Routine\SessionPlanner;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Models\AiSchedule;
use Modules\AI\Models\AiTextReading;
use Modules\AI\Models\AiWorkItem;
use OGame\Models\ChatMessage;
use OGame\Models\Planet;

/**
 * What a human's message changes before the AI accounts that got it next play (llm-classifier-everywhere.md, steps 2-4):
 * - a message that wants an answer now ("fleet on your moon in 20 min") brings an awake account's session forward, as a
 *   player checks the game when a threat lands; slow answers to threats are the latency a human notices first;
 * - a human leader's war or pact message goes to the alliance politics at once;
 * - a mate's call for an attack or for help that names coordinates makes the owner there an enemy of each AI mate, so
 *   the scouting, the raid tolerance and the ACS the war already uses aim at that target.
 */
class ReactAiToHumanMessageAction
{
    /** A player reads the message and logs in within minutes, never at once. */
    private const MIN_DELAY_MINUTES = 3;

    private const MAX_DELAY_MINUTES = 15;

    private const ALLIANCE_ACTS = ['war', 'pact_offer', 'pact_accept', 'pact_break'];

    private const CALL_ACTS = ['acs_call', 'help_request'];

    public function handle(int $chatMessageId): bool
    {
        $message = ChatMessage::query()->find($chatMessageId);
        if ($message === null) {
            return false;
        }

        $reading = app(ReadAiHumanMessageAction::class)->handle((int) $message->sender_id, (string) $message->message);
        $act = (string) $reading?->choice('act');
        $reacted = false;

        foreach ($this->readers($message) as $profile) {
            $moves = [$this->allianceAct($message, $profile, $act), $this->answerCall($message, $profile, $act), $this->wake($message, $profile, $reading)];
            $reacted = $reacted || in_array(true, $moves, true);
        }

        return $reacted;
    }

    /** @return list<AiProfile> the AI accounts the message reached: its recipient, or the AI members of its alliance channel */
    private function readers(ChatMessage $message): array
    {
        $ids = AiObservation::query()->where('source_id', $message->id)->pluck('player_id');

        return AiProfile::query()->whereIn('player_id', $ids)->where('enabled', true)->get()->all();
    }

    private function allianceAct(ChatMessage $message, AiProfile $profile, string $act): bool
    {
        return $message->recipient_id !== null && in_array($act, self::ALLIANCE_ACTS, true)
            && app(AdvanceAiAlliancePoliticsAction::class)->answerHumanLeader((int) $message->sender_id, (int) $profile->player_id, $act);
    }

    /** Only a mate's call is answered: a stranger asking an account to attack someone is not a reason to. */
    private function answerCall(ChatMessage $message, AiProfile $profile, string $act): bool
    {
        if (!in_array($act, self::CALL_ACTS, true) || preg_match('/\b(\d{1,2}):(\d{1,3}):(\d{1,2})\b/', (string) $message->message, $at) !== 1) {
            return false;
        }

        $mates = app(Enemies::class)->spared((int) $profile->player_id);
        $ownerId = Planet::query()->where('galaxy', $at[1])->where('system', $at[2])->where('planet', $at[3])->value('user_id');
        if (!in_array((int) $message->sender_id, $mates, true) || $ownerId === null || in_array((int) $ownerId, [...$mates, (int) $profile->player_id], true)) {
            return false;
        }

        $relationship = AiRelationship::query()->firstOrNew(['player_id' => $profile->player_id, 'other_player_id' => $ownerId], ['revision' => 0]);
        $relationship->threat = (string) max((float) $relationship->threat, Enemies::ENEMY_THREAT);
        $relationship->revision++;
        $relationship->save();

        return true;
    }

    private function wake(ChatMessage $message, AiProfile $profile, AiTextReading|null $reading): bool
    {
        if ($reading?->level('urgency') !== ReadAiHumanMessageAction::URGENCY_NOW) {
            return false;
        }

        $span = self::MAX_DELAY_MINUTES - self::MIN_DELAY_MINUTES + 1;
        $wakeAt = CarbonImmutable::now()->addMinutes(self::MIN_DELAY_MINUTES + crc32($profile->random_seed . ':' . $message->id) % $span);
        $schedule = AiSchedule::query()->where('player_id', $profile->player_id)->first();
        // An asleep account stays asleep: a player does not wake for a message.
        if ($schedule === null || $schedule->next_due_at->lessThanOrEqualTo($wakeAt) || !app(SessionPlanner::class)->isAwake($profile, $wakeAt)) {
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
