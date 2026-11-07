<?php

namespace Modules\AI\Contracts;

use Carbon\CarbonInterface;
use Modules\AI\Enums\AiAllianceStance;

/**
 * Where the wars and pacts between alliances are kept (alliance-politics.md). The module's own rows are the default;
 * an installed diplomacy module (Empire) takes over, so AI and human leaders read and write one record under one rule set.
 */
interface AllianceTreaties
{
    /** Whether the two alliances hold this stance toward each other, whichever side declared it. */
    public function holds(int $allianceId, int $otherId, AiAllianceStance $stance): bool;

    /** Whether the two alliances hold any stance toward each other. */
    public function related(int $allianceId, int $otherId): bool;

    /** @return list<int> the alliances this one holds the stance toward */
    public function counterparts(int $allianceId, AiAllianceStance $stance): array;

    /** @return list<array{0: int, 1: int}> declarer and other side of each open war started by then, other than of $exceptCause */
    public function warsStartedBy(CarbonInterface $startedBy, string $exceptCause): array;

    /** Opens a war; a pact between the two ends with it, broken by the declarer. */
    public function openWar(int $declarerId, int $otherId, string $cause, int|null $byUserId): void;

    public function endWar(int $allianceId, int $otherId): void;

    /** Records a pact both leaders agreed to. */
    public function openPact(int $allianceId, int $otherId, string $cause, int|null $byUserId): void;

    /** Whether there was a pact for $breakerId to break: broken without notice, a betrayal on the breaker's record. */
    public function breakPact(int $breakerId, int $otherId, int|null $byUserId): bool;

    /** Whether there was a pact, not yet under notice, for $cancellerId to cancel: it binds until $noticeEnds. */
    public function cancelPact(int $cancellerId, int $otherId, CarbonInterface $noticeEnds): bool;

    /** Whether the pact between the two is under notice. */
    public function inNotice(int $allianceId, int $otherId): bool;

    /**
     * Ends each pact whose notice has run out: it closes and the side that cancelled is at war with the other.
     *
     * @return list<array{0: int, 1: int}> the side that cancelled and the other side of each
     */
    public function endNotices(): array;

    /** Leaves a pact offer standing for the other side's leader to accept or decline; a record without offers keeps none. */
    public function offerPact(int $allianceId, int $otherId, string $cause, int|null $byUserId): void;

    /** @return list<int> the alliances with a pact offer standing to this one */
    public function pactOffersTo(int $allianceId): array;

    /** Turns down a pact offer standing from $offererId. */
    public function declinePact(int $allianceId, int $offererId): void;

    /** Pacts this alliance broke, as every player reads it; null when the record keeps no public count. */
    public function pactsBroken(int $allianceId): int|null;
}
