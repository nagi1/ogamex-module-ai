<?php

namespace Modules\AI\Actions;

use Exception;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Classification\Choice;
use Modules\AI\Contracts\AllianceTreaties;
use Modules\AI\Domain\Advisor\Advisor;
use Modules\AI\Domain\Advisor\AdvisorArea;
use Modules\AI\Domain\Advisor\AdvisorFacts;
use Modules\AI\Domain\Advisor\AdvisorQuestion;
use Modules\AI\Domain\Decision\Enemies;
use Modules\AI\Domain\Social\AllianceAmbition;
use Modules\AI\Domain\Social\SocialMinds;
use Modules\AI\Enums\AiAllianceStance;
use Modules\AI\Models\AiAllianceCohesion;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use OGame\GameMissions\TransportMission;
use OGame\Models\Alliance;
use OGame\Models\ChatMessage;
use OGame\Models\FleetMission;
use OGame\Models\Highscore;
use OGame\Models\Planet;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Symfony\Component\Yaml\Yaml;

/**
 * What AI-led alliances do to each other (alliance-politics.md). Nothing is staged; each move answers what players did:
 * - war: on the alliance its members hold the most grievance against (the threat recorded after being raided), when
 *   past the line and larger than the other side's, so a war starts where empires have been hitting each other;
 * - pact: with an alliance at war with the same enemy, the enemy of my enemy, when the other leader does not hate it;
 * - betrayal: a leader of the betraying kind turns on a pact partner once no war is left and it has outgrown it, by
 *   notice (the pact binds pact_notice_hours more, then war), or by surprise only when it is cunning and remembers a
 *   grievance toward the partner over its gratitude (DIPLOMACY-BETRAYAL P1; the surprise break is the taboo);
 * - conspiracy: when one alliance holds most of the points, the two strongest runners-up agree in private on a pact and
 *   a shared war on it, so the dominant alliance meets a coalition, the great war of a server's end.
 * The leader announces each one to its members, the other leader and the board, as OGame players do.
 *
 * With living minds on (ai.cognition.social.minds) and the social gateway answering, AI leaders also decide as the
 * simulator's arm `minds` does (rl/ogsim src/diplomacy.rs): PsychSim weighs a pact and a partner, FAtiMA feels, AgentOS
 * remembers who did what, CBRKit recalls how similar pacts ended; each account's mind records only what it saw.
 *
 * With cycles on as well (ai.cognition.social.cycles), clubs rise and fall as the simulator's arm `cycles` has them:
 * asabiyya wears in peace and grows in war, a soft club with too many ambitious members splits, and a soft ruler is
 * overrun by a band of independents. Accounts move only through the host's own leave, found and join.
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

    /** The window the human pressure cap counts threats in. */
    private const PRESSURE_WINDOW_HOURS = 168;

    /** The legal answers to a pact offer, for the advisor. */
    private const ANSWER_OFFER = ['accept' => 'sign the pact', 'decline' => 'refuse the pact'];

    private const KEEP_OR_CANCEL = ['keep' => 'keep the pact', 'cancel' => 'cancel the pact by notice ({hours} h), then war'];

    private const ANSWER_DEMAND = ['Submit' => 'pay the tribute and become their vassal', 'Revolt' => 'refuse and meet their war', 'Ignore' => 'ignore the demand (they read it as a refusal)'];

    /** @var array<string, mixed> */
    private array $policy = [];

    /** Living minds: recording while the flag is on, deciding while the gateway answers, the daily pass once a day. */
    private SocialMinds|null $minds = null;

    private bool $thinking = false;

    private bool $daily = false;

    /** The persona's day (leaks, tribute): once a day with the minds on, whether or not the gateway answers. */
    private bool $today = false;

    /** @var array<int, int> each club's standing leader this pass (governance), the founder until one is recorded */
    private array $leaders = [];

    /** @var list<array{0: string, 1: int, 2: int}> this pass's treaty events: kind, by alliance, with alliance */
    private array $events = [];

    /** @var array<string, array<string, mixed>|null> PsychSim's answer per "club:partner" */
    private array $inside = [];

    /** @var array<string, string|null> FAtiMA's chosen exchange per "club:partner" */
    private array $felt = [];

    /**
     * Members, founders and points read once per pass (a pass asked the host 18k times, 19 s on 33 clubs). Off while the
     * cycles move accounts between clubs, so a split or a gather never reads a roster it just changed.
     *
     * @var array<string, mixed>|null
     */
    private array|null $read = null;

    public function __construct(private readonly AllianceTreaties $treaties)
    {
    }

    /**
     * One politics pass at a time: the minds are loaded, changed and saved within a pass, so two concurrent passes (two
     * sessions ticking at once) would overwrite each other's memories. A pass that finds another running skips.
     */
    public function handle(): int
    {
        $lock = Cache::lock('ai:alliance-politics', 300);
        if (!$lock->get()) {
            return 0;
        }

        try {
            return $this->pass();
        } finally {
            $lock->release();
        }
    }

    private function pass(): int
    {
        $this->loadPolicy();
        $this->wakeMinds(true);
        $founders = array_values(AiProfile::query()->where('enabled', true)->pluck('player_id')->map(static fn ($id): int => (int) $id)->all());
        $this->govern($founders);
        $moves = $this->endNotices() + $this->makePeace() + $this->conspire($founders) + $this->readBoards($founders);
        if ($this->today) {
            $this->leak($founders);
            if (config('ai.cognition.social.tribute')) {
                $this->payTribute($founders);
            }

            if (config('ai.cognition.social.cycles')) {
                $moves += $this->cycle($founders);
            }
        }

        $moves += $this->daily ? $this->think($founders) : 0;

        foreach (Alliance::query()->whereIn('founder_user_id', $founders)->orderBy('id')->get() as $alliance) {
            $moves += $this->answerOffers($alliance) + $this->declareWar($alliance) + $this->offerPact($alliance, $founders) + $this->keepOrBreak($alliance);
        }

        $this->remember();

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
        $atWar = $this->treaties->holds((int) $first->id, $dominantId, AiAllianceStance::War) && $this->treaties->holds((int) $second->id, $dominantId, AiAllianceStance::War);
        if ($atWar || $this->treaties->holds((int) $first->id, (int) $second->id, AiAllianceStance::War)
            || $this->hates((int) $first->founder_user_id, $this->members((int) $second->id))
            || $this->hates((int) $second->founder_user_id, $this->members((int) $first->id))) {
            return 0;
        }

        $dominant = Alliance::query()->findOrFail($dominantId);
        // Leader to leader only: the members learn of the plot from the war, not before.
        if (!$this->treaties->related((int) $first->id, (int) $second->id)) {
            app(DeliverAiDirectReplyAction::class)->handle((int) $first->founder_user_id, (int) $second->founder_user_id, $this->line('conspiracy_offer', (string) $dominant->alliance_tag, (int) $first->founder_user_id, (int) $second->founder_user_id));
            $this->treaties->openPact((int) $first->id, (int) $second->id, 'coalition', (int) $first->founder_user_id);
            $this->events[] = ['pact', (int) $first->id, (int) $second->id];
            app(DeliverAiDirectReplyAction::class)->handle((int) $second->founder_user_id, (int) $first->founder_user_id, $this->line('conspiracy_accept', (string) $dominant->alliance_tag, (int) $second->founder_user_id, (int) $first->founder_user_id));
        }

        foreach ([$first, $second] as $plotter) {
            if (!$this->treaties->holds((int) $plotter->id, $dominantId, AiAllianceStance::War)) {
                $this->goToWar($plotter, $dominant, 'coalition', 'war_broadcast', 'war_to_leader');
            }
        }

        return 1;
    }

    /**
     * A war ends when it has run its course, not on a timer (wiki: peace, or one side yielding): once it has stood the minimum and
     * what the two sides still hold against each other, both ways, has faded under the peace line, the founder who
     * declared it announces peace. A war a coalition is fighting on a dominant alliance stays until that one shrinks.
     */
    private function makePeace(): int
    {
        $peace = (float) ($this->policy['peace_grievance'] ?? 0.0);
        $minimum = (int) ($this->policy['war_minimum_hours'] ?? 0);
        $ended = 0;

        foreach ($this->treaties->warsStartedBy(now()->subHours($minimum), 'coalition') as [$declarerId, $otherId]) {
            $ours = $this->members($declarerId);
            $theirs = $this->members($otherId);
            if ($this->grievance($ours, $theirs) + $this->grievance($theirs, $ours) >= $peace || $this->campaignHolds($declarerId, $otherId)) {
                continue;
            }

            $this->treaties->endWar($declarerId, $otherId);
            $this->events[] = ['peace', $declarerId, $otherId];
            $this->minds?->put($this->founder($declarerId), 'wars', array_diff_key((array) $this->minds->get($this->founder($declarerId), 'wars', []), [$otherId => true]));
            $this->announcePeace($declarerId, $otherId);
            $ended++;
        }

        return $ended;
    }

    private function announcePeace(int $declarerId, int $otherId): void
    {
        $declarer = Alliance::query()->find($declarerId);
        $other = Alliance::query()->find($otherId);
        if ($declarer === null || $other === null) {
            return;
        }

        try {
            app(AllianceService::class)->sendBroadcastMessage((int) $declarer->id, (int) $declarer->founder_user_id, $this->line('peace_broadcast', (string) $other->alliance_tag, (int) $declarer->founder_user_id), ['-1']);
        } catch (Exception) {
        }
    }

    private function declareWar(Alliance $alliance): int
    {
        $line = (float) ($this->policy['war_grievance'] ?? 1.5);
        // A veteran leader does not open a second front while one war is on (the judge on 9444: a club declared on two
        // alliances in eight minutes and lost both exchanges).
        if (count($this->treaties->counterparts((int) $alliance->id, AiAllianceStance::War)) >= (int) ($this->policy['max_open_wars'] ?? PHP_INT_MAX)) {
            return 0;
        }

        $members = $this->members((int) $alliance->id);
        $grievances = [];

        foreach (Alliance::query()->whereKeyNot($alliance->id)->pluck('id') as $otherId) {
            $theirs = $this->members((int) $otherId);
            $mine = $this->grievance($members, $theirs);
            // A pact partner is not a war target: a pact ends by notice or by a betrayal the leader chooses (keepOrBreak), as
            // the simulator's war step skips bound clubs (diplomacy.rs `!self.bound(c, o)`); the live run broke ten fresh pacts
            // by grievance at the instant they were signed.
            if ($this->outmatched((int) $alliance->id, (int) $otherId) || $this->bound((int) $alliance->id, (int) $otherId)) {
                continue;
            }

            if ($mine >= $line * $this->allyFactor((int) $alliance->id, (int) $otherId) && $mine >= $this->grievance($theirs, $members) && !$this->treaties->holds((int) $alliance->id, (int) $otherId, AiAllianceStance::War)) {
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
        $partners = $this->treaties->counterparts($allianceId, AiAllianceStance::Nap);
        $fighting = array_intersect($partners, $this->treaties->counterparts($enemyId, AiAllianceStance::War));

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

        $this->wakeMinds(false);
        $answered = match ($act) {
            'war' => $this->warDeclaredOn($ours, $theirs, 'declared'),
            'pact_offer' => $this->pactOffered($ours, $theirs, 'offered', true),
            'pact_accept' => $this->offeredTo($ours, $senderId) && $this->pactOffered($ours, $theirs, 'accepted', false),
            'pact_break' => $this->treaties->breakPact((int) $theirs->id, (int) $ours->id, $senderId) && $this->logged('betrayal', (int) $theirs->id, (int) $ours->id),
            default => false,
        };
        $this->remember();

        return $answered;
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
        if ($this->treaties->holds((int) $ours->id, (int) $theirs->id, AiAllianceStance::War)) {
            return false;
        }

        $this->treaties->openWar((int) $theirs->id, (int) $ours->id, $cause, (int) $theirs->founder_user_id);
        $this->events[] = ['war', (int) $theirs->id, (int) $ours->id];

        try {
            app(AllianceService::class)->sendBroadcastMessage((int) $ours->id, (int) $ours->founder_user_id, $this->line('war_declared_on_us', (string) $theirs->alliance_tag, (int) $ours->founder_user_id), ['-1']);
        } catch (Exception) {
        }

        return true;
    }

    /** The AI leader takes a pact unless it already holds a stance toward them or hates them; $answer says whether it replies. */
    private function pactOffered(Alliance $ours, Alliance $theirs, string $cause, bool $answer): bool
    {
        $founderId = (int) $ours->founder_user_id;
        if ($this->treaties->related((int) $ours->id, (int) $theirs->id)) {
            return false;
        }

        $refuses = $this->hates($founderId, $this->members((int) $theirs->id)) || $this->distrusts((int) $ours->id, (int) $theirs->id);
        if ($cause === 'offered') {
            $refuses = $this->advise('pact_offer', (int) $ours->id, (int) $theirs->id, self::ANSWER_OFFER, $refuses ? 'decline' : 'accept') === 'decline';
        }

        if ($refuses) {
            if ($answer) {
                app(DeliverAiDirectReplyAction::class)->handle($founderId, (int) $theirs->founder_user_id, $this->line('pact_refuse', (string) $theirs->alliance_tag, $founderId, (int) $theirs->founder_user_id));
            }

            $this->events[] = ['pact_refused', (int) $ours->id, (int) $theirs->id];

            return false;
        }

        $this->treaties->openPact((int) $theirs->id, (int) $ours->id, $cause, (int) $theirs->founder_user_id);
        $this->events[] = ['pact', (int) $theirs->id, (int) $ours->id];
        $this->keepSecret((int) $theirs->id, (int) $ours->id);
        if ($answer) {
            app(DeliverAiDirectReplyAction::class)->handle($founderId, (int) $theirs->founder_user_id, $this->line('pact_accept', (string) $theirs->alliance_tag, $founderId, (int) $theirs->founder_user_id));
        }

        return true;
    }

    /** Whether the AI leader offered this human a pact lately: the offer is the message it sent, no other record. */
    private function offeredTo(Alliance $ours, int $humanId): bool
    {
        $lines = (array) ($this->policy['lines'] ?? []);
        $offers = [...(array) ($lines['pact_offer'] ?? []), ...(array) ($lines['flank_offer'] ?? []), ...(array) ($lines['court_offer'] ?? [])];
        $openings = array_map(static fn (string $offer): string => substr($offer, 0, (int) strpos($offer . '[', '[')), array_values($offers));

        return ChatMessage::query()->where('sender_id', $ours->founder_user_id)->where('recipient_id', $humanId)
            ->where('created_at', '>=', now()->subDays(self::OFFER_DAYS))
            ->where(fn ($query) => array_reduce($openings, static fn ($query, string $opening) => $query->orWhere('message', 'like', $opening . '%'), $query))->exists();
    }

    /**
     * The stronger clubs that hit this one hard enough for a war it dares not declare (outmatched): it looks for others
     * at war with them, as two small clubs under one big one do (9465: both were farmed by the leader and never allied).
     *
     * @return list<int>
     */
    private function feared(Alliance $alliance): array
    {
        $line = (float) ($this->policy['war_grievance'] ?? 1.5);
        $members = $this->members((int) $alliance->id);

        return Alliance::query()->whereKeyNot($alliance->id)->pluck('id')->map(fn ($id): int => (int) $id)
            ->filter(fn (int $otherId): bool => $this->outmatched((int) $alliance->id, $otherId) && $this->grievance($members, $this->members($otherId)) >= $line)
            ->values()->all();
    }

    /** @param list<int> $founders */
    private function offerPact(Alliance $alliance, array $founders): int
    {
        foreach ($this->pactCandidates($alliance) as [$enemyId, $partnerId, $cause]) {
            $partner = Alliance::query()->find($partnerId);
            if ($partner === null || $partnerId === $alliance->id || $partner->founder_user_id === null
                || $this->treaties->related((int) $alliance->id, $partnerId)
                || $this->feudOutweighs((int) $alliance->founder_user_id, (int) $alliance->id, $partnerId, $enemyId)) {
                continue;
            }

            // The offer is made once and awaited: a human leader answers in their own words (answerHumanLeader), and an
            // AI leader that hates the offerer refuses once instead of being asked again on every pass (9464: the
            // same offer twenty times to one leader).
            $human = !in_array($partner->founder_user_id, $founders, true);
            if ($this->offeredTo($alliance, (int) $partner->founder_user_id) || in_array((int) $alliance->id, $this->treaties->pactOffersTo($partnerId), true)) {
                continue;
            }

            if ($this->advise('pact_propose', (int) $alliance->id, $partnerId, ['offer' => 'offer them a pact against a common enemy', 'hold' => 'make no offer now'], 'offer') === 'hold') {
                continue;
            }

            $enemyTag = (string) Alliance::query()->whereKey($enemyId)->value('alliance_tag');
            $line = $cause === 'flank' ? 'flank_offer' : 'pact_offer';
            if ($human) {
                return $this->offerToHuman($alliance, $partner, $line, $enemyTag, $cause);
            }

            app(DeliverAiDirectReplyAction::class)->handle((int) $alliance->founder_user_id, (int) $partner->founder_user_id, $this->line($line, $enemyTag, (int) $alliance->founder_user_id, (int) $partner->founder_user_id));

            if ($this->feudOutweighs((int) $partner->founder_user_id, $partnerId, (int) $alliance->id, $enemyId) || $this->distrusts($partnerId, (int) $alliance->id)) {
                app(DeliverAiDirectReplyAction::class)->handle((int) $partner->founder_user_id, (int) $alliance->founder_user_id, $this->line('pact_refuse', (string) $alliance->alliance_tag, (int) $partner->founder_user_id, (int) $alliance->founder_user_id));
                $this->events[] = ['pact_refused', $partnerId, (int) $alliance->id];

                return 1;
            }

            $this->treaties->openPact((int) $alliance->id, $partnerId, $cause, (int) $alliance->founder_user_id);
            $this->events[] = ['pact', (int) $alliance->id, $partnerId];
            $this->keepSecret((int) $alliance->id, $partnerId);
            app(DeliverAiDirectReplyAction::class)->handle((int) $partner->founder_user_id, (int) $alliance->founder_user_id, $this->line('pact_accept', (string) $alliance->alliance_tag, (int) $partner->founder_user_id, (int) $alliance->founder_user_id));

            return 1;
        }

        return 0;
    }

    /**
     * A pact offer to a human leader is the message; where the record keeps offers (the Empire tab) it also stands there
     * for the human to accept or decline. The human's answer is their own (answerHumanLeader, the tab).
     */
    private function offerToHuman(Alliance $alliance, Alliance $partner, string $line, string $tag, string $cause): int
    {
        $founderId = (int) $alliance->founder_user_id;
        app(DeliverAiDirectReplyAction::class)->handle($founderId, (int) $partner->founder_user_id, $this->line($line, $tag, $founderId, (int) $partner->founder_user_id));
        $this->treaties->offerPact((int) $alliance->id, (int) $partner->id, $cause, $founderId);

        return 1;
    }

    /**
     * Who this alliance would sign with, in the order a veteran thinks of them: whoever fights the same enemy, then a
     * third club it has been trading hits with while a war is on, so the war is not fought on two fronts (the judge on
     * 9509 and 9511: two wars, four clubs, no pact anywhere, because no two clubs shared an enemy).
     *
     * @return list<array{0: int, 1: int, 2: string}> enemy id, partner id, cause
     */
    private function pactCandidates(Alliance $alliance): array
    {
        $wars = $this->treaties->counterparts((int) $alliance->id, AiAllianceStance::War);
        $candidates = [];

        foreach (array_unique([...$wars, ...$this->feared($alliance)]) as $enemyId) {
            foreach ($this->treaties->counterparts($enemyId, AiAllianceStance::War) as $partnerId) {
                $candidates[] = [$enemyId, $partnerId, 'common-enemy'];
            }
        }

        if ($wars === []) {
            return $candidates;
        }

        $own = $this->members((int) $alliance->id);
        $line = (float) ($this->policy['peace_grievance'] ?? 0.3);
        foreach (Alliance::query()->whereKeyNot($alliance->id)->whereNotIn('id', $wars)->orderBy('id')->pluck('id') as $otherId) {
            $theirs = $this->members((int) $otherId);
            if ($this->grievance($own, $theirs) + $this->grievance($theirs, $own) >= $line) {
                $candidates[] = [$wars[0], (int) $otherId, 'flank'];
            }
        }

        return $candidates;
    }

    private function betrayPact(Alliance $alliance): int
    {
        $founderId = (int) $alliance->founder_user_id;
        if (!$this->quiet((int) $alliance->id) || !app(AllianceAmbition::class)->betrays($founderId)) {
            return 0;
        }

        $ratio = (float) ($this->policy['betray_strength_ratio'] ?? 2.0);
        $strength = $this->points((int) $alliance->id);

        foreach ($this->partners((int) $alliance->id) as $partnerId) {
            if ($strength >= $ratio * max(1.0, $this->points($partnerId)) && $this->advise('partner', (int) $alliance->id, $partnerId, self::KEEP_OR_CANCEL, 'cancel') === 'cancel') {
                return $this->turnOn($alliance, $partnerId);
            }
        }

        return 0;
    }

    /**
     * The pact partners not yet under notice: a cancelled pact still binds, but is already decided.
     *
     * @return list<int>
     */
    private function partners(int $club): array
    {
        return array_values(array_filter($this->treaties->counterparts($club, AiAllianceStance::Nap), fn (int $partner): bool => !$this->treaties->inNotice($club, $partner)));
    }

    /** No war open and no notice running: a notice is a war in waiting, and like a war it holds back another betrayal. */
    private function quiet(int $club): bool
    {
        $pacts = $this->treaties->counterparts($club, AiAllianceStance::Nap);

        return $this->treaties->counterparts($club, AiAllianceStance::War) === [] && count($this->partners($club)) === count($pacts);
    }

    /** A leader turns on its partner: by surprise only as revenge with a reason, by notice otherwise. */
    private function turnOn(Alliance $alliance, int $partner): int
    {
        if (!$this->surprises((int) $alliance->id, $partner)) {
            return $this->giveNotice($alliance, $partner);
        }

        $this->breach((int) $alliance->id, $partner);
        $this->goToWar($alliance, Alliance::query()->findOrFail($partner), 'surprise', 'betrayal_broadcast', 'betrayal_to_leader');
        foreach ($this->members($partner) as $member) {
            $this->minds?->experience($member, 'betrayed');
        }

        return 1;
    }

    /**
     * A surprise break is revenge with a reason (DIPLOMACY-BETRAYAL B3): a leader at least secret_cunning, a grudge its
     * members still hold from a hit by the partner's members, and, with the engines answering, a remembered grievance
     * toward them over its gratitude.
     */
    private function surprises(int $club, int $partner): bool
    {
        $minds = $this->minds;
        if ($minds === null || $minds->trait($this->leader($club), 'cunning') < $minds->setting('secret_cunning')) {
            return false;
        }

        if ($this->grievance($this->members($club), $this->members($partner)) <= 0.0) {
            return false;
        }

        if (!$this->thinking) {
            return true;
        }

        [$grievance, $gratitude] = $minds->ledger($this->leader($club), $this->members($partner));

        return $grievance > $gratitude;
    }

    /**
     * Notice: the pact binds pact_notice_hours more, then endNotices opens the war. A cancel is legitimate and costs no
     * reputation; a secret pact's cancel is a private word to the partner's leader, a public one is told to the members.
     */
    private function giveNotice(Alliance $alliance, int $partner): int
    {
        $club = (int) $alliance->id;
        $minutes = (int) round(app(SocialMinds::class)->setting('pact_notice_hours') * 60);
        if (!$this->treaties->cancelPact($club, $partner, now()->addMinutes($minutes))) {
            return 0;
        }

        $this->events[] = ['pact_cancel', $club, $partner];
        $founderId = (int) $alliance->founder_user_id;
        $other = Alliance::query()->findOrFail($partner);
        $tag = (string) $other->alliance_tag;
        if (!in_array($partner, (array) $this->minds?->get($this->founder($club), 'secret', []), true)) {
            try {
                app(AllianceService::class)->sendBroadcastMessage($club, $founderId, $this->line('pact_cancel_broadcast', $tag, $founderId), ['-1']);
            } catch (Exception) {
            }
        }

        if ($other->founder_user_id !== null) {
            app(DeliverAiDirectReplyAction::class)->handle($founderId, (int) $other->founder_user_id, $this->line('pact_cancel_to_leader', $tag, $founderId, (int) $other->founder_user_id));
        }

        return 1;
    }

    /** Pacts whose notice ran out are wars now: an AI canceller announces its war, a human's is recorded as seen. */
    private function endNotices(): int
    {
        $ended = $this->treaties->endNotices();
        foreach ($ended as [$by, $with]) {
            $this->unsecret($by, $with);
            $alliance = Alliance::query()->find($by);
            if ($alliance !== null && AiProfile::query()->where('player_id', $alliance->founder_user_id)->where('enabled', true)->exists()) {
                $this->proclaim($alliance, Alliance::query()->findOrFail($with), 'betrayal', 'betrayal_broadcast', 'betrayal_to_leader');

                continue;
            }

            $this->logged('war', $by, $with);
        }

        return count($ended);
    }

    private function goToWar(Alliance $alliance, Alliance $enemy, string $cause, string $broadcast, string $toLeader): void
    {
        // A pact between the two ends the moment one declares: that is the surprise break the other side remembers.
        $this->treaties->openWar((int) $alliance->id, (int) $enemy->id, $cause, (int) $alliance->founder_user_id);
        $this->proclaim($alliance, $enemy, $cause, $broadcast, $toLeader);
    }

    /** The war as its declarer's members, the other leader and the board see it, and the minds remember it. */
    private function proclaim(Alliance $alliance, Alliance $enemy, string $cause, string $broadcast, string $toLeader): void
    {
        $founderId = (int) $alliance->founder_user_id;
        $tag = (string) $enemy->alliance_tag;
        $this->events[] = [match ($cause) {
            'surprise' => 'betrayal',
            'revolt' => 'revolt',
            default => 'war',
        }, (int) $alliance->id, (int) $enemy->id];
        $this->minds?->put($founderId, 'wars', [(int) $enemy->id => ['cause' => $cause, 'since' => $this->hour()]] + (array) $this->minds->get($founderId, 'wars', []));

        // The announcements are the visible half; the host refusing one (permission, length) leaves the war standing.
        $service = app(AllianceService::class);
        try {
            $service->sendBroadcastMessage((int) $alliance->id, $founderId, $this->line($broadcast, $tag, $founderId), ['-1']);
            $external = trim((string) $alliance->external_text . "\n" . $this->line('war_external', $tag));
            $service->updateTexts((int) $alliance->id, $founderId, $alliance->internal_text, $external, $alliance->application_text);
        } catch (Exception) {
        }

        if ($enemy->founder_user_id !== null) {
            app(DeliverAiDirectReplyAction::class)->handle($founderId, (int) $enemy->founder_user_id, $this->line($toLeader, $tag, $founderId, (int) $enemy->founder_user_id));
        }
    }

    /**
     * What each side's members saw of a treaty event, as the simulator records it (diplomacy.rs `remember`): the side
     * whose members see it, the memory, whose leader is named on the act, valence and weight.
     */
    private const MEMORIES = [
        'war' => [['with', 'war_declared', 'by', -0.6, 0.6], ['by', 'war_declared_on', 'with', -0.3, 0.4]],
        'peace' => [['with', 'peace', 'by', 0.3, 0.3], ['by', 'peace', 'with', 0.3, 0.3]],
        'pact' => [['with', 'pact', 'by', 0.5, 0.5], ['by', 'pact', 'with', 0.5, 0.5]],
        'pact_refused' => [['with', 'pact_refused', 'by', -0.2, 0.3]],
        'betrayal' => [['with', 'betrayed', 'by', -1.0, 1.0]],
        'pact_cancel' => [['with', 'pact_cancelled', 'by', -0.4, 0.5]],
        'submit' => [['by', 'dominated', 'with', -0.7, 0.8], ['with', 'submitted', 'by', 0.3, 0.4]],
        'revolt' => [['with', 'revolt', 'by', -0.6, 0.6]],
    ];

    /**
     * Living minds wake for this pass: the flag records, the gateway answering lets them decide, and the engines' daily
     * pass (the simulator passes once a day; the live tick runs every session) runs once per day.
     */
    private function wakeMinds(bool $pass): void
    {
        $this->events = [];
        $this->inside = [];
        $this->felt = [];
        $this->leaders = [];
        $this->read = [];
        $this->minds = (bool) config('ai.cognition.social.minds', false) ? app(SocialMinds::class) : null;
        $this->thinking = $this->minds?->gatewayAnswers() ?? false;
        $this->daily = $pass && $this->thinking && Cache::add('ai:social-minds:day:' . (int) floor($this->hour() / 24.0), true, now()->addDays(2));
        $this->today = $pass && $this->minds !== null && Cache::add('ai:social-minds:persona-day:' . (int) floor($this->hour() / 24.0), true, now()->addDays(2));
        if ($this->daily) {
            $this->minds?->recordAttacks();
        }
    }

    /** With minds deciding, the engines own betrayal and defence on the daily pass; without them the YAML rule stands. */
    private function keepOrBreak(Alliance $alliance): int
    {
        if (!$this->thinking) {
            return $this->betrayPact($alliance);
        }

        return $this->daily ? $this->actTowardPartners($alliance) : 0;
    }

    /** @param array<mixed> $founders the enabled AI accounts */
    private function think(array $founders): int
    {
        $clubs = array_values(Alliance::query()->whereIn('founder_user_id', $founders)->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        // Humans are treated as any player (SOCIAL-WORLD-DRAFT.md part 17 item 2): courted and pressed through the same
        // choices, while their side of each exchange is their own answer, never an engine's.
        $humans = array_values(Alliance::query()->whereNotIn('founder_user_id', $founders)->whereNotNull('founder_user_id')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $moves = $this->revolt($clubs) + $this->settleUltimatums($clubs);
        $this->askAboutPartners($clubs);

        return $moves + $this->court($clubs, $humans) + $this->eyeTheWeak($clubs, $humans);
    }

    /**
     * Each leader asks about each pact partner in one batch: PsychSim (betray now? help now?) and FAtiMA (what its
     * emotions and relations move it to do).
     *
     * @param list<int> $clubs
     */
    private function askAboutPartners(array $clubs): void
    {
        $minds = $this->minds;
        if ($minds === null) {
            return;
        }

        $pairs = [];
        foreach ($clubs as $club) {
            foreach ($this->partners($club) as $partner) {
                $pairs[] = [$club, $partner];
            }
        }

        $inside = $minds->askPartners(array_map(fn (array $pair): array => [$this->leader($pair[0]), $this->members($pair[1]), $this->leader($pair[1]), $this->ratio($pair[1], $pair[0])], $pairs));
        $felt = $minds->exchange(array_map(fn (array $pair): array => [$this->leader($pair[0]), $this->leader($pair[1]), [
            'pact' => true,
            'ally_in_danger' => array_diff($this->treaties->counterparts($pair[1], AiAllianceStance::War), [$pair[0]]) !== [],
            'power_ratio' => $this->ratio($pair[0], $pair[1]),
        ], ['Ignore', 'Gift', 'DefendAlly', 'Betray']], $pairs), $this->hour());

        foreach ($pairs as $i => [$club, $partner]) {
            $this->inside[$club . ':' . $partner] = $inside[$i] ?? null;
            $this->felt[$club . ':' . $partner] = $felt[$i] ?? null;
        }
    }

    /**
     * The engines' act toward each partner (diplomacy.rs, arm `minds`). Calculation (PsychSim) or feeling (FAtiMA) can
     * move a leader; a felt betrayal is revenge and needs a remembered grievance (AgentOS) toward the partner's members
     * above the gratitude, a felt defence the gratitude. Help is a sacrifice: joining a partner's war on its enemy.
     */
    private function actTowardPartners(Alliance $alliance): int
    {
        $club = (int) $alliance->id;
        $moves = 0;
        if (count($this->treaties->counterparts($club, AiAllianceStance::War)) < (int) ($this->policy['max_open_wars'] ?? PHP_INT_MAX)) {
            foreach ($this->partners($club) as $partner) {
                $enemy = $this->acts($club, $partner, 'help_now') ? array_values(array_filter($this->treaties->counterparts($partner, AiAllianceStance::War), fn (int $e): bool => $e !== $club && !$this->bound($club, $e)))[0] ?? null : null;
                if ($enemy !== null) {
                    $this->goToWar($alliance, Alliance::query()->findOrFail($enemy), $this->calculates($club, $partner, 'help_now') ? 'defend' : 'defend-felt', 'war_broadcast', 'war_to_leader');
                    $moves++;
                    break;
                }
            }
        }

        if (!$this->quiet($club)) {
            return $moves;
        }

        foreach ($this->partners($club) as $partner) {
            $betrays = $this->acts($club, $partner, 'betray_now');
            if ($this->advise('partner', $club, $partner, self::KEEP_OR_CANCEL, $betrays ? 'cancel' : 'keep') === 'cancel') {
                // The teacher's betrayal may be a surprise break; the advisor's own pick is only ever the notice.
                return $moves + ($betrays ? $this->turnOn($alliance, $partner) : $this->giveNotice($alliance, $partner));
            }
        }

        return $moves;
    }

    private function acts(int $club, int $partner, string $act): bool
    {
        if ($this->calculates($club, $partner, $act)) {
            return true;
        }

        [$grievance, $gratitude] = $this->minds?->ledger($this->leader($club), $this->members($partner)) ?? [0.0, 0.0];
        $felt = $this->felt[$club . ':' . $partner] ?? null;

        return $act === 'help_now' ? $felt === 'DefendAlly' && $gratitude >= $grievance : $felt === 'Betray' && $grievance > $gratitude;
    }

    private function calculates(int $club, int $partner, string $act): bool
    {
        return ($this->inside[$club . ':' . $partner][$act] ?? false) === true;
    }

    /**
     * Courtship: each leader looks at the clubs it remembers best (AgentOS gratitude over grievance) and at its
     * enemies' enemies; FAtiMA says whether it feels like offering, CBRKit recalls how similar pacts ended (mostly
     * betrayed: hold back), and the other leader's PsychSim decides. A human-led club is courted the same way, but its
     * leader answers the offer in their own words or on the Empire tab.
     *
     * @param list<int> $clubs
     * @param list<int> $humans human-led clubs
     */
    private function court(array $clubs, array $humans): int
    {
        $minds = $this->minds;
        if ($minds === null) {
            return 0;
        }

        $hour = $this->hour();
        $offers = [];
        foreach ($clubs as $club) {
            $leader = $this->leader($club);
            $courted = (array) $minds->get($leader, 'courted', []);
            $enemies = $this->treaties->counterparts($club, AiAllianceStance::War);
            $scored = [];
            foreach ([...$clubs, ...$humans] as $other) {
                if ($other === $club || $this->treaties->related($club, $other) || $this->vassalBound($club, $other)
                    || $hour - (float) ($courted[$other] ?? -INF) < $minds->setting('court_every_hours')) {
                    continue;
                }

                [$grievance, $gratitude] = $minds->ledger($leader, $this->members($other));
                $common = array_filter($enemies, fn (int $enemy): bool => $this->treaties->holds($other, $enemy, AiAllianceStance::War)) !== [];
                $score = $gratitude - $grievance + ($common ? 1.0 : 0.0);
                if ($score > 0.0) {
                    $scored[$other] = $score;
                }
            }

            arsort($scored);
            foreach (array_slice(array_keys($scored), 0, (int) $minds->setting('court_candidates')) as $other) {
                $offers[] = [$club, $other];
                $minds->put($leader, 'courted', [$other => $hour] + (array) $minds->get($leader, 'courted', []));
            }
        }

        $choices = $minds->exchange(array_map(fn (array $o): array => [$this->leader($o[0]), $this->leader($o[1]), ['pact' => false, 'power_ratio' => $this->ratio($o[0], $o[1])], ['Ignore', 'OfferPact', 'Threaten']], $offers), $hour);
        foreach ($offers as $i => [$club, $other]) {
            $choices[$i] = $this->advise('court', $club, $other, ['OfferPact' => 'offer them a pact', 'Ignore' => 'make no offer now'], ($choices[$i] ?? null) === 'OfferPact' ? 'OfferPact' : 'Ignore');
        }

        $wanted = array_values(array_filter($offers, static fn (int $i): bool => ($choices[$i] ?? null) === 'OfferPact', ARRAY_FILTER_USE_KEY));
        $wary = $minds->wary(array_map(fn (array $o): array => $this->pactSituation($o[0], $o[1]), $wanted));
        $proposed = array_filter($wanted, static fn (int $i): bool => !($wary[$i] ?? false), ARRAY_FILTER_USE_KEY);
        $toHumans = array_filter($proposed, static fn (array $o): bool => in_array($o[1], $humans, true));
        $asked = array_values(array_diff_key($proposed, $toHumans));
        $answers = $minds->askPartners(array_map(fn (array $o): array => [$this->leader($o[1]), $this->members($o[0]), $this->leader($o[0]), $this->ratio($o[0], $o[1])], $asked));

        $moves = 0;
        foreach ($asked as $i => [$club, $other]) {
            if (!$this->treaties->related($club, $other)) {
                $moves += $this->answerCourtship($club, $other, ($answers[$i]['accept_pact'] ?? null) === true);
            }
        }

        foreach ($toHumans as [$club, $other]) {
            $moves += $this->courtHuman($club, $other);
        }

        return $moves;
    }

    /** One courtship offer to a human-led club, made once and awaited as offerPact's is. */
    private function courtHuman(int $club, int $other): int
    {
        $alliance = Alliance::query()->find($club);
        $partner = Alliance::query()->find($other);
        if ($alliance === null || $partner === null || $this->treaties->related($club, $other)
            || $this->offeredTo($alliance, (int) $partner->founder_user_id) || in_array($club, $this->treaties->pactOffersTo($other), true)) {
            return 0;
        }

        return $this->offerToHuman($alliance, $partner, 'court_offer', (string) $partner->alliance_tag, 'courtship');
    }

    /**
     * Pact offers standing to this AI-led club on the record (a human leader's offer on the Empire tab), answered by the
     * rule that answers any pact: with minds deciding, on the daily pass, the courtship's CBRKit hold-back and the
     * courted leader's PsychSim; without them, the YAML rule (pactOffered). An offer not taken is declined.
     */
    private function answerOffers(Alliance $alliance): int
    {
        $club = (int) $alliance->id;
        $moves = 0;
        foreach ($this->treaties->pactOffersTo($club) as $offererId) {
            $offerer = Alliance::query()->find($offererId);
            if ($offerer === null || ($this->thinking && !$this->daily)) {
                continue;
            }

            // An open war blocks a pact: such an offer only lapses.
            if ($this->thinking && !$this->treaties->related($club, $offererId)) {
                $accepts = $this->advise('pact_offer', $club, $offererId, self::ANSWER_OFFER, $this->acceptsCourtship($club, $offererId) ? 'accept' : 'decline') === 'accept';
                $moves += $this->answerCourtship($offererId, $club, $accepts);
            } elseif (!$this->thinking) {
                $moves += (int) $this->pactOffered($alliance, $offerer, 'offered', true);
            }

            if (!$this->treaties->holds($club, $offererId, AiAllianceStance::Nap)) {
                $this->treaties->declinePact($club, $offererId);
            }
        }

        return $moves;
    }

    /** The courted leader's side of courtship for one offer: CBRKit recalls how similar pacts ended, PsychSim decides. */
    private function acceptsCourtship(int $club, int $offererId): bool
    {
        $minds = $this->minds;
        if ($minds === null || ($minds->wary([$this->pactSituation($club, $offererId)])[0] ?? false)) {
            return false;
        }

        $answer = $minds->askPartners([[$this->leader($club), $this->members($offererId), $this->leader($offererId), $this->ratio($offererId, $club)]])[0] ?? null;

        return ($answer['accept_pact'] ?? null) === true;
    }

    /**
     * CBRKit's situation for a pact $club weighs with $other (diplomacy.rs courtship): the other's public record of broken
     * pacts, its own, and the pair's past wars.
     *
     * @return array<string, int|string>
     */
    private function pactSituation(int $club, int $other): array
    {
        return ['decision' => 'pact', 'their_broken' => $this->broken($other), 'my_broken' => $this->broken($club),
            'pair_wars' => (int) (((array) $this->minds?->get($this->founder($club), 'pair_wars', []))[$other] ?? 0)];
    }

    /** Pacts a club broke: the board's public count where it keeps one (Empire), else what its founder's mind counted. */
    private function broken(int $club): int
    {
        return $this->treaties->pactsBroken($club) ?? (int) $this->minds?->get($this->founder($club), 'broken', 0);
    }

    private function answerCourtship(int $club, int $other, bool $accepts): int
    {
        [$leader, $otherLeader] = [$this->leader($club), $this->leader($other)];
        $tag = (string) Alliance::query()->whereKey($club)->value('alliance_tag');
        if (!$accepts) {
            $this->events[] = ['pact_refused', $other, $club];
            app(DeliverAiDirectReplyAction::class)->handle($otherLeader, $leader, $this->line('pact_refuse', $tag, $otherLeader, $leader));

            return 1;
        }

        $this->treaties->openPact($club, $other, 'courtship', $leader);
        $this->events[] = ['pact', $club, $other];
        $this->keepSecret($club, $other);
        app(DeliverAiDirectReplyAction::class)->handle($otherLeader, $leader, $this->line('pact_accept', $tag, $otherLeader, $leader));

        return 1;
    }

    /**
     * Ambition: once a week a leader at peace eyes the weakest club it is not bound to; FAtiMA says whether it threatens
     * or demands tribute, and the target's FAtiMA whether it submits. Submission makes a vassal; resistance (or a bare
     * threat) a war of conquest. A weaker human-led club is eyed by the same rule, within the human pressure cap; it gets
     * an ultimatum instead, since no engine answers for a human (settleUltimatums).
     *
     * @param list<int> $clubs
     * @param list<int> $humans human-led clubs
     */
    private function eyeTheWeak(array $clubs, array $humans): int
    {
        $minds = $this->minds;
        if ($minds === null) {
            return 0;
        }

        if ((int) round($this->hour() / 24.0) % 7 !== 0) {
            return 0;
        }

        $eyed = [];
        foreach ($clubs as $club) {
            if ($this->treaties->counterparts($club, AiAllianceStance::War) !== [] || $this->overlord($club) !== 0) {
                continue;
            }

            $pending = (array) $minds->get($this->founder($club), 'ultimatums', []);
            $pool = [...$clubs, ...($this->mayPressHumans($club) ? $humans : [])];
            $targets = array_filter($pool, fn (int $o): bool => $o !== $club && !isset($pending[$o]) && !$this->vassalBound($club, $o) && !$this->treaties->related($club, $o) && $this->points($o) * 2.0 < $this->points($club));
            usort($targets, fn (int $a, int $b): int => $this->points($a) <=> $this->points($b));
            if ($targets !== []) {
                $eyed[] = [$club, $targets[0]];
            }
        }

        // A human cannot be asked to pay unless tribute is allowed on this server.
        $tribute = (bool) config('ai.cognition.social.tribute');
        $choices = $minds->exchange(array_map(fn (array $e): array => [$this->leader($e[0]), $this->leader($e[1]), ['pact' => false, 'power_ratio' => $this->ratio($e[0], $e[1])],
            $tribute || !in_array($e[1], $humans, true) ? ['Ignore', 'Threaten', 'DemandTribute'] : ['Ignore', 'Threaten']], $eyed), $this->hour());
        foreach ($eyed as $i => [$club, $target]) {
            $options = ['Ignore' => 'leave them be', 'Threaten' => 'threaten them, then war if they do not yield'];
            if ($tribute || !in_array($target, $humans, true)) {
                $options['DemandTribute'] = 'demand a daily tribute, war if refused';
            }

            $rule = (string) ($choices[$i] ?? 'Ignore');
            $choices[$i] = $this->advise('press', $club, $target, $options, isset($options[$rule]) ? $rule : 'Ignore');
        }

        $demands = array_filter($eyed, static fn (array $e, int $i): bool => ($choices[$i] ?? null) === 'DemandTribute' && !in_array($e[1], $humans, true), ARRAY_FILTER_USE_BOTH);
        $replies = array_combine(array_keys($demands), $minds->exchange(array_values(array_map(fn (array $e): array => [$this->leader($e[1]), $this->leader($e[0]), ['demand' => true, 'power_ratio' => $this->ratio($e[1], $e[0])], ['Submit', 'Revolt', 'Ignore']], $demands)), $this->hour()));
        foreach ($demands as $i => [$club, $target]) {
            $rule = (string) ($replies[$i] ?? 'Ignore');
            $replies[$i] = $this->advise('tribute_demand', $target, $club, self::ANSWER_DEMAND, isset(self::ANSWER_DEMAND[$rule]) ? $rule : 'Ignore');
        }

        $moves = 0;
        foreach ($eyed as $i => [$club, $target]) {
            $move = $choices[$i] ?? null;
            if (!in_array($move, ['Threaten', 'DemandTribute'], true) || $this->treaties->related($club, $target) || $this->overlord($target) !== 0) {
                continue;
            }

            $moves += in_array($target, $humans, true) ? $this->ultimatum($club, $target, $move) : $this->pressWeak($club, $target, $move, ($replies[$i] ?? null) === 'Submit');
        }

        return $moves;
    }

    /** Whether this club's leader may still threaten a human-led club this week (ai.cognition.social.human_pressure). */
    private function mayPressHumans(int $club): bool
    {
        $cap = (int) $this->minds?->setting('human_pressure_' . (string) config('ai.cognition.social.human_pressure', 'medium'));
        $hour = $this->hour();
        $recent = array_filter((array) $this->minds?->get($this->founder($club), 'pressed', []), static fn (mixed $at): bool => $hour - (float) $at < self::PRESSURE_WINDOW_HOURS);

        return count($recent) < $cap;
    }

    /** The threat or demand a human leader reads, with its deadline; their answer is what they do before it runs out. */
    private function ultimatum(int $club, int $target, string $move): int
    {
        $minds = $this->minds;
        $founder = $this->founder($club);
        $other = Alliance::query()->find($target);
        if ($minds === null || $other === null) {
            return 0;
        }

        $hour = $this->hour();
        $days = (string) max(1, (int) round($minds->setting('ultimatum_hours') / 24.0));
        $line = $move === 'DemandTribute' ? 'tribute_ultimatum' : 'threat_ultimatum';
        app(DeliverAiDirectReplyAction::class)->handle($founder, (int) $other->founder_user_id, $this->line($line, (string) $other->alliance_tag, $founder, (int) $other->founder_user_id, $days));
        $minds->put($founder, 'ultimatums', [$target => ['move' => $move, 'since' => $hour]] + (array) $minds->get($founder, 'ultimatums', []));
        $pressed = array_filter((array) $minds->get($founder, 'pressed', []), static fn (mixed $at): bool => $hour - (float) $at < self::PRESSURE_WINDOW_HOURS);
        $minds->put($founder, 'pressed', [...array_values($pressed), $hour]);

        return 1;
    }

    /**
     * Ultimatums to human-led clubs whose deadline ran out: a club that came to terms (a pact) or is already at war is
     * left be, one that paid a demanded tribute has submitted, and any other has refused and meets the conquest war.
     *
     * @param list<int> $clubs
     */
    private function settleUltimatums(array $clubs): int
    {
        $minds = $this->minds;
        if ($minds === null) {
            return 0;
        }

        $moves = 0;
        foreach ($clubs as $club) {
            $founder = $this->founder($club);
            $pending = (array) $minds->get($founder, 'ultimatums', []);
            foreach ($pending as $target => $terms) {
                if ($this->hour() - (float) $terms['since'] < $minds->setting('ultimatum_hours')) {
                    continue;
                }

                unset($pending[$target]);
                $moves += $this->settle($club, (int) $target, (string) $terms['move'], (float) $terms['since']);
            }

            $minds->put($founder, 'ultimatums', $pending);
        }

        return $moves;
    }

    private function settle(int $club, int $target, string $move, float $since): int
    {
        if (Alliance::query()->whereKey($target)->doesntExist() || $this->treaties->related($club, $target)) {
            return 0;
        }

        if ($move === 'DemandTribute' && config('ai.cognition.social.tribute') && $this->paid($target, $club, $since)) {
            $this->events[] = ['submit', $target, $club];

            return 1;
        }

        return $this->pressWeak($club, $target, $move, false);
    }

    /** A transport with resources from the club's members that reached one of ours since the demand: what our members saw land. */
    private function paid(int $payer, int $club, float $since): bool
    {
        return FleetMission::query()->whereIn('user_id', $this->members($payer))->where('mission_type', TransportMission::getTypeId())
            ->where('canceled', 0)->whereNull('parent_id')
            ->whereIn('planet_id_to', Planet::query()->whereIn('user_id', $this->members($club))->select('id'))
            ->whereBetween('time_arrival', [(int) ($since * 3600.0), now()->getTimestamp()])
            ->where(static fn ($query) => $query->where('metal', '>', 0)->orWhere('crystal', '>', 0)->orWhere('deuterium', '>', 0))->exists();
    }

    private function pressWeak(int $club, int $target, string $move, bool $submits): int
    {
        if ($move === 'DemandTribute' && $submits) {
            $this->minds?->put($this->founder($target), 'overlord', ['alliance' => $club, 'since' => $this->hour()]);
            $this->events[] = ['submit', $target, $club];

            return 1;
        }

        if (count($this->treaties->counterparts($club, AiAllianceStance::War)) >= (int) ($this->policy['max_open_wars'] ?? PHP_INT_MAX)) {
            return 0;
        }

        $this->goToWar(Alliance::query()->findOrFail($club), Alliance::query()->findOrFail($target), $move === 'DemandTribute' ? 'refused-tribute' : 'conquest', 'war_broadcast', 'war_to_leader');

        return 1;
    }

    /**
     * A vassal revolts once it holds this share of its overlord's points (social-minds.yaml `revolt_ratio`).
     *
     * @param list<int> $clubs
     */
    private function revolt(array $clubs): int
    {
        $minds = $this->minds;
        if ($minds === null) {
            return 0;
        }

        $moves = 0;
        foreach ($clubs as $club) {
            $overlord = $this->overlord($club);
            $lord = $overlord === 0 ? null : Alliance::query()->find($overlord);
            if ($overlord === 0 || ($lord !== null && $this->points($club) < $minds->setting('revolt_ratio') * $this->points($overlord))) {
                continue;
            }

            $minds->put($this->founder($club), 'overlord', null);
            if ($lord !== null) {
                $this->goToWar(Alliance::query()->findOrFail($club), $lord, 'revolt', 'war_broadcast', 'war_to_leader');
                $moves++;
            }
        }

        return $moves;
    }

    /**
     * Arm `cycles` (diplomacy.rs `cycle`), once a day: a band that stands as a club goes to war on the club it rose
     * against, cohesion wears in peace and grows in war, a spent band disperses, a soft club with too many ambitious
     * members splits, a soft ruler is overrun by nomads, and the moved accounts gather in their band's club.
     *
     * @param list<int> $accounts the enabled AI accounts
     */
    private function cycle(array $accounts): int
    {
        $this->read = null;
        try {
            return $this->cycleClubs($accounts);
        } finally {
            $this->read = [];
        }
    }

    /** @param list<int> $accounts */
    private function cycleClubs(array $accounts): int
    {
        $moves = $this->riseUp($accounts);
        $clubs = array_values(array_filter(Alliance::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all(), fn (int $club): bool => $this->members($club) !== []));
        foreach ($clubs as $club) {
            $this->wear($club);
        }

        $this->disperse($accounts);
        foreach ($clubs as $club) {
            $this->split($club);
        }

        $this->overrun($clubs, $accounts);
        $this->gather($accounts);

        return $moves;
    }

    /**
     * A band's war on the club it rose against starts once the band stands as a club (diplomacy.rs `uprisings`).
     *
     * @param list<int> $accounts
     */
    private function riseUp(array $accounts): int
    {
        $moves = 0;
        foreach ($accounts as $chief) {
            $target = (int) $this->minds?->get($chief, 'rising', 0);
            $band = Alliance::query()->where('founder_user_id', $chief)->first();
            if ($target === 0 || $band === null) {
                continue;
            }

            $this->minds?->put($chief, 'rising', 0);
            $enemy = Alliance::query()->find($target);
            if ($enemy !== null && !$this->treaties->related((int) $band->id, $target)) {
                $this->goToWar($band, $enemy, 'uprising', 'war_broadcast', 'war_to_leader');
                $moves++;
            }
        }

        return $moves;
    }

    /** A club's cohesion, 1 at founding (no row yet). */
    private function cohesion(int $club): float
    {
        return (float) (AiAllianceCohesion::query()->where('alliance_id', $club)->value('asabiyya') ?? 1.0);
    }

    /** A public war binds the club; a day of peace wears it. */
    private function wear(int $club): void
    {
        $minds = $this->minds;
        $atWar = $this->treaties->counterparts($club, AiAllianceStance::War) !== [];
        $cohesion = $this->cohesion($club);
        $cohesion = $atWar ? min(1.0, $cohesion + (float) $minds?->setting('asabiyya_war_gain')) : max(0.0, $cohesion - (float) $minds?->setting('asabiyya_peace_decay'));
        AiAllianceCohesion::query()->updateOrCreate(['alliance_id' => $club], ['asabiyya' => $cohesion]);
    }

    /**
     * Who the politics moved, toward which band founder.
     *
     * @param list<int> $accounts
     * @return array<int, int>
     */
    private function pins(array $accounts): array
    {
        $pins = [];
        foreach ($accounts as $account) {
            $pins[$account] = (int) $this->minds?->get($account, 'pinned', 0);
        }

        return array_filter($pins);
    }

    /**
     * A band or splinter whose cohesion is spent disperses: its members go back to wherever their standing puts them.
     *
     * @param list<int> $accounts
     */
    private function disperse(array $accounts): void
    {
        $pins = $this->pins($accounts);
        foreach (array_unique($pins) as $head) {
            $club = (int) Alliance::query()->where('founder_user_id', $head)->value('id');
            if ($club === 0 || $this->cohesion($club) >= (float) $this->minds?->setting('asabiyya_split')) {
                continue;
            }

            foreach (array_keys($pins, $head, true) as $account) {
                $this->minds?->put($account, 'pinned', 0);
            }
        }
    }

    /**
     * Elite overproduction: a soft club of six or more whose ambitious members reach the elite share splits when the
     * strongest of them feels like revolting (FAtiMA, or its love of freedom without the engines); it leaves with the
     * disloyal. The host's founder cannot leave, so it never heads nor follows a schism.
     */
    private function split(int $club): void
    {
        $minds = $this->minds;
        $members = $this->members($club);
        if ($minds === null || $this->cohesion($club) >= $minds->setting('asabiyya_split') || count($members) < (int) $minds->setting('schism_min_members')) {
            return;
        }

        $leader = $this->leader($club);
        $elites = array_values(array_filter($members, static fn (int $a): bool => $a !== $leader && $minds->traitOr($a, 'ambition') > $minds->setting('coup_ambition')));
        if (count($elites) < $minds->setting('elite_share') * count($members)) {
            return;
        }

        $head = $this->strongest($elites);
        $founder = $this->founder($club);
        if ($head === $founder || !$this->revolts($head, $leader)) {
            return;
        }

        $followers = array_filter($members, static fn (int $a): bool => $a !== $leader && $a !== $founder && ($a === $head || $minds->traitOr($a, 'loyalty') < $minds->setting('follower_loyalty')));
        foreach ([$head, ...array_diff($followers, [$head])] as $follower) {
            try {
                app(AllianceService::class)->leaveAlliance($follower);
            } catch (Exception) {
                continue;
            }

            $minds->put($follower, 'pinned', $head);
        }
    }

    private function revolts(int $head, int $leader): bool
    {
        if (!$this->thinking) {
            return (float) $this->minds?->traitOr($head, 'freedom') > (float) $this->minds?->setting('rebel_freedom');
        }

        $ratio = $this->playerPoints($head) / max(1.0, $this->playerPoints($leader));

        return ($this->minds?->exchange([[$head, $leader, ['demand' => false, 'power_ratio' => $ratio], ['Ignore', 'Revolt']]], $this->hour())[0] ?? null) === 'Revolt';
    }

    /**
     * Nomads rise against a ruling dynasty: the leading club, at least ruler_lead times the second, gone soft. The most
     * charismatic independent's band is one in uprising_share free spirits, once per uprising_every_hours in the world.
     *
     * @param list<int> $clubs
     * @param list<int> $accounts
     */
    private function overrun(array $clubs, array $accounts): void
    {
        $minds = $this->minds;
        $last = AiAllianceCohesion::query()->whereNotNull('risen_against_at')->latest('risen_against_at')->first()?->risen_against_at;
        if ($minds === null || $clubs === [] || ($last !== null && $this->hour() - $last->getTimestamp() / 3600.0 < $minds->setting('uprising_every_hours'))) {
            return;
        }

        $points = array_combine($clubs, array_map($this->points(...), $clubs));
        arsort($points);
        $target = (int) array_key_first($points);
        $second = (float) (array_values($points)[1] ?? 0.0);
        if ($points[$target] < $minds->setting('ruler_lead') * $second || $this->cohesion($target) >= $minds->setting('asabiyya_split')) {
            return;
        }

        $pins = $this->pins($accounts);
        $nomads = array_values(array_filter($accounts, fn (int $a): bool => !isset($pins[$a]) && $this->playerPoints($a) > 0.0 && User::query()->whereKey($a)->whereNull('alliance_id')->exists()));
        $chiefs = array_values(array_filter($nomads, static fn (int $a): bool => $minds->trait($a, 'ambition') + $minds->trait($a, 'dominance') + $minds->trait($a, 'courage') > $minds->setting('charisma')));
        if ($chiefs === []) {
            return;
        }

        $chief = $this->strongest($chiefs);
        $band = array_values(array_filter($nomads, static fn (int $a): bool => $a !== $chief && $minds->trait($a, 'freedom') > $minds->setting('rider_freedom')));
        usort($band, fn (int $x, int $y): int => $this->playerPoints($y) <=> $this->playerPoints($x));
        $size = max(2, min((int) $minds->setting('uprising_band') - 1, intdiv(count($band), (int) $minds->setting('uprising_share'))));
        foreach ([...array_slice($band, 0, $size), $chief] as $rider) {
            $minds->put($rider, 'pinned', $chief);
        }

        $minds->put($chief, 'rising', $target);
        AiAllianceCohesion::query()->updateOrCreate(['alliance_id' => $target], ['risen_against_at' => now()]);
    }

    /**
     * The moved accounts stand in their band's club (season.rs `pinned`): its founder founds it once the host's join
     * cooldown lets it, and the others apply and are taken.
     *
     * @param list<int> $accounts
     */
    private function gather(array $accounts): void
    {
        $pins = $this->pins($accounts);
        $life = app(AdvanceAiAllianceLifeAction::class);
        foreach (array_unique($pins) as $head) {
            if (User::query()->whereKey($head)->whereNull('alliance_id')->exists()) {
                $life->foundAlliance($head, $life->tagFor($head));
            }
        }

        foreach ($pins as $account => $head) {
            $club = (int) Alliance::query()->where('founder_user_id', $head)->value('id');
            if ($account === $head || $club === 0 || !User::query()->whereKey($account)->whereNull('alliance_id')->exists()) {
                continue;
            }

            try {
                $service = app(AllianceService::class);
                $service->acceptApplication((int) $service->applyToAlliance($account, $club)->id, $head);
            } catch (Exception) {
                continue;
            }
        }
    }

    private function playerPoints(int $player): float
    {
        return (float) Highscore::query()->where('player_id', $player)->value('general');
    }

    /**
     * Governance (diplomacy.rs, `leaders`): a club's standing leader is its founder at first; one who leaves is succeeded
     * by the strongest AI member, and either may be unseated by a coup. Kept in the founder's mind: the host has no
     * leadership transfer, so the founder stays the club's voice on the board while the standing leader decides.
     *
     * @param list<int> $founders
     */
    private function govern(array $founders): void
    {
        $minds = $this->minds;
        if ($minds === null) {
            return;
        }

        $hour = $this->hour();
        foreach (Alliance::query()->whereIn('founder_user_id', $founders)->orderBy('id')->pluck('founder_user_id', 'id') as $club => $founder) {
            $members = $this->members((int) $club);
            $standing = (array) $minds->get((int) $founder, 'standing', []);
            if (!in_array((int) ($standing['leader'] ?? 0), $members, true)) {
                // A new leader's tenure: no plot until the cooldown has run from the day it took the club.
                $heirs = array_values(array_filter($members, $minds->has(...)));
                $standing = ['leader' => $standing === [] || $heirs === [] ? (int) $founder : $this->strongest($heirs), 'plotted' => $hour];
            }

            $standing = $this->plot($members, $standing, $hour);
            $minds->put((int) $founder, 'standing', $standing);
            $this->leaders[(int) $club] = (int) $standing['leader'];
        }
    }

    /**
     * A coup: the most ambitious and domineering member who outgrew the leader plots; it succeeds where the other
     * members' mean authority (respect for hierarchy) is under the line. One attempt per cooldown.
     *
     * @param list<int> $members
     * @param array<string, mixed> $standing
     * @return array<string, mixed>
     */
    private function plot(array $members, array $standing, float $hour): array
    {
        $minds = $this->minds;
        $leader = (int) $standing['leader'];
        if ($minds === null || count($members) < 3 || $hour - (float) $standing['plotted'] < $minds->setting('coup_cooldown_hours')) {
            return $standing;
        }

        $points = Highscore::query()->whereIn('player_id', $members)->pluck('general', 'player_id');
        $plotters = array_values(array_filter($members, static fn (int $a): bool => $a !== $leader && $minds->has($a)
            && $minds->trait($a, 'ambition') > $minds->setting('coup_ambition')
            && (float) ($points[$a] ?? 0) > $minds->setting('coup_points_ratio') * max(1.0, (float) ($points[$leader] ?? 0))));
        if ($plotters === []) {
            return $standing;
        }

        usort($plotters, static fn (int $x, int $y): int => ($minds->trait($y, 'ambition') + $minds->trait($y, 'dominance')) <=> ($minds->trait($x, 'ambition') + $minds->trait($x, 'dominance')));
        $plotter = $plotters[0];
        $others = array_diff($members, [$leader, $plotter]);
        $respect = array_sum(array_map(static fn (int $a): float => $minds->traitOr($a, 'authority'), $others)) / max(1, count($others));
        $won = $respect < $minds->setting('coup_authority');

        foreach ($members as $member) {
            $minds->saw($member, $won ? 'coup' : 'coup_failed', $plotter, $hour, -0.3, 0.5);
        }

        $minds->saw($leader, $won ? 'overthrown' : 'plot_foiled', $plotter, $hour, -1.0, 1.0);
        $minds->experience($won ? $leader : $plotter, 'defeat');
        $minds->experience($won ? $plotter : $leader, 'victory');

        return ['leader' => $won ? $plotter : $leader, 'plotted' => $hour];
    }

    /** @param list<int> $players */
    private function strongest(array $players): int
    {
        $points = Highscore::query()->whereIn('player_id', $players)->pluck('general', 'player_id');
        usort($players, static fn (int $x, int $y): int => [(float) ($points[$y] ?? 0), $x] <=> [(float) ($points[$x] ?? 0), $y]);

        return $players[0];
    }

    /**
     * Leaks, once a day (diplomacy.rs): a secret pact slips through a disloyal member of either club, and a secret
     * pact's hidden betrayal is told by a vindictive member of the betrayed club. Each draw is seeded by the day.
     *
     * @param list<int> $founders
     */
    private function leak(array $founders): void
    {
        $minds = $this->minds;
        if ($minds === null) {
            return;
        }

        $day = (int) floor($this->hour() / 24.0);
        $seen = [];
        foreach (Alliance::query()->whereIn('founder_user_id', $founders)->orderBy('id')->pluck('id') as $club) {
            $club = (int) $club;
            foreach ((array) $minds->get($this->founder($club), 'secret', []) as $partner) {
                [$x, $y] = [min($club, (int) $partner), max($club, (int) $partner)];
                if (isset($seen["$x:$y"]) || !$this->treaties->holds($x, $y, AiAllianceStance::Nap)) {
                    continue;
                }

                $seen["$x:$y"] = true;
                $stay = $this->keeps([...$this->members($x), ...$this->members($y)], 'loyalty', static fn (float $loyalty): float => $minds->setting('leak_per_member_day') * (1.0 - $loyalty));
                if ($minds->draw("pact:$x:$y", $day) > $stay) {
                    $this->unsecret($x, $y);
                    $this->spread('secret_pact_exposed', $this->leader($x), $x, $y, 'pact_leak_broadcast');
                }
            }

            $this->tell($club, $day);
        }
    }

    /** The victims of this club's hidden betrayals: a vindictive member tells, and the breaker's name is out. */
    private function tell(int $club, int $day): void
    {
        $minds = $this->minds;
        $founder = $this->founder($club);
        foreach ((array) $minds?->get($founder, 'hidden', []) as $victim) {
            $stay = $this->keeps($this->members((int) $victim), 'vindictiveness', static fn (float $vindictive): float => (float) $minds?->setting('tell_per_victim_day') * $vindictive);
            if ($minds === null || $minds->draw("told:$club:$victim", $day) <= $stay) {
                continue;
            }

            $minds->put($founder, 'hidden', array_values(array_diff((array) $minds->get($founder, 'hidden', []), [$victim])));
            $minds->put($founder, 'broken', (int) $minds->get($founder, 'broken', 0) + 1);
            foreach ($this->members($club) as $member) {
                $minds->experience($member, 'betrayal_exposed');
            }

            $this->spread('betrayal_exposed', $this->leader($club), $club, (int) $victim, 'betrayal_leak_broadcast');
        }
    }

    /**
     * The chance nothing slips among these players today: each keeps quiet with 1 - chance(its trait).
     *
     * @param list<int> $players
     * @param callable(float): float $chance
     */
    private function keeps(array $players, string $trait, callable $chance): float
    {
        return array_product(array_map(fn (int $a): float => 1.0 - $chance((float) $this->minds?->traitOr($a, $trait)), $players));
    }

    /** A leak is news: every alliance member's mind hears it, and every AI-led alliance's founder passes it on. */
    private function spread(string $event, int $actor, int $club, int $other, string $line): void
    {
        $hour = $this->hour();
        foreach (User::query()->whereNotNull('alliance_id')->pluck('id') as $member) {
            $this->minds?->saw((int) $member, $event, $actor, $hour, -0.4, 0.4);
        }

        $tags = Alliance::query()->whereIn('id', [$club, $other])->pluck('alliance_tag', 'id');
        foreach (Alliance::query()->whereIn('founder_user_id', AiProfile::query()->where('enabled', true)->pluck('player_id'))->get() as $alliance) {
            try {
                app(AllianceService::class)->sendBroadcastMessage((int) $alliance->id, (int) $alliance->founder_user_id, $this->line($line, (string) ($tags[$club] ?? ''), (int) $alliance->founder_user_id, 0, (string) ($tags[$other] ?? '')), ['-1']);
            } catch (Exception) {
            }
        }
    }

    /**
     * Tribute, once a day (season.rs): each AI member of a vassal club sends a share of its home stock to the overlord's
     * strongest member as a real Transport; one with no cargo hulls for it pays nothing that day.
     *
     * @param list<int> $founders
     */
    private function payTribute(array $founders): void
    {
        $minds = $this->minds;
        foreach (Alliance::query()->whereIn('founder_user_id', $founders)->orderBy('id')->pluck('id') as $club) {
            $overlord = $this->overlord((int) $club);
            $lords = $overlord === 0 ? [] : $this->members($overlord);
            if ($minds === null || $lords === []) {
                continue;
            }

            $lord = $this->strongest($lords);
            foreach (array_filter($this->members((int) $club), $minds->has(...)) as $member) {
                app(PayAiTributeAction::class)->handle($member, $lord, $minds->setting('tribute_share'));
            }
        }
    }

    /** The partner's leader asks PsychSim whether to trust the offerer (pact refused for distrust). */
    private function distrusts(int $askerClub, int $offerer): bool
    {
        $asker = $this->leader($askerClub);
        if (!$this->thinking || $this->minds === null || !$this->minds->has($asker)) {
            return false;
        }

        $answer = $this->minds->askPartners([[$asker, $this->members($offerer), $this->leader($offerer), $this->ratio($offerer, $askerClub)]])[0] ?? null;

        return ($answer['accept_pact'] ?? null) === false;
    }

    /** A pact is secret when either leader is cunning enough (social-minds.yaml `secret_cunning`). */
    private function keepSecret(int $club, int $other): void
    {
        [$leader, $otherLeader] = [$this->leader($club), $this->leader($other)];
        if ($this->minds === null || max($this->minds->trait($leader, 'cunning'), $this->minds->trait($otherLeader, 'cunning')) <= $this->minds->setting('secret_cunning')) {
            return;
        }

        foreach ([[$club, $other], [$other, $club]] as [$holderClub, $partner]) {
            $holder = $this->founder($holderClub);
            $this->minds->put($holder, 'secret', array_values(array_unique([...(array) $this->minds->get($holder, 'secret', []), $partner])));
        }
    }

    /** A broken pact: a public one costs the breaker reputation, a secret one is known to its victim only until told. */
    private function breach(int $club, int $partner): bool
    {
        $founder = $this->founder($club);
        $secret = in_array($partner, (array) $this->minds?->get($founder, 'secret', []), true);
        if ($secret) {
            $this->minds?->put($founder, 'hidden', array_values(array_unique([...(array) $this->minds->get($founder, 'hidden', []), $partner])));
        }

        if (!$secret) {
            $this->minds?->put($founder, 'broken', (int) $this->minds->get($founder, 'broken', 0) + 1);
        }

        $this->unsecret($club, $partner);

        return $secret;
    }

    private function unsecret(int $club, int $partner): void
    {
        foreach ([[$club, $partner], [$partner, $club]] as [$holderClub, $other]) {
            $holder = $this->founder($holderClub);
            if ($this->minds?->has($holder)) {
                $this->minds->put($holder, 'secret', array_values(array_diff((array) $this->minds->get($holder, 'secret', []), [$other])));
            }
        }
    }

    /**
     * A war joined in defence lasts while a partner of the defender still fights that enemy; a war of conquest is a
     * campaign that runs at least conquest_hours before the grievance line may end it.
     */
    private function campaignHolds(int $declarerId, int $otherId): bool
    {
        $war = ((array) $this->minds?->get($this->founder($declarerId), 'wars', []))[$otherId] ?? null;
        $cause = (string) ($war['cause'] ?? '');
        if (str_starts_with($cause, 'defend')) {
            return $this->defending($declarerId, $otherId) || $this->defending($otherId, $declarerId);
        }

        return in_array($cause, ['conquest', 'refused-tribute'], true) && $this->hour() - (float) $war['since'] < $this->minds?->setting('conquest_hours');
    }

    private function defending(int $club, int $enemy): bool
    {
        return array_filter($this->treaties->counterparts($club, AiAllianceStance::Nap), fn (int $partner): bool => $this->treaties->holds($partner, $enemy, AiAllianceStance::War)) !== [];
    }

    private function bound(int $club, int $other): bool
    {
        return $this->treaties->holds($club, $other, AiAllianceStance::Nap) || $this->vassalBound($club, $other);
    }

    /** Vassal and overlord leave each other alone. */
    private function vassalBound(int $club, int $other): bool
    {
        return $this->minds !== null && ($this->overlord($club) === $other || $this->overlord($other) === $club);
    }

    private function overlord(int $club): int
    {
        return (int) (((array) $this->minds?->get($this->founder($club), 'overlord', []))['alliance'] ?? 0);
    }

    private function logged(string $kind, int $by, int $with): bool
    {
        $this->events[] = [$kind, $by, $with];

        return true;
    }

    /**
     * This pass's treaty events into the memories of those who saw them (members of the clubs involved), then on the
     * daily pass FAtiMA appraises what each leader saw and AgentOS recalls every mind.
     */
    private function remember(): void
    {
        if ($this->minds === null) {
            return;
        }

        $hour = $this->hour();
        foreach ($this->events as [$kind, $by, $with]) {
            $leaders = ['by' => $this->leader($by), 'with' => $this->leader($with) ?: $this->leader($by)];
            foreach (self::MEMORIES[$kind] ?? [] as [$side, $event, $actor, $valence, $weight]) {
                foreach ($this->members($side === 'by' ? $by : $with) as $member) {
                    $this->minds->saw($member, $event, $leaders[$actor], $hour, $valence, $weight);
                }
            }

            foreach ($kind === 'war' ? [[$this->founder($by), $with], [$this->founder($with), $by]] : [] as [$holder, $other]) {
                $wars = (array) $this->minds->get($holder, 'pair_wars', []);
                $this->minds->put($holder, 'pair_wars', [$other => (int) ($wars[$other] ?? 0) + 1] + $wars);
            }
        }

        if ($this->daily) {
            $this->minds->feel(array_values(Alliance::query()->whereNotNull('founder_user_id')->pluck('id')->map(fn ($id): int => $this->leader((int) $id))->all()), $hour);
            $this->minds->recall($hour);
        }

        $this->minds->save();
    }

    /** The club's standing leader (governance), whose persona decides; the founder while none is recorded. */
    private function leader(int $allianceId): int
    {
        if (!isset($this->leaders[$allianceId])) {
            $founder = $this->founder($allianceId);
            $standing = (int) (((array) $this->minds?->get($founder, 'standing', []))['leader'] ?? 0);
            $this->leaders[$allianceId] = in_array($standing, $this->members($allianceId), true) ? $standing : $founder;
        }

        return $this->leaders[$allianceId];
    }

    /**
     * The host's founder: it speaks for the club on the board (the host has no leadership transfer) and its mind keeps
     * the club's records (wars, pacts, secrets, vassalage, its standing leader), since a founder cannot leave.
     */
    private function founder(int $allianceId): int
    {
        return $this->once('founder:' . $allianceId, static fn (): int => (int) Alliance::query()->whereKey($allianceId)->value('founder_user_id'));
    }

    /**
     * @template T
     * @param callable(): T $read
     * @return T
     */
    private function once(string $key, callable $read): mixed
    {
        if ($this->read === null) {
            return $read();
        }

        return array_key_exists($key, $this->read) ? $this->read[$key] : ($this->read[$key] = $read());
    }

    /** One club's public points over another's. */
    private function ratio(int $club, int $other): float
    {
        return $this->points($club) / max(1.0, $this->points($other));
    }

    private function hour(): float
    {
        return now()->getTimestamp() / 3600.0;
    }

    private function loadPolicy(): void
    {
        $file = Yaml::parseFile(dirname(__DIR__, 2) . self::FILE);
        $this->policy = is_array($file) ? $file : [];
    }

    /**
     * Whether a leader's grudge against the other club is worse than the one against the enemy they share. Two clubs
     * fighting the same giant set a small feud aside to face it (9500: two clubs at war with the giant kept hitting each
     * other and never signed); a feud that is the bigger grudge still refuses the pact.
     */
    private function feudOutweighs(int $leaderId, int $allianceId, int $otherId, int $enemyId): bool
    {
        $own = $this->members($allianceId);

        return $this->hates($leaderId, $this->members($otherId))
            && $this->grievance($own, $this->members($otherId)) >= $this->grievance($own, $this->members($enemyId));
    }

    /** @param list<int> $players */
    private function hates(int $playerId, array $players): bool
    {
        return AiRelationship::query()->where('player_id', $playerId)->whereIn('other_player_id', $players)->where('threat', '>=', Enemies::ENEMY_THREAT)->exists();
    }

    /**
     * The members' threats toward the other's members, read per reference_members of the aggrieved alliance: two raids
     * anger a club of six, not a bloc of a hundred and sixty (alliance-war.yaml; rl/ogsim diplomacy.rs grievance()).
     *
     * @param list<int> $from
     * @param list<int> $toward
     */
    private function grievance(array $from, array $toward): float
    {
        $sum = (float) AiRelationship::query()->whereIn('player_id', $from)->whereIn('other_player_id', $toward)->sum('threat');

        return $sum / max(1.0, count($from) / (float) ($this->policy['reference_members'] ?? 6));
    }

    private function points(int $allianceId): float
    {
        return $this->once('points:' . $allianceId, fn (): float => (float) Highscore::query()->whereIn('player_id', $this->members($allianceId))->sum('general'));
    }

    /** @return list<int> */
    private function members(int $allianceId): array
    {
        return array_values($this->once('members:' . $allianceId, static fn (): array => User::query()->where('alliance_id', $allianceId)->pluck('id')->map(static fn ($id): int => (int) $id)->all()));
    }

    /**
     * The advisor's word on one diplomatic choice (ai.campaign-consultation.advisor, off by default): the teacher's
     * choice is the default and the advisor may pick another legal option; off, a cap or any failure keeps the
     * teacher's. The brief holds what the club's leader can see: both clubs' public members and points, the public war
     * and broken-pact record, its own stance toward them, its own feelings and its own reports on their leader.
     *
     * @param array<string, string> $options id => what it does
     */
    private function advise(string $moment, int $club, int $other, array $options, string $rule): string
    {
        $advisor = app(Advisor::class);
        $leader = $this->leader($club);
        if (!$advisor->enabled(AdvisorArea::Diplomacy) || !AiProfile::query()->where('player_id', $leader)->where('enabled', true)->exists()) {
            return $rule;
        }

        $facts = app(AdvisorFacts::class);
        $theirLeader = $this->leader($other);
        $hours = (string) (int) app(SocialMinds::class)->setting('pact_notice_hours');
        $side = fn (int $id): array => ['tag' => (string) Alliance::query()->whereKey($id)->value('alliance_tag')] + $facts->club($id)
            + ['open_wars' => count($this->treaties->counterparts($id, AiAllianceStance::War)), 'pacts_broken' => $this->treaties->pactsBroken($id)];

        return $advisor->consult(new AdvisorQuestion(
            AdvisorArea::Diplomacy,
            $leader,
            $moment,
            array_map(static fn (string $id, string $summary): array => ['id' => $id, 'summary' => str_replace('{hours}', $hours, $summary)], array_keys($options), array_values($options)),
            $rule,
            [
                'us' => $side($club),
                'them' => $side($other),
                'between_us' => $this->treaties->holds($club, $other, AiAllianceStance::War) ? 'war' : ($this->treaties->holds($club, $other, AiAllianceStance::Nap) ? 'pact' : 'none'),
                'my_feelings_toward_their_leader' => $facts->feelings($leader, $theirLeader, $moment),
            ],
            $facts->reports($leader, $theirLeader),
        ))->choice;
    }

    /** The leader's own wording of one announcement; the model words it when it is on, and the authored line is the fallback. */
    private function line(string $key, string $tag, int $speakerId = 0, int $toPlayerId = 0, string $other = ''): string
    {
        $line = ((array) ($this->policy['lines'] ?? []))[$key] ?? '';
        // Several wordings per line, picked by the other alliance's tag so the same declaration is not repeated verbatim.
        $line = is_array($line) ? (string) ($line[crc32($key . $tag) % max(1, count($line))] ?? '') : (string) $line;

        $hours = (string) (int) app(SocialMinds::class)->setting('pact_notice_hours');
        $line = str_replace(['{tag}', '{other}', '{hours}'], [$tag, $other, $hours], $line);
        $profile = $speakerId > 0 ? AiProfile::query()->where('player_id', $speakerId)->where('enabled', true)->first() : null;

        // With the diplomacy advisor applying, DeepSeek words the line in the persona's voice and the recipient's language.
        $advised = app(Advisor::class)->applies(AdvisorArea::Diplomacy);

        return $profile === null || $line === '' ? $line : app(ComposeAiLineAction::class)->handle($profile, 'alliance_politics.' . $key, $line, ['tag' => $tag, 'other' => $other], $toPlayerId > 0 ? $toPlayerId : null, $advised);
    }
}
