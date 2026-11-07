<?php

namespace Modules\AI\Domain\Social;

use Modules\AI\Infrastructure\Cognition\SocialGatewayClient;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiSocialMind;
use OGame\Models\Alliance;
use OGame\Models\BattleReport;
use Symfony\Component\Yaml\Yaml;

/**
 * The living minds of the AI accounts (SOCIAL-ENGINES-CONTRACT.md), as the simulator keeps them (rl/ogsim src/mind.rs):
 * what each account saw, its persona, and the engines' state it carries from call to call. The engines compute through
 * the social gateway (FAtiMA appraisal and exchange, PsychSim partner belief, CBRKit similar cases, AgentOS recall);
 * this class only keeps state, records what an account saw and talks to the gateway. Hours are Unix hours.
 */
class SocialMinds
{
    private const FILE = '/resources/behavior/social-minds.yaml';

    /** A mind's memory event -> FAtiMA social event (mind.rs `fatima_event`, scenarios/ogame-social.json). */
    private const FATIMA_EVENTS = [
        'attacked' => 'attacked', 'revolt' => 'attacked', 'war_declared' => 'threatened', 'pact_refused' => 'threatened',
        'betrayed' => 'betrayed', 'pact' => 'pact_offered', 'peace' => 'pact_kept', 'submitted' => 'helped',
        'dominated' => 'tribute_demanded', 'betrayal_exposed' => 'leak_heard', 'secret_pact_exposed' => 'leak_heard',
        'coup' => 'coup', 'overthrown' => 'coup', 'coup_failed' => 'coup', 'plot_foiled' => 'coup',
    ];

    /** A memory event -> the partner act PsychSim reads (diplomacy.rs `ask_partners`). */
    private const PARTNER_ACTS = [
        'betrayed' => 'betrayal', 'betrayal_exposed' => 'betrayal', 'attacked' => 'attack', 'war_declared' => 'attack',
        'pact' => 'kept', 'peace' => 'kept', 'submitted' => 'tribute',
    ];

    /** AgentOS minds per gateway item, so the gateway fans the chunks out over its replicas (mind.rs `recall`). */
    private const RECALL_CHUNK = 16;

    /** @var array<int, array<string, mixed>> */
    private array $minds = [];

    /** @var array<int, int>|null enabled AI account -> its seed */
    private ?array $seeds = null;

    /** @var array<string, mixed>|null */
    private ?array $file = null;

    public function __construct(private readonly SocialGatewayClient $gateway, private readonly SocialPersona $persona)
    {
    }

    public function gatewayAnswers(): bool
    {
        return $this->gateway->answers();
    }

    public function setting(string $key): float
    {
        if ($this->file === null) {
            $file = Yaml::parseFile(dirname(__DIR__, 3) . self::FILE);
            $this->file = is_array($file) ? $file : [];
        }

        return (float) ($this->file[$key] ?? 0.0);
    }

    /** A trait an observer would assume of an account: its own when it has a mind, neutral (0.5) for a human. */
    public function traitOr(int $userId, string $name): float
    {
        return $this->has($userId) ? $this->trait($userId, $name) : 0.5;
    }

    /** One uniform draw in 0..1 for a named stream on a game day: the same day and stream always draw the same. */
    public function draw(string $stream, int $day): float
    {
        return hexdec(substr(hash('xxh64', $day . ':' . $stream), 0, 13)) / 4_503_599_627_370_496.0;
    }

