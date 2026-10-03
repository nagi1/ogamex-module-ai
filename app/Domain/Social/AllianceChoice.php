<?php

namespace Modules\AI\Domain\Social;

use Modules\AI\Enums\AiToMStance;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\PsychSimTheoryOfMind;
use OGame\Models\Alliance;
use OGame\Models\AllianceApplication;
use OGame\Models\AllianceHighscore;
use OGame\Models\AllianceMember;
use OGame\Models\Highscore;
use OGame\Models\User;
use Symfony\Component\Yaml\Yaml;

/**
 * Picks the alliance a human-like account would apply to, from host data alone.
 *
 * The join decision is "which club will actually take me, and can I keep up with it" (alliance
 * FAQ). The account's rank against the population stays the first filter: a weak account looks
 * for a mass/open club, a strong one for a high points-per-member core, a mid one balances the
 * two (all in resources/behavior/alliance_fit.yaml). Among the clubs the rank admits, the fit rule
 * decides: the applicant's language and activity band against the club's, both read from host
 * state — the club's through its founder. A club no account of this language and pace fits is
 * never asked, so the account stays free for the pass's founder half; the club that then appears
 * carries that account's language and pace, so a cohort spreads over clubs instead of piling into
 * one. An alliance that already rejected the account is never re-applied to.
 */
class AllianceChoice
{
    private const FIT_FILE = '/resources/behavior/alliance_fit.yaml';

    private const RECRUITMENT_FILE = '/resources/behavior/alliance-recruitment.yaml';

    /** @var array<string, mixed>|null */
    private array|null $policy = null;

    private bool $policyRead = false;

    /** @var array{share_ceiling: float, minimum_cohort: int}|null */
    private array|null $recruitment = null;

    private bool $recruitmentRead = false;

    /** @var list<int>|null */
    private array|null $aiManagers = null;

