<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Domain\Social\SocialMinds;
use Modules\AI\Enums\AiAllianceStance;
use Modules\AI\Models\AiAllianceRelation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiSocialMind;
use Modules\AI\Support\AiClock;
use OGame\Models\Alliance;
use OGame\Models\BattleReport;
use OGame\Models\ChatMessage;
use OGame\Models\Highscore;
use OGame\Models\User;

/**
 * The human side of an AI world (validation.md "Measure humans separately", product.md "Conflict that remains
 * enjoyable"): what humans did with AI accounts, how hard the AI pressed them, whether they stayed after a loss, and
 * the guardrails. Read only, from records the host and module already keep; AI accounts are never in a denominator.
 */
class BuildHumanExperienceReportAction
{
    /** Attack, ACS attack, moon destruction, missile: what a defender feels as pressure (probes are not). */
    private const HOSTILE_MISSIONS = [1, 2, 9, 10];

    private const ACS_DEFEND = 5;

    /** The window social-minds.yaml counts threats over (AdvanceAiAlliancePoliticsAction::PRESSURE_WINDOW_HOURS). */
    private const PRESSURE_WINDOW_HOURS = 168;

    /** @var array<int, true> */
    private array $ai = [];

    /** @var array<int, true> */
    private array $humans = [];

    /** @var array<int, int> alliance -> founder */
    private array $founders = [];

    public function __construct(private readonly AiClock $clock, private readonly SocialMinds $minds)
    {
    }

    /** @return array<string, mixed> */
    public function handle(int $days, float $lossThreshold = 0.2): array
    {
        $now = $this->clock->now();
        $from = $now->subDays($days);
        $this->ai = array_fill_keys(AiProfile::query()->pluck('player_id')->map(static fn ($id): int => (int) $id)->all(), true);
        $this->founders = Alliance::query()->whereNotNull('founder_user_id')->pluck('founder_user_id', 'id')->map(static fn ($id): int => (int) $id)->all();
        $this->humans = array_fill_keys(User::query()->whereNotIn('id', array_keys($this->ai))->pluck('id')->map(static fn ($id): int => (int) $id)->all(), true);

        $battles = $this->battles($from, $now);
        $pacts = $this->pacts($from, $now);
        $assistance = $this->assistance($from, $now);
        $conversations = $this->conversations($from, $now);
        $hostile = $this->hostileMissions($from, $now);

        $credited = [];
        foreach ([$battles['per_human'], $pacts['per_human'], $assistance['per_human'], $conversations['per_human']] as $kind) {
            foreach ($kind as $human => $count) {
                $credited[$human] = ($credited[$human] ?? 0) + $count;
            }
        }

        $total = $battles['raids'] + $battles['defenses'] + $pacts['count'] + $assistance['count'] + $conversations['two_way'];
        $humanWeeks = count($this->humans) * $days / 7.0;

        return [
            'days' => $days,
            'humans' => count($this->humans),
            'interactions' => [
                'raids_on_ai' => $battles['raids'],
                'defenses_against_ai' => $battles['defenses'],
                'pacts_with_ai_alliances' => $pacts['count'],
                'assistance' => $assistance['count'],
                'two_way_conversations' => $conversations['two_way'],
                'total' => $total,
                'per_human_week' => $humanWeeks > 0 ? round($total / $humanWeeks, 2) : 0.0,
                'humans_with_any' => count($credited),
            ],
            'opportunities' => [
                'ai_attacks_launched' => $hostile['attacks'],
                'conversation_contacts' => $conversations['contacts'],
            ],
            'pressure' => $this->pressure($hostile['per_human'], $days, $from, $now),
            'resilience' => $this->resilience($battles['lost'], $lossThreshold),
            'retention' => $this->retention($now),
            'guardrails' => $this->guardrails($from, $now),
        ];
    }

