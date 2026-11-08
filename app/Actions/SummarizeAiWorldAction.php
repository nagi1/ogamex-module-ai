<?php

namespace Modules\AI\Actions;

use Illuminate\Support\Facades\DB;

/**
 * A finished world in the few hundred lines a judge reads (llm-classifier-everywhere.md, step 6): alliances and their
 * strength, the wars and pacts between them, who fought whom, the mission mix, and the last things players said.
 * Raw tables, no models, so it reads a late world's copied database as well as the live one.
 */
class SummarizeAiWorldAction
{
    private const MESSAGES = 40;

    public function handle(string $connection): string
    {
        $db = DB::connection($connection);
        $allianceOf = $db->table('users')->whereNotNull('alliance_id')->pluck('alliance_id', 'id')->all();
        $tags = $db->table('alliances')->pluck('alliance_tag', 'id')->all();
        $sideOf = static fn (int|null $playerId): string => $playerId !== null && isset($allianceOf[$playerId]) ? '[' . ($tags[$allianceOf[$playerId]] ?? '?') . ']' : 'solo';

        $lines = ['# Alliances (tag, members, points)'];
        foreach ($tags as $id => $tag) {
            $members = array_keys($allianceOf, $id);
            $lines[] = sprintf('[%s] %d members, %d points', $tag, count($members), (int) $db->table('highscores')->whereIn('player_id', $members)->sum('general'));
        }
        $lines[] = sprintf('%d of %d players in no alliance', $db->table('users')->whereNull('alliance_id')->count(), $db->table('users')->count());

        $lines[] = '# Wars and pacts';
        // A world older than the relations table has no politics to report, which is itself the finding.
        $relations = $db->getSchemaBuilder()->hasTable('ai_alliance_relations') ? $db->table('ai_alliance_relations')->orderBy('started_at')->get() : [];
        foreach ($relations as $relation) {
            $lines[] = sprintf(
                '%s [%s] -> [%s] %s (%s)%s',
                $relation->started_at,
                $tags[$relation->alliance_id] ?? '?',
                $tags[$relation->other_alliance_id] ?? '?',
                (int) $relation->stance === 1 ? 'war' : 'pact',
                $relation->cause,
                $relation->ended_at === null ? '' : ' ended ' . $relation->ended_at
            );
        }

        $lines[] = '# Battles by side (attacker -> defender: count)';
        $pairs = [];
        foreach ($db->table('battle_reports')->get(['attacker', 'planet_user_id']) as $battle) {
            $attacker = (array) json_decode((string) $battle->attacker, true);
            $key = $sideOf(isset($attacker['player_id']) ? (int) $attacker['player_id'] : null) . ' -> ' . $sideOf($battle->planet_user_id === null ? null : (int) $battle->planet_user_id);
            $pairs[$key] = ($pairs[$key] ?? 0) + 1;
        }
        arsort($pairs);
        foreach (array_slice($pairs, 0, 15, true) as $pair => $count) {
            $lines[] = "{$pair}: {$count}";
        }

        $lines[] = '# Mission and event mix (message kind: count)';
        foreach ($db->table('messages')->select('key', DB::raw('count(*) as n'))->groupBy('key')->orderByDesc('n')->limit(25)->get() as $kind) {
            $lines[] = "{$kind->key}: {$kind->n}";
        }

        $lines[] = '# Last things players said (sender side, channel: text)';
        foreach ($db->table('chat_messages')->latest('id')->limit(self::MESSAGES)->get()->reverse() as $chat) {
            $lines[] = sprintf('%s %s: %s', $sideOf((int) $chat->sender_id), $chat->alliance_id === null ? 'direct' : 'alliance', str_replace("\n", ' ', (string) $chat->message));
        }

        return implode("\n", $lines);
    }
}
