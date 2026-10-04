<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Enums\AiActionType;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Enums\AiReceiptState;
use Modules\AI\Models\AiActionReceipt;
use Symfony\Component\Yaml\Yaml;

/**
 * What the host or the dispatch gate refused this account a moment ago.
 *
 * A refused dispatch is not a mission, so nothing the planners read recorded it and the same target or
 * the same origin was offered again on the next login (measured 4 Oct 2026: one account tried one
 * target 21 times in six hours). A player who was told "that planet is online" leaves it alone for a
 * while; this is that memory, read from the receipts the gate already writes.
 *
 * The refusal cools what it blamed for as long as a launched mission cools its target, so a cooling
 * shorter than the span DISPATCH_REFUSALS counts cannot let a repeat land inside it. How long that is
 * lives in `resources/behavior/dispatch-refusals.yaml`, never here.
 */
class RecentRefusals
{
    private const POLICY_FILE = '/resources/behavior/dispatch-refusals.yaml';

    /** @var array<string, mixed>|null */
    private static ?array $policy = null;

    /**
     * The two refusals the gate raises about the *target*: a planet that is online or staging says
     * nothing about the body the fleet would leave from, so an origin is never blamed for them.
     */
    private const TARGET_SIDE_REASONS = [
        AiQueueActionReason::TargetActiveAtDispatch->value,
        AiQueueActionReason::TargetStagingAtDispatch->value,
    ];

    public function target(int $playerId, int $galaxy, int $system, int $position): bool
    {
        return isset($this->refusedTargets($playerId)["{$galaxy}:{$system}:{$position}"]);
    }

    /**
     * Whether the window holds a refusal that blamed this origin — for one of $reasons when the caller
     * names them, otherwise for whatever the gate said.
     */
    public function origin(int $playerId, int $planetId, string ...$reasons): bool
    {
        return $this->recent($playerId)
            ->reject($this->targetSide(...))
            ->contains(fn (AiActionReceipt $receipt): bool => ($receipt->result['planet_id'] ?? null) === $planetId
                && ($reasons === [] || in_array($receipt->result['reason'] ?? null, $reasons, true)));
    }

    /**
     * Whether the account already holds an answer for a dispatch from this body to this target: the body
     * or the coordinates is one the window blames.
     *
     * A planner reads this memory before it decides, but an intent is a decision already made and the
     * worker may run it hours later, so a refusal raised in between is one no planner could have read.
     * Asking the gate again for that body or target is the same question the account was already
     * answered, and the one receipt is the whole memory it needs.
     */
    public function cools(int $playerId, int $planetId, int $galaxy, int $system, int $position): bool
    {
        foreach ($this->recent($playerId) as $receipt) {
            $decision = $receipt->result['decision'] ?? [];

            if ($galaxy > 0
                && (int) ($decision['target_galaxy'] ?? 0) === $galaxy
                && (int) ($decision['target_system'] ?? 0) === $system
                && (int) ($decision['target_position'] ?? 0) === $position) {
                return true;
            }

            if ($planetId > 0 && ! $this->targetSide($receipt) && (int) ($receipt->result['planet_id'] ?? 0) === $planetId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the window holds a refusal of one of this account's own dispatch orders — the lane whose
     * budget belongs to the account rather than to a body or a target.
     *
     * An order that names no target is that lane: an expedition's slot is shared by every planet, so the
     * host's refusal ("too many expeditions at the same time") is the account's, and a player answers it
     * by waiting for a slot to come home rather than by trying the next body for the same answer. Cooling
     * the body alone sent the next login to a sibling and repeated the refusal (measured 4 Oct 2026: one
     * body refused for this five times in six hours). A refusal that names no body either (no owned
     * planet, nothing queueable) is not a dispatch that could have flown, so it is not a lane refusal.
     */
    public function accountLane(int $playerId): bool
    {
        return $this->recent($playerId)->contains(fn (AiActionReceipt $receipt): bool => ($receipt->result['decision'] ?? []) === []
            && ($receipt->result['planet_id'] ?? null) !== null);
    }

    /**
     * The coordinates the window's refusals named, keyed "g:s:p": a planner holding a candidate list
     * skips them in one read instead of one query per candidate.
     *
     * @return array<string, true>
     */
    public function refusedTargets(int $playerId): array
    {
        $coordinates = [];

        foreach ($this->recent($playerId) as $receipt) {
            $galaxy = $receipt->result['decision']['target_galaxy'] ?? null;
            $system = $receipt->result['decision']['target_system'] ?? null;
            $position = $receipt->result['decision']['target_position'] ?? null;

            if ($galaxy !== null && $system !== null && $position !== null) {
                $coordinates["{$galaxy}:{$system}:{$position}"] = true;
            }
        }

        return $coordinates;
    }

    /**
     * The own bodies the window's refusals blamed, keyed by planet id.
     *
     * An origin the gate refused is an origin a player leaves alone for a while, and the reason is not
     * read: the host raises some of them as free text a planner cannot name ("Not enough units on the
     * planet to send the fleet. Units required: colony_ship"), and each one says the dispatch from this
     * body did not happen. Only the target-side reasons are excluded.
     *
     * @return array<int, true>
     */
    public function refusedOrigins(int $playerId): array
    {
        $origins = [];

        foreach ($this->recent($playerId)->reject($this->targetSide(...)) as $receipt) {
            $planetId = $receipt->result['planet_id'] ?? null;

            if ($planetId !== null) {
                $origins[(int) $planetId] = true;
            }
        }

        return $origins;
    }

    /** A refusal about the target is no fault of the body the fleet would leave from. */
    private function targetSide(AiActionReceipt $receipt): bool
    {
        return in_array($receipt->result['reason'] ?? null, self::TARGET_SIDE_REASONS, true);
    }

    /** @return \Illuminate\Support\Collection<int, AiActionReceipt> */
    private function recent(int $playerId)
    {
        return AiActionReceipt::query()
            ->where('player_id', $playerId)
            ->where('action_type', AiActionType::DispatchFleet->value)
            ->where('state', AiReceiptState::Rejected->value)
            ->where('created_at', '>', now()->subMinutes($this->coolingMinutes()))
            ->get();
    }

    /**
     * The cooling a refusal buys, in minutes, read by name from the behaviour data. Absent data cools
     * nothing beyond the moment of the refusal, so a missing file shows up as repeated refusals rather
     * than as a silent blackout of every origin.
     */
    private function coolingMinutes(): int
    {
        $value = $this->policy()['cooling_minutes'] ?? null;

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /** @return array<string, mixed> */
    private function policy(): array
    {
        if (self::$policy !== null) {
            return self::$policy;
        }

        $path = dirname(__DIR__, 3) . self::POLICY_FILE;
        $parsed = is_file($path) ? Yaml::parseFile($path) : [];

        return self::$policy = is_array($parsed) ? $parsed : [];
    }
}