    /**
     * Battles between one human and one AI account: a raid the human flew or a defence the human stood. Each one also
     * books what the human lost (units destroyed, plus loot when they defended) for the resilience read.
     *
     * @return array{raids: int, defenses: int, per_human: array<int, int>, lost: array<int, array{lost: float, last: int}>}
     */
    private function battles(CarbonImmutable $from, CarbonImmutable $now): array
    {
        $out = ['raids' => 0, 'defenses' => 0, 'per_human' => [], 'lost' => []];
        foreach (BattleReport::query()->whereBetween('created_at', [$from, $now])->lazyById() as $report) {
            $attacker = (int) ($report->attacker['player_id'] ?? 0);
            $defender = (int) ($report->defender['player_id'] ?? 0);
            $raid = isset($this->humans[$attacker]) && isset($this->ai[$defender]);
            if (!$raid && !(isset($this->ai[$attacker]) && isset($this->humans[$defender]))) {
                continue;
            }

            $human = $raid ? $attacker : $defender;
            $out[$raid ? 'raids' : 'defenses']++;
            $out['per_human'][$human] = ($out['per_human'][$human] ?? 0) + 1;

            $loot = $raid ? 0.0 : (float) array_sum(array_intersect_key((array) $report->loot, array_flip(['metal', 'crystal', 'deuterium'])));
            $lost = (float) (($raid ? $report->attacker : $report->defender)['resource_loss'] ?? 0) + $loot;
            $out['lost'][$human] = [
                'lost' => ($out['lost'][$human]['lost'] ?? 0.0) + $lost,
                'last' => max($out['lost'][$human]['last'] ?? 0, (int) $report->created_at?->getTimestamp()),
            ];
        }

        return $out;
    }

    /**
     * Pacts opened in the window between a human-led and an AI-led alliance, credited to the human leader who holds it.
     *
     * @return array{count: int, per_human: array<int, int>}
     */
    private function pacts(CarbonImmutable $from, CarbonImmutable $now): array
    {
        $founders = $this->founders;
        $out = ['count' => 0, 'per_human' => []];
        $relations = AiAllianceRelation::query()->where('stance', AiAllianceStance::Nap)->whereBetween('started_at', [$from, $now])->get();
        foreach ($relations as $relation) {
            $one = $founders[$relation->alliance_id] ?? 0;
            $other = $founders[$relation->other_alliance_id] ?? 0;
            $human = isset($this->humans[$one]) && isset($this->ai[$other]) ? $one : (isset($this->humans[$other]) && isset($this->ai[$one]) ? $other : 0);
            if ($human !== 0) {
                $out['count']++;
                $out['per_human'][$human] = ($out['per_human'][$human] ?? 0) + 1;
            }
        }

        return $out;
    }

    /**
     * ACS defence fleets that arrived between a human and an AI account, either way: help given or taken, credited to
     * the human.
     *
     * @return array{count: int, per_human: array<int, int>}
     */
    private function assistance(CarbonImmutable $from, CarbonImmutable $now): array
    {
        $out = ['count' => 0, 'per_human' => []];
        foreach ($this->missionsWithTarget([self::ACS_DEFEND], $from, $now)->where('fleet_missions.time_arrival', '<=', $now->getTimestamp())->get() as $row) {
            $sender = (int) $row->user_id;
            $target = (int) $row->target_user_id;
            $human = isset($this->humans[$sender]) && isset($this->ai[$target]) ? $sender : (isset($this->ai[$sender]) && isset($this->humans[$target]) ? $target : 0);
            if ($human !== 0) {
                $out['count']++;
                $out['per_human'][$human] = ($out['per_human'][$human] ?? 0) + 1;
            }
        }

        return $out;
    }

    /**
     * Direct chats between a human and an AI account. A contact is a pair that exchanged anything; it is a two-way
     * conversation only when the AI answered something the human wrote, so an AI greeting nobody answered is not one.
     *
     * @return array{contacts: int, two_way: int, per_human: array<int, int>}
     */
    private function conversations(CarbonImmutable $from, CarbonImmutable $now): array
    {
        $pairs = [];
        $messages = ChatMessage::query()->whereNull('alliance_id')->whereNotNull('recipient_id')->whereBetween('created_at', [$from, $now])->oldest()->orderBy('id')->get(['sender_id', 'recipient_id']);
        foreach ($messages as $message) {
            $sender = (int) $message->sender_id;
            $recipient = (int) $message->recipient_id;
            $human = isset($this->humans[$sender]) && isset($this->ai[$recipient]) ? $sender : (isset($this->ai[$sender]) && isset($this->humans[$recipient]) ? $recipient : 0);
            if ($human === 0) {
                continue;
            }

            $key = $sender . ':' . $recipient;
            $pair = $human === $sender ? $key : $recipient . ':' . $sender;
            $pairs[$pair] ??= ['human' => $human, 'human_spoke' => false, 'answered' => false];
            if ($human === $sender) {
                $pairs[$pair]['human_spoke'] = true;
            } elseif ($pairs[$pair]['human_spoke']) {
                $pairs[$pair]['answered'] = true;
            }
        }

        $out = ['contacts' => count($pairs), 'two_way' => 0, 'per_human' => []];
        foreach ($pairs as $pair) {
            if ($pair['answered']) {
                $out['two_way']++;
                $out['per_human'][$pair['human']] = ($out['per_human'][$pair['human']] ?? 0) + 1;
            }
        }

        return $out;
    }