    /**
     * The alliances bound to this one by vassalage either way (diplomacy.rs `bound`). The bond is kept in the vassal
     * club founder's mind (`overlord`), so it is read straight from the rows: a raid planner runs outside the pass. With
     * the minds off there is no vassalage.
     *
     * @return list<int>
     */
    public function vassalBonds(int $allianceId): array
    {
        if (!(bool) config('ai.cognition.social.minds', false)) {
            return [];
        }

        $founder = Alliance::query()->whereKey($allianceId)->value('founder_user_id');
        $lord = ((array) AiSocialMind::query()->where('user_id', (int) $founder)->first()?->state)['overlord']['alliance'] ?? null;
        $vassalFounders = AiSocialMind::query()->where('state->overlord->alliance', $allianceId)->pluck('user_id');

        return array_values(Alliance::query()->whereIn('founder_user_id', $vassalFounders)->pluck('id')->push($lord)->filter(static fn ($id): bool => is_numeric($id))
            ->map(static fn ($id): int => (int) $id)->unique()->all());
    }

    /**
     * The band founder an account was moved toward by a schism or a nomad uprising (diplomacy.rs `pinned`), 0 when none;
     * read straight from the row, since the alliance life that must hold it there runs outside the pass.
     */
    public function pinnedTo(int $userId): int
    {
        if (!(bool) config('ai.cognition.social.minds', false) || !(bool) config('ai.cognition.social.cycles', false)) {
            return 0;
        }

        return (int) (((array) AiSocialMind::query()->where('user_id', $userId)->first()?->state)['pinned'] ?? 0);
    }

    /** Only an enabled AI account has a mind; a human's is its own. */
    public function has(int $userId): bool
    {
        return isset($this->seeds()[$userId]);
    }

    public function get(int $userId, string $key, mixed $default = null): mixed
    {
        return $this->has($userId) ? ($this->mind($userId)[$key] ?? $default) : $default;
    }

    public function put(int $userId, string $key, mixed $value): void
    {
        if ($this->has($userId)) {
            $this->mind($userId);
            $this->minds[$userId][$key] = $value;
        }
    }

    /** @return array<string, float> */
    public function traits(int $userId): array
    {
        return (array) $this->get($userId, 'persona', ['traits' => []])['traits'];
    }

    public function trait(int $userId, string $name): float
    {
        return (float) ($this->traits($userId)[$name] ?? 0.0);
    }

    /** Experience moves an account's traits (social-persona.yaml `drift`). */
    public function experience(int $userId, string $event): void
    {
        if ($this->has($userId)) {
            $persona = (array) $this->get($userId, 'persona');
            $this->put($userId, 'persona', ['traits' => $this->persona->experience($this->traits($userId), $event)] + $persona);
        }
    }

    /** Records what $who saw: $actor did $event (valence < 0 harm, > 0 help) at $hour, kept fresh until appraised. */
    public function saw(int $who, string $event, int $actor, float $hour, float $valence, float $weight): void
    {
        if ($who === $actor || !$this->has($who)) {
            return;
        }

        $id = (int) $this->get($who, 'next_id', 0) + 1;
        $this->put($who, 'next_id', $id);
        $this->put($who, 'fresh', [...(array) $this->get($who, 'fresh', []), ['id' => $id, 'event' => $event, 'actor' => $actor, 'at' => $hour, 'valence' => $valence, 'weight' => $weight]]);
    }

    /** Each AI account's own combat reports of hits on its planets since it last looked (diplomacy.rs `hit`). */
    public function recordAttacks(): void
    {
        foreach (array_keys($this->seeds()) as $userId) {
            $cursor = (int) $this->get($userId, 'report_cursor', 0);
            $reports = BattleReport::query()->where('planet_user_id', $userId)->where('id', '>', $cursor)->orderBy('id')->get(['id', 'attacker', 'created_at']);
            foreach ($reports as $report) {
                $attacker = $report->attacker['player_id'] ?? null;
                if (is_numeric($attacker)) {
                    $this->saw($userId, 'attacked', (int) $attacker, ($report->created_at ?? now())->getTimestamp() / 3600.0, -1.0, 0.5);
                }
            }

            $this->put($userId, 'report_cursor', (int) ($reports->last()->id ?? $cursor));
        }
    }

