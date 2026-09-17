<?php

namespace Modules\AI\Domain\Social;

use OGame\Models\Alliance;
use OGame\Models\AllianceApplication;
use OGame\Models\AllianceHighscore;
use OGame\Models\AllianceMember;
use OGame\Models\Highscore;

/**
 * Picks the alliance a human-like account would apply to, from host data alone.
 *
 * The join decision is "how good am I, and which tier will actually take me" (alliance FAQ):
 * a weak account lands in a mass/open alliance, a mid account in a normal active one, and a
 * strong account in a high points-per-member core. The account's rank against the whole
 * population picks the tier; the host's own alliance rows supply size, points and the pitch.
 * An alliance that already rejected the account is never re-applied to.
 */
class AllianceChoice
{
    private const STRONG_RANK_RATIO = 0.15;

    private const NEWBIE_RANK_RATIO = 0.5;

    public function choose(int $playerId): ?Alliance
    {
        $rank = (int) Highscore::query()->where('player_id', $playerId)->value('general_rank');
        $population = (int) Highscore::query()->where('general_rank', '>', 0)->count();

        if ($rank <= 0 || $population <= 0) {
            return null;
        }

        $ratio = $rank / $population;
        $best = null;
        $bestScore = -1.0;

        foreach ($this->openCandidates($playerId) as $alliance) {
            $score = $this->score($alliance, $ratio);

            if ($score > $bestScore) {
                $best = $alliance;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** @return list<Alliance> */
    private function openCandidates(int $playerId): array
    {
        return Alliance::query()
            ->where('is_open', true)
            ->whereDoesntHave('applications', fn ($query) => $query
                ->where('user_id', $playerId)
                ->where('status', AllianceApplication::STATUS_REJECTED))
            ->get()
            ->all();
    }

    private function score(Alliance $alliance, float $rankRatio): float
    {
        $members = (int) AllianceMember::query()->where('alliance_id', $alliance->id)->count();
        $points = (int) (AllianceHighscore::query()->where('alliance_id', $alliance->id)->value('general') ?? 0);
        // Log scale keeps a million-point elite core comparable with a catch-all's raw size.
        $quality = log10(max(1.0, $points / max(1, $members)));
        $size = min($members, 100);
        // A readable pitch is the applicant's own filter: demanding alliances write one.
        $pitch = trim((string) ($alliance->external_text . $alliance->application_text)) !== '' ? 1.0 : 0.0;

        if ($rankRatio <= self::STRONG_RANK_RATIO) {
            return $quality * 2.0 + $pitch;
        }

        if ($rankRatio >= self::NEWBIE_RANK_RATIO) {
            return $size * 2.0 + $pitch;
        }

        return $quality + $size + $pitch;
    }
}
