<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Domain\Decision\Enemies;
use Modules\AI\Domain\Social\AllianceAmbition;
use Modules\AI\Enums\AiAllianceStance;
use Modules\AI\Models\AiAllianceRelation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use OGame\Models\Alliance;
use OGame\Models\Highscore;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Symfony\Component\Yaml\Yaml;

/**
 * What AI-led alliances do to each other (alliance-politics.md). Nothing is staged; each move answers what players did:
 * - war: on the alliance its members hold the most grievance against (the threat recorded after being raided), when
 *   past the line and larger than the other side's, so a war starts where empires have been hitting each other;
 * - pact: with an alliance at war with the same enemy, the enemy of my enemy, when the other leader does not hate it;
 * - betrayal: a leader of the betraying kind breaks a pact once no war is left and it has outgrown the partner;
 * - conspiracy: when one alliance holds most of the points, the two strongest runners-up agree in private on a pact and
 *   a shared war on it, so the dominant alliance meets a coalition, the great war of a server's end.
 * The leader announces each one to its members, the other leader and the board, as OGame players do.
 */
class AdvanceAiAlliancePoliticsAction
{
    private const FILE = '/resources/behavior/alliance-war.yaml';

    /** @var array<string, mixed> */
    private array $policy = [];

    public function handle(): int
    {
        $file = Yaml::parseFile(dirname(__DIR__, 2) . self::FILE);
        $this->policy = is_array($file) ? $file : [];
        $founders = AiProfile::query()->where('enabled', true)->pluck('player_id')->all();
        $moves = $this->conspire($founders);

        foreach (Alliance::query()->whereIn('founder_user_id', $founders)->orderBy('id')->get() as $alliance) {
            $moves += $this->declareWar($alliance) + $this->offerPact($alliance, $founders) + $this->betrayPact($alliance);
        }

        return $moves;
    }

    /** @param list<int> $founders */
    private function conspire(array $founders): int
    {
        $points = Alliance::query()->pluck('id')->mapWithKeys(fn ($id): array => [(int) $id => $this->points((int) $id)])->sortDesc();
        $dominantId = (int) $points->keys()->first();
        if ($points->count() < 3 || $points->first() <= (float) ($this->policy['coalition_share'] ?? 0.5) * $points->sum()) {
            return 0;
        }

        $plotters = Alliance::query()->whereIn('id', $points->keys()->slice(1)->all())->whereIn('founder_user_id', $founders)->get()
            ->sortByDesc(fn (Alliance $alliance): float => $points[$alliance->id])->take(2)->values();
        if ($plotters->count() < 2) {
            return 0;
        }

        [$first, $second] = [$plotters[0], $plotters[1]];
        $atWar = $this->open((int) $first->id, $dominantId, AiAllianceStance::War) && $this->open((int) $second->id, $dominantId, AiAllianceStance::War);
        if ($atWar || $this->open((int) $first->id, (int) $second->id, AiAllianceStance::War)
            || $this->hates((int) $first->founder_user_id, $this->members((int) $second->id))
            || $this->hates((int) $second->founder_user_id, $this->members((int) $first->id))) {
            return 0;
        }

        $dominant = Alliance::query()->findOrFail($dominantId);
        // Leader to leader only: the members learn of the plot from the war, not before.
        if (!AiAllianceRelation::query()->openBetween((int) $first->id, (int) $second->id)->exists()) {
            app(DeliverAiDirectReplyAction::class)->handle((int) $first->founder_user_id, (int) $second->founder_user_id, $this->line('conspiracy_offer', (string) $dominant->alliance_tag));
            AiAllianceRelation::query()->create([
                'alliance_id' => $first->id,
                'other_alliance_id' => $second->id,
                'stance' => AiAllianceStance::Nap,
                'cause' => 'coalition',
                'declared_by' => $first->founder_user_id,
                'started_at' => now(),
            ]);
            app(DeliverAiDirectReplyAction::class)->handle((int) $second->founder_user_id, (int) $first->founder_user_id, $this->line('conspiracy_accept', (string) $dominant->alliance_tag));
        }

        foreach ([$first, $second] as $plotter) {
            if (!$this->open((int) $plotter->id, $dominantId, AiAllianceStance::War)) {
                $this->goToWar($plotter, $dominant, 'coalition', 'war_broadcast', 'war_to_leader');
            }
        }

        return 1;
    }

    private function declareWar(Alliance $alliance): int
    {
        $line = (float) ($this->policy['war_grievance'] ?? 1.5);
        $members = $this->members((int) $alliance->id);
        $grievances = [];

        foreach (Alliance::query()->whereKeyNot($alliance->id)->pluck('id') as $otherId) {
            $theirs = $this->members((int) $otherId);
            $mine = $this->grievance($members, $theirs);
            if ($mine >= $line && $mine >= $this->grievance($theirs, $members) && !$this->open((int) $alliance->id, (int) $otherId, AiAllianceStance::War)) {
                $grievances[(int) $otherId] = $mine;
            }
        }

        if ($grievances === []) {
            return 0;
        }

        arsort($grievances);
        $this->goToWar($alliance, Alliance::query()->findOrFail(array_key_first($grievances)), 'grievance', 'war_broadcast', 'war_to_leader');

        return 1;
    }

