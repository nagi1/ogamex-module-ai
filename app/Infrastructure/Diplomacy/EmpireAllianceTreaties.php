<?php

namespace Modules\AI\Infrastructure\Diplomacy;

use Carbon\CarbonInterface;
use Modules\AI\Contracts\AllianceTreaties;
use Modules\AI\Enums\AiAllianceStance;
use Modules\Empire\Enums\TreatyStance;
use Modules\Empire\Models\AllianceTreaty;
use Modules\Empire\Services\DiplomacyService;

/**
 * The Empire module's diplomacy board as the record: an AI leader acts through DiplomacyService as its alliance's
 * leader, so its wars and pacts show on the alliance page and the treaties human leaders sign there bind it too.
 */
class EmpireAllianceTreaties implements AllianceTreaties
{
    public function __construct(private readonly DiplomacyService $diplomacy)
    {
    }

    public function holds(int $allianceId, int $otherId, AiAllianceStance $stance): bool
    {
        return $this->diplomacy->stance($allianceId, $otherId) === $this->treatyStance($stance);
    }

    public function related(int $allianceId, int $otherId): bool
    {
        return $this->diplomacy->stance($allianceId, $otherId) !== null;
    }

    public function counterparts(int $allianceId, AiAllianceStance $stance): array
    {
        // A pact offer nobody accepted yet is no pact.
        return AllianceTreaty::query()->openFor($allianceId)->where('stance', $this->treatyStance($stance))
            ->when($stance === AiAllianceStance::Nap, static fn ($query) => $query->whereNotNull('accepted_at'))
            ->get(['alliance_id', 'other_alliance_id'])
            ->map(static fn (AllianceTreaty $treaty): int => (int) $treaty->counterpart($allianceId))->unique()->values()->all();
    }

    public function warsStartedBy(CarbonInterface $startedBy, string $exceptCause): array
    {
        return AllianceTreaty::query()->whereNull('ended_at')->where('stance', TreatyStance::War)->where('cause', '!=', $exceptCause)
            ->where('started_at', '<=', $startedBy)->orderBy('id')->get(['alliance_id', 'other_alliance_id'])
            ->map(static fn (AllianceTreaty $war): array => [(int) $war->alliance_id, (int) $war->other_alliance_id])->values()->all();
    }

    public function openWar(int $declarerId, int $otherId, string $cause, int|null $byUserId): void
    {
        if ($this->diplomacy->inPact($declarerId, $otherId)) {
            $this->diplomacy->breakPact($declarerId, $otherId, $byUserId);

            return;
        }

        $this->diplomacy->declareWar($declarerId, $otherId, $byUserId, $cause);
    }

    public function endWar(int $allianceId, int $otherId): void
    {
        $this->diplomacy->endWar($allianceId, $otherId);
    }

    /** Both leaders agreed: the offer is made and taken at once, or a standing offer from either side is taken. */
    public function openPact(int $allianceId, int $otherId, string $cause, int|null $byUserId): void
    {
        $this->diplomacy->offerPact($allianceId, $otherId, $byUserId, $cause);
        if ($this->diplomacy->acceptPact($otherId, $allianceId) !== null) {
            $this->diplomacy->acceptPact($allianceId, $otherId);
        }
    }

    public function breakPact(int $breakerId, int $otherId, int|null $byUserId): bool
    {
        return $this->diplomacy->breakPact($breakerId, $otherId, $byUserId) === null;
    }

    public function cancelPact(int $cancellerId, int $otherId, CarbonInterface $noticeEnds): bool
    {
        return $this->diplomacy->cancelPact($cancellerId, $otherId, null, $noticeEnds) === null;
    }

    public function inNotice(int $allianceId, int $otherId): bool
    {
        return $this->diplomacy->inNotice($allianceId, $otherId);
    }

    public function endNotices(): array
    {
        return $this->diplomacy->endNotices();
    }

    public function offerPact(int $allianceId, int $otherId, string $cause, int|null $byUserId): void
    {
        $this->diplomacy->offerPact($allianceId, $otherId, $byUserId, $cause);
    }

    public function pactOffersTo(int $allianceId): array
    {
        return array_values(AllianceTreaty::query()->whereNull('ended_at')->whereNull('accepted_at')->where('stance', TreatyStance::Pact)
            ->where('other_alliance_id', $allianceId)->orderBy('id')->pluck('alliance_id')->map(static fn ($id): int => (int) $id)->all());
    }

    public function declinePact(int $allianceId, int $offererId): void
    {
        $this->diplomacy->declinePact($allianceId, $offererId);
    }

    public function pactsBroken(int $allianceId): int|null
    {
        return $this->diplomacy->pactsBroken($allianceId);
    }

    private function treatyStance(AiAllianceStance $stance): TreatyStance
    {
        return match ($stance) {
            AiAllianceStance::War => TreatyStance::War,
            AiAllianceStance::Nap => TreatyStance::Pact,
        };
    }
}