    /**
     * PsychSim's partner model for each question [asker, the partner club's members, the partner's leader, partner
     * points / own points]. The asker's belief and bond travel and come back; only the partner's acts the asker saw
     * since the last question are sent. Null where the engine did not answer.
     *
     * @param list<array{0: int, 1: list<int>, 2: int, 3: float}> $questions
     * @return list<array<string, mixed>|null>
     */
    public function askPartners(array $questions): array
    {
        $items = [];
        $lastSeen = [];
        foreach ($questions as [$asker, $partners, $partnerLeader, $ratio]) {
            $held = (array) (((array) $this->get($asker, 'beliefs', []))[$partnerLeader]['state'] ?? []);
            $since = (int) ($held['last'] ?? 0);
            $acts = array_values(array_filter(
                [...(array) $this->get($asker, 'memories', []), ...(array) $this->get($asker, 'fresh', [])],
                static fn (array $m): bool => $m['id'] > $since && in_array($m['actor'], $partners, true)
            ));
            $history = array_values(array_filter(array_map(static fn (array $m): array|null => isset(self::PARTNER_ACTS[$m['event']]) ? ['action' => self::PARTNER_ACTS[$m['event']], 't' => $m['at']] : null, $acts)));
            $lastSeen[] = max([$since, ...array_column($acts, 'id')]);
            $traits = $this->traits($asker);
            $body = [
                // The ratio in quarter steps, so repeated questions hit the gateway's cache.
                'points_ratio' => min(8.0, max(0.25, round($ratio * 4.0) / 4.0)),
                'my_traits' => array_intersect_key($traits, array_flip(['loyalty', 'honesty', 'ambition', 'cunning'])) ?: (object) [],
                'horizon' => 2,
                'history' => $history,
            ];
            foreach (['belief', 'bond'] as $carried) {
                if (isset($held[$carried])) {
                    $body[$carried] = $held[$carried];
                }
            }

            $items[] = ['engine' => 'psychsim', 'route' => '/social/partner', 'body' => ['items' => [$body]]];
        }

        $answers = [];
        foreach ($this->gateway->batch($items) as $i => $answer) {
            $result = $this->first($answer);
            [$asker, , $partnerLeader] = $questions[$i];
            if ($result !== null) {
                $beliefs = (array) $this->get($asker, 'beliefs', []);
                $beliefs[$partnerLeader] = ['p_loyal' => (float) ($result['p_loyal'] ?? 0.5), 'state' => ['belief' => $result['belief'] ?? null, 'bond' => $result['bond'] ?? null, 'last' => $lastSeen[$i]]];
                $this->put($asker, 'beliefs', $beliefs);
            }

            $answers[] = $result;
        }

        return $answers;
    }

    /**
     * FAtiMA's social exchange (its emotions and relations decide): [asker, other, context, options] -> the choice.
     *
     * @param list<array{0: int, 1: int, 2: array<string, mixed>, 3: list<string>}> $questions
     * @return list<string|null>
     */
    public function exchange(array $questions, float $hour): array
    {
        $items = array_map(fn (array $q): array => ['engine' => 'fatima', 'route' => '/social/exchange', 'body' => ['items' => [
            $this->fatimaItem($q[0], $hour) + ['other' => (string) $q[1], 'context' => $q[2], 'options' => $q[3]],
        ]]], $questions);

        return array_map(fn (array|null $answer): string|null => is_string($this->first($answer)['choice'] ?? null) ? $this->first($answer)['choice'] : null, $this->gateway->batch($items));
    }

    /**
     * CBRKit: how pacts in situations like each one ended; true when most similar cases ended in betrayal.
     *
     * @param list<array<string, int|string>> $situations
     * @return list<bool>
     */
    public function wary(array $situations): array
    {
        $items = array_map(static fn (array $situation): array => ['engine' => 'cbrkit', 'route' => '/social/similar', 'body' => ['items' => [['situation' => $situation, 'k' => 10]]]], $situations);

        return array_map(function (array|null $answer): bool {
            $cases = (array) ($this->first($answer)['cases'] ?? []);
            $betrayed = count(array_filter($cases, static fn (mixed $case): bool => is_array($case) && ($case['outcome']['label'] ?? null) === 'betrayed'));

            return $cases !== [] && 2 * $betrayed > count($cases);
        }, $this->gateway->batch($items));
    }

