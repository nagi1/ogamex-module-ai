<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Contracts\AllianceTreaties;
use Modules\AI\Infrastructure\Diplomacy\EmpireAllianceTreaties;
use Modules\AI\Models\AiProfile;
use OGame\Models\Alliance;
use Symfony\Component\Yaml\Yaml;

/**
 * Deed matches speech (SOCIAL-WORLD-DRAFT part 16.3, phase E), read only. Every war, pact, notice and peace an AI leader
 * announced (alliance broadcast or a word to the other leader, authored lines of alliance-war.yaml) must stand on the
 * treaty record at the instant it was said; every such act by an AI-led club must have been said; and no hostile fleet of
 * a member flies at a member of a club it holds a pact with, notice window included.
 *
 * The lines are matched as authored, so a run with the language model composing them reads them as unmatched speech.
 * Membership is read as it stands now: an account the cycles moved after a flight is judged by its present club.
 */
class CheckSocialIntegrityAction
{
    /** Attack, ACS attack, moon destruction, missile. */
    private const HOSTILE_MISSIONS = [1, 2, 9, 10];

    private const ESPIONAGE = 6;

    /** Seconds between a word and its act that still count as one move (a politics pass stands at one instant). */
    private const WINDOW = 3600;

    /** What each authored line claims: the act it must stand next to on the record. */
    private const CLAIMS = [
        'war_broadcast' => 'war', 'war_to_leader' => 'war', 'war_declared_on_us' => 'war', 'betrayal_broadcast' => 'war',
        'betrayal_to_leader' => 'war', 'peace_broadcast' => 'peace', 'pact_accept' => 'pact', 'conspiracy_accept' => 'pact',
        'pact_cancel_broadcast' => 'cancel', 'pact_cancel_to_leader' => 'cancel', 'threat_ultimatum' => 'threat',
        'tribute_ultimatum' => 'threat', 'pact_offer' => 'offer', 'flank_offer' => 'offer', 'court_offer' => 'offer',
        'conspiracy_offer' => 'offer', 'pact_refuse' => 'refuse',
    ];

    /** @var array<string, list<int>> tag -> alliance ids */
    private array $byTag = [];

    /** @var array<int, int> user -> alliance */
    private array $clubOf = [];

    /** @var list<array{id: int, a: int, b: int, stance: int, start: int, accepted: int|null, end: int|null, notice: int|null, canceller: int|null, breaker: int|null}> */
    private array $treaties = [];

    /** @return array<string, mixed> */
    public function handle(): array
    {
        $this->clubOf = DB::table('users')->whereNotNull('alliance_id')->pluck('alliance_id', 'id')->map(static fn ($id): int => (int) $id)->all();
        foreach (Alliance::query()->get(['id', 'alliance_tag']) as $alliance) {
            $this->byTag[(string) $alliance->alliance_tag][] = (int) $alliance->id;
        }
        $this->treaties = $this->loadTreaties();

        $speech = $this->speech();
        $report = ['record' => $this->usesEmpire() ? 'empire_alliance_treaties' : 'ai_alliance_relations', 'acts' => $this->acts(), 'speech' => [], 'unmatched_speech' => [], 'silent_acts' => [], 'fleets' => $this->fleets()];
        foreach ($speech as $said) {
            $report['speech'][$said['claim']] = ($report['speech'][$said['claim']] ?? 0) + 1;
            if (in_array($said['claim'], ['war', 'peace', 'pact', 'cancel'], true) && !$this->stands($said)) {
                $report['unmatched_speech'][] = $said;
            }
        }
        $report['silent_acts'] = $this->silentActs($speech);
        $report['ok'] = $report['unmatched_speech'] === [] && $report['silent_acts'] === [] && $report['fleets']['hostile_at_partner'] === [];

        return $report;
    }

    /** @return array<string, int> */
    private function acts(): array
    {
        $acts = ['wars' => 0, 'pacts' => 0, 'pact_offers_open' => 0, 'notice_cancels' => 0, 'surprise_breaks' => 0, 'peace' => 0];
        foreach ($this->treaties as $t) {
            if ($t['stance'] === 1) {
                $acts['wars']++;
                $acts['peace'] += $t['end'] !== null ? 1 : 0;

                continue;
            }
            $acts[$t['accepted'] === null ? 'pact_offers_open' : 'pacts']++;
            $acts['notice_cancels'] += $t['notice'] !== null ? 1 : 0;
            $acts['surprise_breaks'] += $t['breaker'] !== null ? 1 : 0;
        }

        return $acts;
    }

