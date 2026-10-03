<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Intel;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

/**
 * What a target has paid this account before: a bounded counter per player and coordinate, raised by a
 * raid that came home with loot and lowered by one that came home empty (architecture step 7, 4.2).
 *
 * It is memory, not a forecast. Nothing here touches the host's world and nothing is derived from a live
 * foreign planet: the writer is the account's own raid outcome, recorded from the host's battle report,
 * and the reader is the scout that ranks its next probe. The counter outlives the process in `ai_intel`,
 * so a session an hour later still knows what a farm did. The step, the bound and the weight the counter
 * carries into a ranking are read by name from `resources/behavior/intel.yaml`, never written here.
 */
final class IntelBook
{
    private const POLICY_FILE = '/resources/behavior/intel.yaml';

    /** @var array<string, mixed>|null */
    private static ?array $policy = null;

    /** The key a target is written and read under: the three numbers every report carries. */
    public static function key(int $galaxy, int $system, int $position): string
    {
        return $galaxy . ':' . $system . ':' . $position;
    }

    /** The current score of one target: zero for a planet the account has never fought. */
    public function priority(int $playerId, string $coordinate): int
    {
        $priority = DB::table('ai_intel')
            ->where('player_id', $playerId)
            ->where('coordinate', $coordinate)
            ->value('priority');

        return $priority === null ? 0 : (int) $priority;
    }

    /**
     * Every target this account has a counter for, keyed by coordinate: the read side of a ranking board
     * where a coordinate the account never hit is simply absent, so a caller reads a missing key as zero.
     *
     * @return array<string, int>
     */
    public function priorities(int $playerId): array
    {
        $priorities = [];
        foreach (DB::table('ai_intel')->where('player_id', $playerId)->get(['coordinate', 'priority']) as $row) {
            $priorities[(string) $row->coordinate] = (int) $row->priority;
        }

        return $priorities;
    }

    /**
     * Fold one raid outcome into a target's counter: a profitable raid raises it, a loss lowers it, and the
     * counter stops at the bound either way. Returns the counter as it now stands.
     */
    public function recordOutcome(int $playerId, string $coordinate, bool $won): int
    {
        $existing = DB::table('ai_intel')->where('player_id', $playerId)->where('coordinate', $coordinate)->value('priority');
        $priority = $this->bounded(($existing === null ? 0 : (int) $existing) + ($won ? $this->step('win_step') : -$this->step('loss_step')));

        $where = ['player_id' => $playerId, 'coordinate' => $coordinate];

        if ($existing === null) {
            DB::table('ai_intel')->insert($where + [
                'priority' => $priority,
                'last_outcome_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $priority;
        }

        DB::table('ai_intel')->where($where)->update([
            'priority' => $priority,
            'last_outcome_at' => now(),
            'updated_at' => now(),
        ]);

        return $priority;
    }

    private function step(string $name): int
    {
        return max(1, $this->int('target_priority.' . $name, 1));
    }

    private function bounded(int $priority): int
    {
        return max($this->int('target_priority.floor', $priority), min($this->int('target_priority.ceiling', $priority), $priority));
    }

    private function int(string $path, int $fallback): int
    {
        return (int) $this->number($path, (float) $fallback);
    }

    private function number(string $path, float $fallback): float
    {
        $value = $this->policy();

        foreach (explode('.', $path) as $segment) {
            $value = is_array($value) ? ($value[$segment] ?? null) : null;
        }

        return is_numeric($value) ? (float) $value : $fallback;
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
