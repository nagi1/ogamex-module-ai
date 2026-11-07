<?php

namespace Modules\AI\Domain\Advisor;

use Modules\AI\Actions\ReadAiInnerStateAction;
use Modules\AI\Models\AiProfile;
use OGame\Models\BattleReport;
use OGame\Models\EspionageReport;
use OGame\Models\Highscore;
use OGame\Models\Message;
use OGame\Models\User;

/**
 * The only place an advisor brief reads the world from, and only what this account can see: the
 * public highscore, its own spy and battle reports (through its own messages) with their age, and
 * its own feelings and memories of the other player. Never another account's planets, fleets,
 * resources or mind.
 */
class AdvisorFacts
{
    /** @return array{points: int, rank: int}|array{} the public highscore line, empty before the first one */
    public function standing(int $playerId): array
    {
        $line = Highscore::query()->where('player_id', $playerId)->first(['general', 'general_rank']);

        return $line === null ? [] : ['points' => (int) $line->general, 'rank' => (int) $line->general_rank];
    }

    /** @return array{members: int, points: int} the alliance as its public member list and highscore show it */
    public function club(int $allianceId): array
    {
        $members = User::query()->where('alliance_id', $allianceId)->pluck('id');

        return ['members' => $members->count(), 'points' => (int) Highscore::query()->whereIn('player_id', $members)->sum('general')];
    }

    /** @return array<string, string|list<string>> how the account itself feels about the other player, in words */
    public function feelings(int $playerId, int $otherId, string $about): array
    {
        return $otherId > 0 ? app(ReadAiInnerStateAction::class)->handle($playerId, $otherId, $about) : [];
    }

    /** The persona whose voice a line is written in. */
    public function persona(int $playerId): string
    {
        return strtolower((string) AiProfile::query()->where('player_id', $playerId)->first()?->archetype?->name);
    }

    /** The language the recipient plays in, for the wording only; English when unknown. */
    public function language(int $playerId): string
    {
        $lang = strtolower(trim((string) User::query()->whereKey($playerId)->value('lang')));

        return $lang === '' ? 'en' : $lang;
    }

    /**
     * The account's own reports on the other player: the newest spy report and battles between them,
     * each with its age in hours. A report another account received is never read.
     *
     * @return list<array{kind: string, age_hours: int, value: array<string, int|string>}>
     */
    public function reports(int $playerId, int $otherId, int $limit = 3): array
    {
        $now = now()->getTimestamp();
        $age = static fn ($at): int => max(0, intdiv($now - (int) $at?->getTimestamp(), 3600));
        $evidence = [];

        $spy = EspionageReport::query()
            ->whereIn('id', Message::query()->where('user_id', $playerId)->whereNotNull('espionage_report_id')->select('espionage_report_id'))
            ->where('planet_user_id', $otherId)
            ->orderByDesc('id')
            ->first();
        if ($spy !== null) {
            $evidence[] = ['kind' => 'own_spy_report', 'age_hours' => $age($spy->created_at), 'value' => [
                'coordinates' => $spy->planet_galaxy . ':' . $spy->planet_system . ':' . $spy->planet_position,
                'resources' => (int) array_sum(array_map('intval', array_intersect_key((array) $spy->resources, array_flip(['metal', 'crystal', 'deuterium'])))),
                'ships' => (int) array_sum(array_map('intval', (array) $spy->ships)),
                'defense' => (int) array_sum(array_map('intval', (array) $spy->defense)),
            ]];
        }

        $battles = BattleReport::query()
            ->whereIn('id', Message::query()->where('user_id', $playerId)->whereNotNull('battle_report_id')->select('battle_report_id'))
            ->whereIn('planet_user_id', [$playerId, $otherId])
            ->orderByDesc('id')
            ->limit(20)
            ->get();
        foreach ($battles as $battle) {
            $attacker = (int) (((array) $battle->attacker)['player_id'] ?? 0);
            if (!in_array($attacker, [$playerId, $otherId], true) || $attacker === (int) $battle->planet_user_id || count($evidence) > $limit) {
                continue;
            }

            $attackerLoss = (int) (((array) $battle->attacker)['resource_loss'] ?? 0);
            $defenderLoss = (int) (((array) $battle->defender)['resource_loss'] ?? 0);
            $evidence[] = ['kind' => 'own_battle_report', 'age_hours' => $age($battle->created_at), 'value' => [
                'attacker' => $attacker === $playerId ? 'me' : 'them',
                'my_loss' => $attacker === $playerId ? $attackerLoss : $defenderLoss,
                'their_loss' => $attacker === $playerId ? $defenderLoss : $attackerLoss,
            ]];
        }

        return $evidence;
    }
}