    /**
     * Hostile fleets AI accounts launched at human planets in the window (not recalled, outbound only).
     *
     * @return array{attacks: int, per_human: array<int, int>}
     */
    private function hostileMissions(CarbonImmutable $from, CarbonImmutable $now): array
    {
        $out = ['attacks' => 0, 'per_human' => []];
        foreach ($this->missionsWithTarget(self::HOSTILE_MISSIONS, $from, $now)->get() as $row) {
            $target = (int) $row->target_user_id;
            if (isset($this->ai[(int) $row->user_id]) && isset($this->humans[$target])) {
                $out['per_human'][$target] = ($out['per_human'][$target] ?? 0) + 1;
                $out['attacks'] += in_array((int) $row->mission_type, [1, 2], true) ? 1 : 0;
            }
        }

        return $out;
    }

    /**
     * @param list<int> $types
     */
    private function missionsWithTarget(array $types, CarbonImmutable $from, CarbonImmutable $now): Builder
    {
        return DB::table('fleet_missions')
            ->join('planets', 'planets.id', '=', 'fleet_missions.planet_id_to')
            ->whereNull('fleet_missions.parent_id')
            ->where('fleet_missions.canceled', 0)
            ->whereIn('fleet_missions.mission_type', $types)
            ->whereBetween('fleet_missions.time_departure', [$from->getTimestamp(), $now->getTimestamp()])
            ->select(['fleet_missions.user_id', 'fleet_missions.mission_type', 'planets.user_id as target_user_id']);
    }

    /**
     * @param array<int, int> $perHuman
     * @return array<string, mixed>
     */
    private function pressure(array $perHuman, int $days, CarbonImmutable $from, CarbonImmutable $now): array
    {
        $counts = array_map(static fn (int $human): int => $perHuman[$human] ?? 0, array_keys($this->humans));
        rsort($counts);
        $total = array_sum($counts);
        $topDecile = array_sum(array_slice($counts, 0, (int) ceil(count($counts) * 0.1)));
        $daily = array_map(static fn (int $count): float => $count / max(1, $days), array_reverse($counts));

        $level = (string) config('ai.cognition.social.human_pressure', 'medium');
        $cap = (int) $this->minds->setting('human_pressure_' . $level);
        $fromHour = $from->getTimestamp() / 3600.0;
        $capHour = $now->getTimestamp() / 3600.0 - self::PRESSURE_WINDOW_HOURS;
        $threats = 0;
        $atCap = 0;
        foreach (AiSocialMind::query()->whereIn('user_id', array_keys($this->ai))->get(['user_id', 'state']) as $mind) {
            // `pressed` holds the hour of every threat or tribute demand the leader sent a human-led club.
            $pressed = array_map('floatval', (array) ($mind->state['pressed'] ?? []));
            $threats += count(array_filter($pressed, static fn (float $at): bool => $at >= $fromHour));
            $atCap += count(array_filter($pressed, static fn (float $at): bool => $at >= $capHour)) >= $cap ? 1 : 0;
        }

        return [
            'hostile_missions' => $total,
            'per_human_day' => ['p50' => $this->percentile($daily, 0.5), 'p90' => $this->percentile($daily, 0.9), 'max' => $daily === [] ? 0.0 : round(max($daily), 2)],
            'top_decile_share' => $total > 0 ? round($topDecile / $total, 3) : 0.0,
            'threats_to_human_leaders' => $threats,
            'wars_on_human_alliances' => $this->warsOnHumans($from, $now),
            'cap_level' => $level,
            'cap_per_leader_week' => $cap,
            'ai_leaders_at_cap' => $atCap,
        ];
    }

