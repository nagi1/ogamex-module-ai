<?php

namespace Modules\AI\Infrastructure\Diplomacy;

use Carbon\CarbonInterface;
use Modules\AI\Contracts\AllianceTreaties;
use Modules\AI\Enums\AiAllianceStance;
use Modules\AI\Models\AiAllianceRelation;

/** The module's own record of alliance wars and pacts, used when no diplomacy module is enabled. */
class AiAllianceRelationTreaties implements AllianceTreaties
{
    public function holds(int $allianceId, int $otherId, AiAllianceStance $stance): bool
    {
        return AiAllianceRelation::query()->openBetween($allianceId, $otherId)->where('stance', $stance)->exists();
    }

    public function related(int $allianceId, int $otherId): bool
    {
        return AiAllianceRelation::query()->openBetween($allianceId, $otherId)->exists();
    }

    public function counterparts(int $allianceId, AiAllianceStance $stance): array
    {
        return AiAllianceRelation::counterparts($allianceId, $stance);
    }

    public function warsStartedBy(CarbonInterface $startedBy, string $exceptCause): array
    {
        return AiAllianceRelation::query()->whereNull('ended_at')->where('stance', AiAllianceStance::War)->where('cause', '!=', $exceptCause)
            ->where('started_at', '<=', $startedBy)->orderBy('id')->get(['alliance_id', 'other_alliance_id'])
            ->map(static fn (AiAllianceRelation $war): array => [(int) $war->alliance_id, (int) $war->other_alliance_id])->values()->all();
    }

    public function openWar(int $declarerId, int $otherId, string $cause, int|null $byUserId): void
    {
        AiAllianceRelation::query()->openBetween($declarerId, $otherId)->update(['ended_at' => now()]);
        $this->open($declarerId, $otherId, AiAllianceStance::War, $cause, $byUserId);
    }

    public function endWar(int $allianceId, int $otherId): void
    {
        AiAllianceRelation::query()->openBetween($allianceId, $otherId)->where('stance', AiAllianceStance::War)->update(['ended_at' => now()]);
    }

    public function openPact(int $allianceId, int $otherId, string $cause, int|null $byUserId): void
    {
        $this->open($allianceId, $otherId, AiAllianceStance::Nap, $cause, $byUserId);
    }

    public function breakPact(int $breakerId, int $otherId, int|null $byUserId): bool
    {
        return AiAllianceRelation::query()->openBetween($breakerId, $otherId)->where('stance', AiAllianceStance::Nap)->update(['ended_at' => now()]) > 0;
    }

    public function cancelPact(int $cancellerId, int $otherId, CarbonInterface $noticeEnds): bool
    {
        return AiAllianceRelation::query()->openBetween($cancellerId, $otherId)->where('stance', AiAllianceStance::Nap)->whereNull('notice_ends_at')
            ->update(['notice_ends_at' => $noticeEnds, 'cancelled_by_alliance_id' => $cancellerId]) > 0;
    }

    public function inNotice(int $allianceId, int $otherId): bool
    {
        return AiAllianceRelation::query()->openBetween($allianceId, $otherId)->where('stance', AiAllianceStance::Nap)->whereNotNull('notice_ends_at')->exists();
    }

    public function endNotices(): array
    {
        $ended = [];
        foreach (AiAllianceRelation::query()->whereNull('ended_at')->where('stance', AiAllianceStance::Nap)->where('notice_ends_at', '<=', now())->orderBy('id')->get() as $pact) {
            $canceller = (int) $pact->cancelled_by_alliance_id;
            $other = $canceller === $pact->alliance_id ? $pact->other_alliance_id : $pact->alliance_id;
            $this->openWar($canceller, $other, 'betrayal', null);
            $ended[] = [$canceller, $other];
        }

        return $ended;
    }

    /** The module keeps no offers: an AI leader's offer is the message it sends (AdvanceAiAlliancePoliticsAction::offeredTo). */
    public function offerPact(int $allianceId, int $otherId, string $cause, int|null $byUserId): void
    {
    }

    public function pactOffersTo(int $allianceId): array
    {
        return [];
    }

    public function declinePact(int $allianceId, int $offererId): void
    {
    }

    /** Breakers are not on record here; each AI leader's mind counts what it saw. */
    public function pactsBroken(int $allianceId): int|null
    {
        return null;
    }

    private function open(int $allianceId, int $otherId, AiAllianceStance $stance, string $cause, int|null $byUserId): void
    {
        AiAllianceRelation::query()->create([
            'alliance_id' => $allianceId,
            'other_alliance_id' => $otherId,
            'stance' => $stance,
            'cause' => $cause,
            'declared_by' => $byUserId,
            'started_at' => now(),
        ]);
    }
}