    /**
     * FAtiMA appraisal of what each leader saw since the last pass: its character state comes back with emotions
     * decayed by the toolkit, mood and relations moved.
     *
     * @param list<int> $leaders
     */
    public function feel(array $leaders, float $hour): void
    {
        $asked = [];
        $items = [];
        foreach (array_unique($leaders) as $leader) {
            $born = (float) $this->get($leader, 'born', $hour);
            $events = array_values(array_filter(array_map(static fn (array $m): array|null => isset(self::FATIMA_EVENTS[$m['event']])
                ? ['event' => self::FATIMA_EVENTS[$m['event']], 'actor' => (string) $m['actor'], 'intensity' => min(1.0, max(0.0, (float) $m['weight'])), 'tick' => (int) floor($m['at'] - $born)]
                : null, (array) $this->get($leader, 'fresh', []))));
            if ($events === []) {
                continue;
            }

            $asked[] = $leader;
            $items[] = ['engine' => 'fatima', 'route' => '/social/appraise', 'body' => ['items' => [$this->fatimaItem($leader, $hour) + ['events' => $events]]]];
        }

        foreach ($this->gateway->batch($items) as $i => $answer) {
            $result = $this->first($answer);
            if (isset($result['state'])) {
                $this->put($asked[$i], 'fatima', $result['state']);
            }

            if (isset($result['mood'])) {
                $this->put($asked[$i], 'mood', (float) $result['mood']);
            }
        }
    }

    /**
     * AgentOS episodic recall for every mind that remembers something: fresh events join the memories, AgentOS encodes,
     * decays, consolidates and ranks them with the persona's HEXACO traits, and the per-actor aggregate comes back.
     */
    public function recall(float $hour): void
    {
        foreach (AiSocialMind::query()->whereNotIn('user_id', array_keys($this->minds))->pluck('user_id') as $userId) {
            $this->has((int) $userId) && $this->mind((int) $userId);
        }

        $who = [];
        $items = [];
        foreach (array_keys($this->minds) as $userId) {
            $memories = array_slice([...(array) $this->get($userId, 'memories', []), ...(array) $this->get($userId, 'fresh', [])], -(int) $this->setting('memory_cap'));
            $this->put($userId, 'memories', $memories);
            $this->put($userId, 'fresh', []);
            if ($memories === []) {
                continue;
            }

            $who[] = $userId;
            $items[] = ['scopeId' => 'account-' . $userId, 'query' => 'who did what to me', 'now' => $hour * 3600.0, 'traits' => $this->hexaco($userId),
                'memories' => array_map(static fn (array $m): array => ['id' => $m['id'], 'text' => str_replace('_', ' ', $m['event']) . ' by ' . $m['actor'],
                    'tags' => [$m['event'], ...match ($m['event']) {
                        'betrayed', 'betrayal_exposed' => ['promise_broken'], 'pact' => ['promise_kept'], default => []
                    }],
                    'at' => $m['at'] * 3600.0, 'valence' => $m['valence'], 'arousal' => $m['weight'], 'actor' => (string) $m['actor']], $memories)];
        }

        $chunks = array_chunk($items, self::RECALL_CHUNK);
        $answers = $this->gateway->batch(array_map(static fn (array $chunk): array => ['engine' => 'agentos', 'route' => '/social/recall', 'body' => ['items' => $chunk]], $chunks));
        foreach ($answers as $k => $answer) {
            foreach ((array) ($answer['results'] ?? []) as $j => $result) {
                $userId = $who[$k * self::RECALL_CHUNK + $j] ?? null;
                if ($userId === null || !is_array($result['actors'] ?? null)) {
                    continue;
                }

                $this->put($userId, 'recalled', array_map(static fn (array $a): array => ['grievance' => (float) ($a['grievance'] ?? 0.0), 'gratitude' => (float) ($a['gratitude'] ?? 0.0),
                    'broken' => (float) ($a['brokenPromises'] ?? 0.0), 'kept' => (float) ($a['keptPromises'] ?? 0.0)], $result['actors']));
            }
        }
    }