    /** @param array{claim: string, at: int, speaker: int, counterpart: list<int>} $said */
    private function stands(array $said): bool
    {
        foreach ($this->treaties as $t) {
            if (!$this->between($t, $said['speaker'], $said['counterpart'])) {
                continue;
            }

            $near = static fn (int|null $at): bool => $at !== null && abs($at - $said['at']) <= self::WINDOW;
            $hit = match ($said['claim']) {
                'war' => $t['stance'] === 1 && $near($t['start']),
                'peace' => $t['stance'] === 1 && $near($t['end']),
                'pact' => $t['stance'] === 2 && $near($t['accepted']),
                'cancel' => $t['stance'] === 2 && $t['canceller'] === $said['speaker'] && $t['notice'] !== null && $t['notice'] > $said['at'],
                default => false,
            };
            if ($hit) {
                return true;
            }
        }

        return false;
    }

    /**
     * Acts of AI-led clubs nobody was told about. Peace counts only where a war was ended by peace, not replaced by a pact.
     *
     * @param list<array{claim: string, at: int, speaker: int, counterpart: list<int>}> $speech
     * @return list<array<string, mixed>>
     */
    private function silentActs(array $speech): array
    {
        $aiClubs = array_fill_keys(Alliance::query()->whereIn('founder_user_id', AiProfile::query()->pluck('player_id'))->pluck('id')->map(static fn ($id): int => (int) $id)->all(), true);
        $silent = [];
        foreach ($this->treaties as $t) {
            $both = [[$t['a'], $t['b']], [$t['b'], $t['a']]];
            $acts = [];
            if ($t['stance'] === 1) {
                $acts[] = ['claim' => 'war', 'at' => $t['start'], 'pairs' => [$both[0]]];
                if ($t['end'] !== null) {
                    $acts[] = ['claim' => 'peace', 'at' => $t['end'], 'pairs' => $both];
                }
            } elseif ($t['accepted'] !== null) {
                $acts[] = ['claim' => 'pact', 'at' => $t['accepted'], 'pairs' => [$both[1]]];
                if ($t['notice'] !== null && $t['canceller'] !== null) {
                    $acts[] = ['claim' => 'cancel', 'at' => null, 'pairs' => [$t['canceller'] === $t['a'] ? $both[0] : $both[1]]];
                }
            }

            foreach ($acts as $act) {
                $speakers = array_values(array_filter($act['pairs'], static fn (array $pair): bool => isset($aiClubs[$pair[0]])));
                foreach ($speakers as [$speaker, $other]) {
                    foreach ($speech as $said) {
                        if ($said['claim'] === $act['claim'] && $said['speaker'] === $speaker && in_array($other, $said['counterpart'], true)
                            && ($act['at'] === null || abs($act['at'] - $said['at']) <= self::WINDOW)) {
                            continue 3;
                        }
                    }
                }
                if ($speakers !== []) {
                    $silent[] = ['act' => $act['claim'], 'treaty' => $t['id'], 'alliance_id' => $t['a'], 'other_alliance_id' => $t['b'],
                        'at' => $act['at'] === null ? null : CarbonImmutable::createFromTimestamp($act['at'])->toIso8601String()];
                }
            }
        }

        return $silent;
    }