    private function warsOnHumans(CarbonImmutable $from, CarbonImmutable $now): int
    {
        $founders = $this->founders;

        return AiAllianceRelation::query()->where('stance', AiAllianceStance::War)->whereBetween('started_at', [$from, $now])->get()
            ->filter(fn (AiAllianceRelation $war): bool => isset($this->ai[$founders[$war->alliance_id] ?? 0]) && isset($this->humans[$founders[$war->other_alliance_id] ?? 0]))
            ->count();
    }

    /**
     * Humans who lost more than the threshold of their worth to AI accounts in the window, and how many of them were
     * active after their last such battle. Worth is their general points (one per thousand resources spent) plus what
     * they lost, so the share is of what they had before the losses.
     *
     * @param array<int, array{lost: float, last: int}> $lost
     * @return array<string, mixed>
     */
    private function resilience(array $lost, float $threshold): array
    {
        $points = Highscore::query()->whereIn('player_id', array_keys($lost))->pluck('general', 'player_id');
        $active = User::query()->whereIn('id', array_keys($lost))->pluck('time', 'id');
        $hard = 0;
        $stayed = 0;
        foreach ($lost as $human => $loss) {
            $worth = (float) ($points[$human] ?? 0) * 1000.0 + $loss['lost'];
            if ($worth <= 0.0 || $loss['lost'] / $worth <= $threshold) {
                continue;
            }

            $hard++;
            $stayed += (int) ($active[$human] ?? 0) > $loss['last'] ? 1 : 0;
        }

        return [
            'loss_threshold' => $threshold,
            'hit_hard' => $hard,
            'active_after' => $stayed,
            'share_active_after' => $hard > 0 ? round($stayed / $hard, 3) : null,
        ];
    }

    /**
     * Day-7 and day-30 retention by cohort. The host keeps only the last activity (users.time), not a login history,
     * so "retained past day N" means last active at least N days after registering. New = registered in the last 30 days.
     *
     * @return array<string, array<string, array{eligible: int, retained: int, rate: float|null}>>
     */
    private function retention(CarbonImmutable $now): array
    {
        $out = [];
        $users = User::query()->whereIn('id', array_keys($this->humans))->get(['id', 'created_at', 'time']);
        foreach (['new' => true, 'returning' => false] as $cohort => $isNew) {
            foreach (['d7' => 7, 'd30' => 30] as $label => $day) {
                $eligible = $users->filter(static fn (User $user): bool => $user->created_at !== null
                    && ($user->created_at->getTimestamp() >= $now->subDays(30)->getTimestamp()) === $isNew
                    && $user->created_at->getTimestamp() <= $now->subDays($day)->getTimestamp());
                $retained = $eligible->filter(static fn (User $user): bool => (int) $user->time >= $user->created_at?->getTimestamp() + $day * 86400)->count();
                $out[$cohort][$label] = ['eligible' => $eligible->count(), 'retained' => $retained, 'rate' => $eligible->count() > 0 ? round($retained / $eligible->count(), 3) : null];
            }
        }

        return $out;
    }

    /**
     * Humans who put an AI account on their ignore list is the one opt-out the host records. The host keeps no player
     * reports (complaints) table, so that guardrail reads null rather than zero.
     *
     * @return array<string, int|null>
     */
    private function guardrails(CarbonImmutable $from, CarbonImmutable $now): array
    {
        if (!Schema::hasTable('ignored_players')) {
            return ['ai_ignored_by_humans' => null, 'reports' => null];
        }

        $ignores = DB::table('ignored_players')->whereIn('user_id', array_keys($this->humans))->whereIn('ignored_user_id', array_keys($this->ai));

        return [
            'ai_ignored_by_humans' => (clone $ignores)->whereBetween('created_at', [$from, $now])->count(),
            'ai_ignored_total' => $ignores->count(),
            'reports' => null,
        ];
    }

    /** @param list<float> $sorted ascending */
    private function percentile(array $sorted, float $q): float
    {
        return $sorted === [] ? 0.0 : round($sorted[(int) floor($q * (count($sorted) - 1))], 2);
    }
}