    /**
     * What a leader still remembers of a club's members (AgentOS recall): summed grievance and gratitude.
     *
     * @param list<int> $members
     * @return array{0: float, 1: float}
     */
    public function ledger(int $leader, array $members): array
    {
        $recalled = (array) $this->get($leader, 'recalled', []);
        $held = array_intersect_key($recalled, array_flip($members));

        return [array_sum(array_column($held, 'grievance')), array_sum(array_column($held, 'gratitude'))];
    }

    public function save(): void
    {
        foreach ($this->minds as $userId => $state) {
            AiSocialMind::query()->updateOrCreate(['user_id' => $userId], ['state' => $state]);
        }
    }

    /** @return array<int, int> enabled AI account -> its seed */
    private function seeds(): array
    {
        return $this->seeds ??= AiProfile::query()->where('enabled', true)->pluck('random_seed', 'player_id')
            ->mapWithKeys(static fn ($seed, $userId): array => [(int) $userId => (int) $seed])->all();
    }

    /** @return array<string, mixed> */
    private function mind(int $userId): array
    {
        if (!isset($this->minds[$userId])) {
            $state = AiSocialMind::query()->where('user_id', $userId)->first()?->state;
            $this->minds[$userId] = is_array($state) ? $state : $this->born($userId);
        }

        return $this->minds[$userId];
    }

    /**
     * A new mind: its persona drawn from its seed, and it starts watching from today's reports (it remembers what it
     * sees from now on).
     *
     * @return array<string, mixed>
     */
    private function born(int $userId): array
    {
        return [
            'persona' => $this->persona->draw($this->seeds()[$userId], $userId),
            'born' => floor(now()->getTimestamp() / 3600.0),
            'mood' => 0.0, 'memories' => [], 'fresh' => [], 'next_id' => 0, 'fatima' => null, 'recalled' => [], 'beliefs' => [],
            'report_cursor' => (int) BattleReport::query()->max('id'),
        ];
    }

    /**
     * The persona and carried state FAtiMA reads. Its clock is hours since the mind was born: the toolkit restarts a
     * character's clock at 0 and advances it a bounded step per call, so Unix hours would decay every emotion at once.
     *
     * @return array<string, mixed>
     */
    private function fatimaItem(int $userId, float $hour): array
    {
        $item = ['persona' => ['traits' => $this->traits($userId) ?: (object) []], 'now' => (int) floor($hour - (float) $this->get($userId, 'born', $hour))];
        $state = $this->get($userId, 'fatima');

        return $state === null ? $item : $item + ['state' => $state];
    }

    /**
     * Persona -> AgentOS's HEXACO: vindictive minds encode harm stronger, forgiving ones reappraise faster.
     *
     * @return array<string, float>
     */
    private function hexaco(int $userId): array
    {
        $t = fn (string $name): float => $this->trait($userId, $name);

        return ['emotionality' => $t('vindictiveness'), 'agreeableness' => $t('forgiveness'), 'honesty' => $t('honesty'),
            'conscientiousness' => $t('loyalty'), 'extraversion' => $t('dominance'), 'openness' => 0.5];
    }

    /**
     * The first result of a social route's batch answer.
     *
     * @param array<string, mixed>|null $answer
     * @return array<string, mixed>|null
     */
    private function first(array|null $answer): array|null
    {
        $result = $answer['results'][0] ?? null;

        return is_array($result) && !isset($result['error']) ? $result : null;
    }
}