    /** @param list<int> $founders */
    private function offerPact(Alliance $alliance, array $founders): int
    {
        $enemies = AiAllianceRelation::counterparts((int) $alliance->id, AiAllianceStance::War);

        foreach ($enemies as $enemyId) {
            foreach (AiAllianceRelation::counterparts($enemyId, AiAllianceStance::War) as $partnerId) {
                $partner = Alliance::query()->find($partnerId);
                // Only an AI leader answers here; a human leader's reply needs the classifier (alliance-politics.md S4).
                if ($partner === null || $partnerId === $alliance->id || !in_array($partner->founder_user_id, $founders, true)
                    || AiAllianceRelation::query()->openBetween((int) $alliance->id, $partnerId)->exists()
                    || $this->hates((int) $alliance->founder_user_id, $this->members($partnerId))) {
                    continue;
                }

                $enemyTag = (string) Alliance::query()->whereKey($enemyId)->value('alliance_tag');
                app(DeliverAiDirectReplyAction::class)->handle((int) $alliance->founder_user_id, (int) $partner->founder_user_id, $this->line('pact_offer', $enemyTag));
                if ($this->hates((int) $partner->founder_user_id, $this->members((int) $alliance->id))) {
                    continue;
                }

                AiAllianceRelation::query()->create([
                    'alliance_id' => $alliance->id,
                    'other_alliance_id' => $partnerId,
                    'stance' => AiAllianceStance::Nap,
                    'cause' => 'common-enemy',
                    'declared_by' => $alliance->founder_user_id,
                    'started_at' => now(),
                ]);
                app(DeliverAiDirectReplyAction::class)->handle((int) $partner->founder_user_id, (int) $alliance->founder_user_id, $this->line('pact_accept', (string) $alliance->alliance_tag));

                return 1;
            }
        }

        return 0;
    }

    private function betrayPact(Alliance $alliance): int
    {
        $founderId = (int) $alliance->founder_user_id;
        if (AiAllianceRelation::counterparts((int) $alliance->id, AiAllianceStance::War) !== [] || !app(AllianceAmbition::class)->betrays($founderId)) {
            return 0;
        }

        $ratio = (float) ($this->policy['betray_strength_ratio'] ?? 2.0);
        $strength = $this->points((int) $alliance->id);

        foreach (AiAllianceRelation::counterparts((int) $alliance->id, AiAllianceStance::Nap) as $partnerId) {
            if ($strength >= $ratio * max(1.0, $this->points($partnerId))) {
                $this->goToWar($alliance, Alliance::query()->findOrFail($partnerId), 'betrayal', 'betrayal_broadcast', 'betrayal_to_leader');

                return 1;
            }
        }

        return 0;
    }

    private function goToWar(Alliance $alliance, Alliance $enemy, string $cause, string $broadcast, string $toLeader): void
    {
        $founderId = (int) $alliance->founder_user_id;
        $tag = (string) $enemy->alliance_tag;

        // A pact between the two ends the moment one declares: that is the betrayal the other side remembers.
        AiAllianceRelation::query()->openBetween((int) $alliance->id, (int) $enemy->id)->update(['ended_at' => now()]);
        AiAllianceRelation::query()->create([
            'alliance_id' => $alliance->id,
            'other_alliance_id' => $enemy->id,
            'stance' => AiAllianceStance::War,
            'cause' => $cause,
            'declared_by' => $founderId,
            'started_at' => now(),
        ]);

        // The announcements are the visible half; the host refusing one (permission, length) leaves the war standing.
        $service = app(AllianceService::class);
        try {
            $service->sendBroadcastMessage((int) $alliance->id, $founderId, $this->line($broadcast, $tag), ['-1']);
            $external = trim((string) $alliance->external_text . "\n" . $this->line('war_external', $tag));
            $service->updateTexts((int) $alliance->id, $founderId, $alliance->internal_text, $external, $alliance->application_text);
        } catch (Exception) {
        }

        if ($enemy->founder_user_id !== null) {
            app(DeliverAiDirectReplyAction::class)->handle($founderId, (int) $enemy->founder_user_id, $this->line($toLeader, $tag));
        }
    }

    private function open(int $allianceId, int $otherId, AiAllianceStance $stance): bool
    {
        return AiAllianceRelation::query()->openBetween($allianceId, $otherId)->where('stance', $stance)->exists();
    }

    /** @param list<int> $players */
    private function hates(int $playerId, array $players): bool
    {
        return AiRelationship::query()->where('player_id', $playerId)->whereIn('other_player_id', $players)->where('threat', '>=', Enemies::ENEMY_THREAT)->exists();
    }

    /**
     * @param list<int> $from
     * @param list<int> $toward
     */
    private function grievance(array $from, array $toward): float
    {
        return (float) AiRelationship::query()->whereIn('player_id', $from)->whereIn('other_player_id', $toward)->sum('threat');
    }

    private function points(int $allianceId): float
    {
        return (float) Highscore::query()->whereIn('player_id', $this->members($allianceId))->sum('general');
    }

    /** @return list<int> */
    private function members(int $allianceId): array
    {
        return User::query()->where('alliance_id', $allianceId)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function line(string $key, string $tag): string
    {
        return str_replace('{tag}', $tag, (string) (((array) ($this->policy['lines'] ?? []))[$key] ?? ''));
    }
}
