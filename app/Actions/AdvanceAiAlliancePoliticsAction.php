<?php

namespace Modules\AI\Actions;

use Exception;
use Laravel\Ai\Classification\Choice;
use Modules\AI\Domain\Decision\Enemies;
use Modules\AI\Domain\Social\AllianceAmbition;
use Modules\AI\Enums\AiAllianceStance;
use Modules\AI\Models\AiAllianceRelation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use OGame\Models\Alliance;
use OGame\Models\ChatMessage;
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
 *
 * Humans take part through the same rows: a human leader's message (read by the classifier) declares war, offers,
 * accepts or breaks a pact, and a human alliance's page that declares war on, or lists a pact with, an AI alliance
 * counts as said (llm-classifier-everywhere.md, step 3). With the classifier off only AI leaders take part.
 */
class AdvanceAiAlliancePoliticsAction
{
    private const FILE = '/resources/behavior/alliance-war.yaml';

    /** A pact offer to a human stands this long: offered once, then awaited, then offered again. */
    private const OFFER_DAYS = 3;

    /** @var array<string, mixed> */
    private array $policy = [];

    public function handle(): int
    {
        $this->loadPolicy();
        $founders = AiProfile::query()->where('enabled', true)->pluck('player_id')->all();
        $moves = $this->makePeace() + $this->conspire($founders) + $this->readBoards($founders);

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

    /**
     * A war ends when it has run its course, not on a timer (wiki: peace or surrender): once it has stood the minimum and
     * what the two sides still hold against each other, both ways, has faded under the peace line, the founder who
     * declared it announces peace. A war a coalition is fighting on a dominant alliance stays until that one shrinks.
     */
    private function makePeace(): int
    {
        $peace = (float) ($this->policy['peace_grievance'] ?? 0.0);
        $minimum = (int) ($this->policy['war_minimum_hours'] ?? 0);
        $ended = 0;

        foreach (AiAllianceRelation::query()->whereNull('ended_at')->where('stance', AiAllianceStance::War)->where('cause', '!=', 'coalition')
            ->where('started_at', '<=', now()->subHours($minimum))->get() as $war) {
            $ours = $this->members((int) $war->alliance_id);
            $theirs = $this->members((int) $war->other_alliance_id);
            if ($this->grievance($ours, $theirs) + $this->grievance($theirs, $ours) >= $peace) {
                continue;
            }

            $war->update(['ended_at' => now()]);
            $this->announcePeace($war);
            $ended++;
        }

        return $ended;
    }

    private function announcePeace(AiAllianceRelation $war): void
    {
        $declarer = Alliance::query()->find($war->alliance_id);
        $other = Alliance::query()->find($war->other_alliance_id);
        if ($declarer === null || $other === null) {
            return;
        }

        try {
            app(AllianceService::class)->sendBroadcastMessage((int) $declarer->id, (int) $declarer->founder_user_id, $this->line('peace_broadcast', (string) $other->alliance_tag), ['-1']);
        } catch (Exception) {
        }
    }

    private function declareWar(Alliance $alliance): int
    {
        $line = (float) ($this->policy['war_grievance'] ?? 1.5);
        // A veteran leader does not open a second front while one war is on (the judge on 9444: a club declared on two
        // alliances in eight minutes and lost both exchanges).
        if (count(AiAllianceRelation::counterparts((int) $alliance->id, AiAllianceStance::War)) >= (int) ($this->policy['max_open_wars'] ?? PHP_INT_MAX)) {
            return 0;
        }

        $members = $this->members((int) $alliance->id);
        $grievances = [];

        foreach (Alliance::query()->whereKeyNot($alliance->id)->pluck('id') as $otherId) {
            $theirs = $this->members((int) $otherId);
            $mine = $this->grievance($members, $theirs);
            if ($this->outmatched((int) $alliance->id, (int) $otherId)) {
                continue;
            }

            if ($mine >= $line * $this->allyFactor((int) $alliance->id, (int) $otherId) && $mine >= $this->grievance($theirs, $members) && !$this->open((int) $alliance->id, (int) $otherId, AiAllianceStance::War)) {
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

    /**
     * A veteran does not declare a war it cannot trade: the judge read a two-member club that declared on one hit five
     * times as often as it hit back (9437). An alliance more than this many times weaker in points sits the grudge out,
     * and the grudge fades (grudges.yaml) until it grows or a partner is fighting the same side.
     */
    private function outmatched(int $allianceId, int $enemyId): bool
    {
        $ratio = (float) ($this->policy['war_max_strength_ratio'] ?? 0.0);

        return $ratio > 0.0 && $this->points($enemyId) > $ratio * max(1.0, $this->points($allianceId))
            && $this->allyFactor($allianceId, $enemyId) >= 1.0;
    }

    /**
     * "Allies are expected to support each other in war-time" (alliance guide): the grievance an alliance needs to join a
     * war falls to this share of the usual line when a pact partner is already fighting the other side.
     */
    private function allyFactor(int $allianceId, int $enemyId): float
    {
        $partners = AiAllianceRelation::counterparts($allianceId, AiAllianceStance::Nap);
        $fighting = array_intersect($partners, AiAllianceRelation::counterparts($enemyId, AiAllianceStance::War));

        return $fighting === [] ? 1.0 : (float) ($this->policy['ally_war_factor'] ?? 1.0);
    }

    /**
     * A human leader's war or pact message to an AI leader, as the classifier read its act. The answer is the AI
     * leader's own: a pact is taken unless it hates the other side, an acceptance counts only for a pact it offered.
     */
    public function answerHumanLeader(int $senderId, int $recipientId, string $act): bool
    {
        $this->loadPolicy();
        $theirs = Alliance::query()->where('founder_user_id', $senderId)->first();
        $ours = Alliance::query()->where('founder_user_id', $recipientId)->first();
        if ($theirs === null || $ours === null || !AiProfile::query()->where('player_id', $recipientId)->where('enabled', true)->exists()) {
            return false;
        }

        return match ($act) {
            'war' => $this->warDeclaredOn($ours, $theirs, 'declared'),
            'pact_offer' => $this->pactOffered($ours, $theirs, 'offered', true),
            'pact_accept' => $this->offeredTo($ours, $senderId) && $this->pactOffered($ours, $theirs, 'accepted', false),
            'pact_break' => AiAllianceRelation::query()->openBetween((int) $ours->id, (int) $theirs->id)->where('stance', AiAllianceStance::Nap)->update(['ended_at' => now()]) > 0,
            default => false,
        };
    }

    /**
     * A human alliance's external page is where players post wars and pacts ("War: [X]", "NAP [Y]"). Each page is read
     * once per AI-led alliance it might name, and only again when its text changes (the readings are the cache).
     *
     * @param list<int> $founders
     */
    private function readBoards(array $founders): int
    {
        $moves = 0;
        $boards = Alliance::query()->whereNotIn('founder_user_id', $founders)->whereNotNull('external_text')->where('external_text', '!=', '')->get();

        foreach ($boards as $board) {
            foreach (Alliance::query()->whereIn('founder_user_id', $founders)->get() as $ours) {
                $reading = app(ReadAiTextAction::class)->handle('board:' . $ours->id, (string) $board->external_text, [
                    'stance' => new Choice("What does this OGame alliance page say about the alliance [{$ours->alliance_tag}]?", [
                        'war' => "it declares or lists a war with [{$ours->alliance_tag}]",
                        'nap' => "it lists a pact or non-aggression with [{$ours->alliance_tag}]",
                        'none' => "it says nothing about [{$ours->alliance_tag}]",
                    ]),
                ]);

                $moves += (int) match ($reading?->choice('stance')) {
                    'war' => $this->warDeclaredOn($ours, $board, 'board'),
                    'nap' => $this->pactOffered($ours, $board, 'board', false),
                    default => false,
                };
            }
        }

        return $moves;
    }

    private function warDeclaredOn(Alliance $ours, Alliance $theirs, string $cause): bool
    {
        if ($this->open((int) $ours->id, (int) $theirs->id, AiAllianceStance::War)) {
            return false;
        }

        AiAllianceRelation::query()->openBetween((int) $ours->id, (int) $theirs->id)->update(['ended_at' => now()]);
        AiAllianceRelation::query()->create([
            'alliance_id' => $theirs->id,
            'other_alliance_id' => $ours->id,
            'stance' => AiAllianceStance::War,
            'cause' => $cause,
            'declared_by' => $theirs->founder_user_id,
            'started_at' => now(),
        ]);

        try {
            app(AllianceService::class)->sendBroadcastMessage((int) $ours->id, (int) $ours->founder_user_id, $this->line('war_declared_on_us', (string) $theirs->alliance_tag), ['-1']);
        } catch (Exception) {
        }

        return true;
    }

    /** The AI leader takes a pact unless it already holds a stance toward them or hates them; $answer says whether it replies. */
    private function pactOffered(Alliance $ours, Alliance $theirs, string $cause, bool $answer): bool
    {
        $founderId = (int) $ours->founder_user_id;
        if (AiAllianceRelation::query()->openBetween((int) $ours->id, (int) $theirs->id)->exists()) {
            return false;
        }

        if ($this->hates($founderId, $this->members((int) $theirs->id))) {
            if ($answer) {
                app(DeliverAiDirectReplyAction::class)->handle($founderId, (int) $theirs->founder_user_id, $this->line('pact_refuse', (string) $theirs->alliance_tag));
            }

            return false;
        }

        AiAllianceRelation::query()->create([
            'alliance_id' => $theirs->id,
            'other_alliance_id' => $ours->id,
            'stance' => AiAllianceStance::Nap,
            'cause' => $cause,
            'declared_by' => $theirs->founder_user_id,
            'started_at' => now(),
        ]);
        if ($answer) {
            app(DeliverAiDirectReplyAction::class)->handle($founderId, (int) $theirs->founder_user_id, $this->line('pact_accept', (string) $theirs->alliance_tag));
        }

        return true;
    }

    /** Whether the AI leader offered this human a pact lately: the offer is the message it sent, no other record. */
    private function offeredTo(Alliance $ours, int $humanId): bool
    {
        $offers = (array) (((array) ($this->policy['lines'] ?? []))['pact_offer'] ?? []);
        $openings = array_map(static fn (string $offer): string => substr($offer, 0, (int) strpos($offer . '[', '[')), array_values($offers));

        return ChatMessage::query()->where('sender_id', $ours->founder_user_id)->where('recipient_id', $humanId)
            ->where('created_at', '>=', now()->subDays(self::OFFER_DAYS))
            ->where(fn ($query) => array_reduce($openings, static fn ($query, string $opening) => $query->orWhere('message', 'like', $opening . '%'), $query))->exists();
    }

    /** @param list<int> $founders */
    private function offerPact(Alliance $alliance, array $founders): int
    {
        $enemies = AiAllianceRelation::counterparts((int) $alliance->id, AiAllianceStance::War);

        foreach ($enemies as $enemyId) {
            foreach (AiAllianceRelation::counterparts($enemyId, AiAllianceStance::War) as $partnerId) {
                $partner = Alliance::query()->find($partnerId);
                if ($partner === null || $partnerId === $alliance->id || $partner->founder_user_id === null
                    || AiAllianceRelation::query()->openBetween((int) $alliance->id, $partnerId)->exists()
                    || $this->hates((int) $alliance->founder_user_id, $this->members($partnerId))) {
                    continue;
                }

                // A human leader answers in their own words (answerHumanLeader), so the offer is made once and awaited.
                $human = !in_array($partner->founder_user_id, $founders, true);
                if ($human && $this->offeredTo($alliance, (int) $partner->founder_user_id)) {
                    continue;
                }

                $enemyTag = (string) Alliance::query()->whereKey($enemyId)->value('alliance_tag');
                app(DeliverAiDirectReplyAction::class)->handle((int) $alliance->founder_user_id, (int) $partner->founder_user_id, $this->line('pact_offer', $enemyTag));
                if ($human) {
                    return 1;
                }

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

    private function loadPolicy(): void
    {
        $file = Yaml::parseFile(dirname(__DIR__, 2) . self::FILE);
        $this->policy = is_array($file) ? $file : [];
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
        $line = ((array) ($this->policy['lines'] ?? []))[$key] ?? '';
        // Several wordings per line, picked by the other alliance's tag so the same declaration is not repeated verbatim.
        $line = is_array($line) ? (string) ($line[crc32($key . $tag) % max(1, count($line))] ?? '') : (string) $line;

        return str_replace('{tag}', $tag, $line);
    }
}