    /** @return array{hostile_at_partner: list<array<string, mixed>>, espionage_at_partner: int, hostile_at_war_enemy: int} */
    private function fleets(): array
    {
        $members = [];
        foreach ($this->clubOf as $user => $club) {
            $members[$club][] = (int) $user;
        }

        $out = ['hostile_at_partner' => [], 'espionage_at_partner' => 0, 'hostile_at_war_enemy' => 0];
        foreach ($this->treaties as $t) {
            $from = $t['stance'] === 2 ? $t['accepted'] : $t['start'];
            if ($from === null || !isset($members[$t['a']], $members[$t['b']])) {
                continue;
            }

            foreach ([[$t['a'], $t['b']], [$t['b'], $t['a']]] as [$club, $other]) {
                $missions = DB::table('fleet_missions')->join('planets', 'planets.id', '=', 'fleet_missions.planet_id_to')
                    ->whereIn('fleet_missions.user_id', $members[$club])->whereIn('planets.user_id', $members[$other])
                    ->whereIn('fleet_missions.mission_type', [...self::HOSTILE_MISSIONS, self::ESPIONAGE])
                    ->where('fleet_missions.time_departure', '>=', $from)
                    ->when($t['end'] !== null, static fn ($query) => $query->where('fleet_missions.time_departure', '<', $t['end']))
                    ->get(['fleet_missions.id', 'fleet_missions.user_id', 'fleet_missions.mission_type', 'fleet_missions.time_departure']);
                foreach ($missions as $mission) {
                    if ($t['stance'] === 1) {
                        $out['hostile_at_war_enemy'] += in_array((int) $mission->mission_type, self::HOSTILE_MISSIONS, true) ? 1 : 0;
                    } elseif ((int) $mission->mission_type === self::ESPIONAGE) {
                        $out['espionage_at_partner']++;
                    } else {
                        $out['hostile_at_partner'][] = ['mission' => (int) $mission->id, 'player_id' => (int) $mission->user_id, 'type' => (int) $mission->mission_type,
                            'treaty' => $t['id'], 'in_notice' => $t['notice'] !== null, 'departed' => CarbonImmutable::createFromTimestamp((int) $mission->time_departure)->toIso8601String()];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Broadcasts (one row per recipient, folded to one per club, line and instant) and words between leaders.
     *
     * @return list<array{claim: string, line: string, at: int, speaker: int, counterpart: list<int>, text: string}>
     */
    private function speech(): array
    {
        $patterns = $this->patterns();
        $said = [];
        $seen = [];
        foreach (DB::table('messages')->where('key', 'alliance_broadcast')->orderBy('id')->get(['params', 'created_at']) as $row) {
            $params = json_decode((string) $row->params, true);
            $text = (string) ($params['message'] ?? '');
            $at = CarbonImmutable::parse((string) $row->created_at)->getTimestamp();
            foreach ($this->byTag[(string) ($params['alliance_tag'] ?? '')] ?? [] as $speaker) {
                $key = $speaker . '|' . $at . '|' . $text;
                if (!isset($seen[$key]) && ($line = $this->read($patterns, $text, $speaker, $at)) !== null) {
                    $seen[$key] = true;
                    $said[] = $line;
                }
            }
        }

        $leaders = Alliance::query()->whereNotNull('founder_user_id')->pluck('id', 'founder_user_id')->map(static fn ($id): int => (int) $id)->all();
        foreach (DB::table('chat_messages')->whereIn('sender_id', array_keys($leaders))->orderBy('id')->get(['sender_id', 'message', 'created_at']) as $row) {
            $line = $this->read($patterns, (string) $row->message, $leaders[(int) $row->sender_id], CarbonImmutable::parse((string) $row->created_at)->getTimestamp());
            if ($line !== null) {
                $said[] = $line;
            }
        }

        return $said;
    }

    /**
     * @param array<string, list<string>> $patterns
     * @return array{claim: string, line: string, at: int, speaker: int, counterpart: list<int>, text: string}|null
     */
    private function read(array $patterns, string $text, int $speaker, int $at): array|null
    {
        foreach ($patterns as $key => $regexes) {
            foreach ($regexes as $regex) {
                if (preg_match($regex, $text, $match) === 1) {
                    return ['claim' => self::CLAIMS[$key], 'line' => $key, 'at' => $at, 'speaker' => $speaker, 'counterpart' => $this->byTag[$match['tag']] ?? [], 'text' => $text];
                }
            }
        }

        return null;
    }

    /** @return array<string, list<string>> line key -> regexes with a named group for the tag */
    private function patterns(): array
    {
        $lines = (array) (Yaml::parseFile(dirname(__DIR__, 2) . '/resources/behavior/alliance-war.yaml')['lines'] ?? []);
        $patterns = [];
        foreach (self::CLAIMS as $key => $claim) {
            foreach ((array) ($lines[$key] ?? []) as $line) {
                // The first {tag} names the counterpart; a repeat must say the same tag.
                $parts = explode('\\{tag\\}', preg_quote((string) $line, '/'));
                $regex = array_shift($parts) . ($parts === [] ? '' : '(?<tag>[^\\]]+)' . implode('(?P=tag)', $parts));
                $patterns[$key][] = '/^' . str_replace(['\\{other\\}', '\\{hours\\}'], ['.+?', '\\d+'], $regex) . '$/su';
            }
        }

        return $patterns;
    }

    private function usesEmpire(): bool
    {
        return app(AllianceTreaties::class) instanceof EmpireAllianceTreaties;
    }

    /** @return list<array{id: int, a: int, b: int, stance: int, start: int, accepted: int|null, end: int|null, notice: int|null, canceller: int|null, breaker: int|null}> */
    private function loadTreaties(): array
    {
        $empire = $this->usesEmpire();
        $table = $empire ? 'empire_alliance_treaties' : 'ai_alliance_relations';
        if (!Schema::hasTable($table)) {
            return [];
        }

        $time = static fn ($value): int|null => $value === null ? null : CarbonImmutable::parse((string) $value)->getTimestamp();
        $rows = [];
        foreach (DB::table($table)->orderBy('id')->get() as $row) {
            $start = (int) $time($row->started_at);
            $rows[] = [
                'id' => (int) $row->id, 'a' => (int) $row->alliance_id, 'b' => (int) $row->other_alliance_id, 'stance' => (int) $row->stance,
                'start' => $start, 'accepted' => $empire ? $time($row->accepted_at) : $start, 'end' => $time($row->ended_at),
                'notice' => $time($row->notice_ends_at ?? null), 'canceller' => isset($row->cancelled_by_alliance_id) ? (int) $row->cancelled_by_alliance_id : null,
                'breaker' => isset($row->broken_by_alliance_id) ? (int) $row->broken_by_alliance_id : null,
            ];
        }

        return $rows;
    }

    /** @param array{a: int, b: int} $t @param list<int> $others */
    private function between(array $t, int $club, array $others): bool
    {
        return ($t['a'] === $club && in_array($t['b'], $others, true)) || ($t['b'] === $club && in_array($t['a'], $others, true));
    }
}