    public function choose(int $playerId): ?Alliance
    {
        $policy = $this->policy();
        $rank = (int) Highscore::query()->where('player_id', $playerId)->value('general_rank');
        $population = (int) Highscore::query()->where('general_rank', '>', 0)->count();

        if ($policy === null || $rank <= 0 || $population <= 0) {
            return null;
        }

        $ratio = $rank / $population;
        $account = $this->accountFit($playerId);
        $best = null;
        $bestScore = -INF;

        foreach ($this->openCandidates($playerId) as $alliance) {
            $club = $this->clubFit($alliance);

            if (! $this->fits($account, $club, $policy['fit'])) {
                continue;
            }

            $score = $this->tierScore($alliance, $ratio, $policy['tier']) + $this->fitScore($account, $club, $policy['fit']);

            if ($score > $bestScore) {
                $best = $alliance;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Whether some open alliance fits the account. Without one the pass's founder half gives the
     * account a club of its own, whose language and pace are the account's own — so the cohort's
     * clubs are founded by accounts of different archetypes instead of all draining into the
     * first club that exists.
     */
    public function hasOpenCandidate(int $playerId): bool
    {
        $policy = $this->policy();

        if ($policy === null) {
            return $this->openCandidates($playerId) !== [];
        }

        $account = $this->accountFit($playerId);

        foreach ($this->openCandidates($playerId) as $alliance) {
            if ($this->fits($account, $this->clubFit($alliance), $policy['fit'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the club the account sits in still fits it: the same language and pace rule that picked
     * it. A club that does not is one a player leaves, which is how a cohort that all joined one club
     * spreads out instead of staying piled into it.
     */
    public function currentClubFits(int $playerId): bool
    {
        $policy = $this->policy();
        $allianceId = User::query()->whereKey($playerId)->value('alliance_id');
        $club = $allianceId === null ? null : Alliance::query()->find($allianceId);

        if ($policy === null || $club === null) {
            return true;
        }

        // A club the host keeps shut takes no applications, so a seat in it is a dead end: the
        // member belongs to nothing that can ever grow, which is the state where the whole
        // cohort sits still and the lane the scorecard's alliance aspect measures asks nobody
        // (ALLY-001). Seeded clubs are created shut, so this is the common live case.
        if (! $club->is_open) {
            return false;
        }

        if ($this->holdsCohortShare($club)) {
            return false;
        }

        return $this->fits($this->accountFit($playerId), $this->clubFit($club), $policy['fit']);
    }

    /** @return list<Alliance> */
    private function openCandidates(int $playerId): array
    {
        return array_values(
            Alliance::query()
                ->where('is_open', true)
                ->whereDoesntHave('applications', fn ($query) => $query
                    ->where('user_id', $playerId)
                    ->where('status', AllianceApplication::STATUS_REJECTED))
                ->get()
                ->reject(fn (Alliance $alliance): bool => $this->readsFounderAsExploitative($playerId, $alliance) || $this->holdsCohortShare($alliance))
                ->all(),
        );
    }

    /**
     * Whether the club already holds the share of the AI accounts the invariant forbids. Such a club
     * is no longer asked, and a member of it leaves it: the cohort spreads over clubs instead of
     * sitting in the one the seeder filled, which is the state where the join half stops asking and
     * the lane the scorecard's alliance aspect measures creates no application again (ALLY-001).
     * Below minimum_cohort a share means nothing, so no club is read as holding it.
     */
    private function holdsCohortShare(Alliance $alliance): bool
    {
        $ceiling = $this->recruitment();

        if ($ceiling === null) {
            return false;
        }

        $managers = $this->aiManagers();
        $cohort = count($managers);

        if ($cohort < $ceiling['minimum_cohort']) {
            return false;
        }

        // The share is read from both halves of a seat: the host's own membership pointer, which is
        // what the cohort read that raises ALLIANCE_SHARE counts, and the membership row. A cohort
        // seated through the pointer alone — as seeding left grand — counts zero on the row half, so
        // a club holding most of the cohort read as empty, its members never became misfits, and the
        // lane the scorecard's alliance aspect measures created no application (ALLY-001).
        $members = User::query()->where('alliance_id', $alliance->id)->whereIn('id', $managers)->pluck('id')
            ->merge(AllianceMember::query()->where('alliance_id', $alliance->id)->whereIn('user_id', $managers)->pluck('user_id'))
            ->unique()
            ->count();

        return $members / $cohort >= $ceiling['share_ceiling'];
    }

    /** @return list<int> the accounts the module drives: only their seats count toward the share. */
    private function aiManagers(): array
    {
        return $this->aiManagers ??= AiProfile::query()->where('enabled', true)->pluck('player_id')
            ->map(static fn ($playerId): int => (int) $playerId)->all();
    }

    /**
     * The ceiling read by name from resources/behavior, so the share the cohort invariant states is
     * one number a tuning pass moves. A missing or unreadable file leaves the club fit rule alone in
     * force rather than a default the source does not state.
     *
     * @return array{share_ceiling: float, minimum_cohort: int}|null
     */
    private function recruitment(): array|null
    {
        if ($this->recruitmentRead) {
            return $this->recruitment;
        }

        $this->recruitmentRead = true;
        $path = dirname(__DIR__, 3) . self::RECRUITMENT_FILE;
        $parsed = is_file($path) ? Yaml::parseFile($path) : null;

        if (!is_array($parsed) || !isset($parsed['share_ceiling'], $parsed['minimum_cohort'])) {
            return $this->recruitment = null;
        }

        return $this->recruitment = [
            'share_ceiling' => (float) $parsed['share_ceiling'],
            'minimum_cohort' => (int) $parsed['minimum_cohort'],
        ];
    }

    /**
     * A wary account will not join a club whose founder it models as likely to exploit it, so a
     * Defect read removes that alliance from the account's candidates. An absent relationship or
     * an absent driver read keeps the alliance in the running, exactly as the native choice did.
     */
    private function readsFounderAsExploitative(int $playerId, Alliance $alliance): bool
    {
        return app(PsychSimTheoryOfMind::class)->stanceToward($playerId, (int) $alliance->founder_user_id) === AiToMStance::Defect;
    }

    /** @return array{language: string|null, band: int|null} */
    private function accountFit(int $playerId): array
    {
        return ['language' => $this->language($playerId), 'band' => $this->band($playerId)];
    }

    /**
     * The club's own language and pace, read from its founder: the one account whose host state
     * stands for the club the founder keeps.
     *
     * @return array{language: string|null, band: int|null}
     */
    private function clubFit(Alliance $alliance): array
    {
        $founderId = (int) $alliance->founder_user_id;

        return ['language' => $this->language($founderId), 'band' => $this->band($founderId)];
    }

    /** The account's declared client language, or null when the host holds none. */
    private function language(int $playerId): string|null
    {
        $lang = strtolower(trim((string) User::query()->whereKey($playerId)->value('lang')));

        return $lang === '' ? null : $lang;
    }

    /** How much the account is around, from its own profile; null when it states no pace. */
    private function band(int $playerId): int|null
    {
        // The pace the club or applicant states comes from its own profile row.
        return AiProfile::query()->where('player_id', $playerId)->first()?->activity_band?->value;
    }

    private function fits(array $account, array $club, array $fit): bool
    {
        if ($account['language'] !== null && $club['language'] !== null && $account['language'] !== $club['language']) {
            return false;
        }

        return $this->bandDistance($account['band'], $club['band']) <= (int) $fit['band']['tolerance'];
    }

    /** Steps between two paces; an unstated pace on either side is no distance at all. */
    private function bandDistance(int|null $account, int|null $club): int
    {
        if ($account === null || $club === null) {
            return 0;
        }

        return abs($account - $club);
    }

    private function fitScore(array $account, array $club, array $fit): float
    {
        return $this->languageScore($account['language'], $club['language'], $fit['language'])
            + $this->bandScore($account['band'], $club['band'], $fit['band']);
    }

    private function languageScore(string|null $account, string|null $club, array $weights): float
    {
        if ($account === null || $club === null) {
            return 0.0;
        }

        return (float) ($account === $club ? $weights['match'] : $weights['mismatch']);
    }

    private function bandScore(int|null $account, int|null $club, array $weights): float
    {
        if ($account === null || $club === null) {
            return 0.0;
        }

        return match ($this->bandDistance($account, $club)) {
            0 => (float) $weights['match'],
            1 => (float) $weights['neighbour'],
            default => (float) $weights['mismatch'],
        };
    }

    private function tierScore(Alliance $alliance, float $rankRatio, array $tier): float
    {
        $members = (int) AllianceMember::query()->where('alliance_id', $alliance->id)->count();
        $points = (int) (AllianceHighscore::query()->where('alliance_id', $alliance->id)->value('general') ?? 0);
        // Log scale keeps a million-point elite core comparable with a catch-all's raw size.
        $quality = log10(max(1.0, $points / max(1, $members)));
        $size = min($members, (int) $tier['size_cap']);
        // A readable pitch is the applicant's own filter: demanding alliances write one.
        $pitch = trim((string) ($alliance->external_text . $alliance->application_text)) !== '' ? (float) $tier['pitch'] : 0.0;

        if ($rankRatio <= (float) $tier['strong_rank_ratio']) {
            return $quality * (float) $tier['strong_quality_weight'] + $pitch;
        }

        if ($rankRatio >= (float) $tier['newbie_rank_ratio']) {
            return $size * (float) $tier['newbie_size_weight'] + $pitch;
        }

        return $quality * (float) $tier['mid_quality_weight'] + $size * (float) $tier['mid_size_weight'] + $pitch;
    }

    /**
     * Read by name from resources/behavior so the fit the cohort invariant states is a number a
     * tuning pass moves without touching this class. A missing or unreadable file leaves the
     * account with no fit rule: it then weighs clubs on the host's own appeal alone.
     *
     * @return array<string, mixed>|null
     */
    private function policy(): array|null
    {
        if ($this->policyRead) {
            return $this->policy;
        }

        $this->policyRead = true;
        $path = dirname(__DIR__, 3) . self::FIT_FILE;
        $parsed = is_file($path) ? Yaml::parseFile($path) : null;

        return $this->policy = is_array($parsed) ? $parsed : null;
    }
}
