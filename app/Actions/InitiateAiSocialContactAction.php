<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\AI\Domain\Decision\Enemies;
use Modules\AI\Domain\Persona\PersonaTaste;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Models\AiSocialExchange;
use Modules\AI\Support\RandomSource;

/**
 * Initiates contact the way a player does: after something happened. A received
 * transport earns a thank-you, and a newly-joined alliance member earns a
 * welcome, both through the same authored path a reply uses. The other sourced
 * initiations SOC1 names — a report share after a raid, a greeting to a prober —
 * wait on host surfaces that do not exist yet (no report-share API, no
 * inbound-probe observation).
 */
class InitiateAiSocialContactAction
{
    /** One session thanks at most this many senders, so a pile never reads as spam. */
    private const MAXIMUM_INITIATIONS_PER_SESSION = 5;

    /** A member this sociable or more greets a newcomer; below it the account stays quiet. */
    private const WELCOME_SOCIABILITY_FLOOR = 0.5;

    public function handle(int $playerId, CarbonImmutable $now): int
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();

        if ($profile === null) {
            return 0;
        }

        $sent = 0;
        $maximum = $this->maximumInitiations($profile);

        foreach ($this->unthankedTransfers($playerId) as $observation) {
            if ($sent >= $maximum) {
                break;
            }

            if ($this->thank($observation, $profile, $now)) {
                $sent++;
            }
        }

        foreach ($this->unwelcomedJoins($playerId) as $observation) {
            if ($sent >= $maximum) {
                break;
            }

            if ($this->welcome($observation, $profile, $now)) {
                $sent++;
            }
        }

        foreach ($this->unwarnedRaiders($playerId) as $observation) {
            if ($sent >= $maximum) {
                break;
            }

            if ($this->warn($observation, $profile)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * The raids of players this account holds at the war line that it has not yet told to stop, one per raider: a player
     * whom the same account keeps hitting writes once, "stop attacking me", before anything else (2006 board: hostilities
     * began with a message, not silence). The line is the only thing a reply to it can be wrong about.
     *
     * @return Collection<int, AiObservation>
     */
    private function unwarnedRaiders(int $playerId): Collection
    {
        return AiObservation::query()
            ->where('player_id', $playerId)
            ->where('kind', AiObservationKind::BattleReportObserved)
            ->whereIn('subject_player_id', AiRelationship::query()->where('player_id', $playerId)->where('threat', '>=', Enemies::ENEMY_THREAT)->select('other_player_id'))
            ->whereNotIn('subject_player_id', AiSocialExchange::query()->where('player_id', $playerId)->where('type', AiSocialExchangeType::Warning)->select('counterparty_player_id'))
            ->oldest('id')
            ->get()
            ->unique('subject_player_id');
    }

    private function warn(AiObservation $observation, AiProfile $profile): bool
    {
        $raiderId = (int) $observation->subject_player_id;
        $message = app(BuildAuthoredSocialReplyAction::class)->line($profile, 'raid_warning', (string) $raiderId);

        if ($raiderId <= 0 || $raiderId === $profile->player_id || $message === null) {
            return false;
        }

        // Recorded as sent, not evaluated: the raider's own turn answers it, through its own cognition.
        $exchange = app(RecordAiSocialExchangeAction::class)->handle($profile->player_id, $raiderId, $observation->id, AiSocialExchangeType::Warning, [], null, 1);

        if ($exchange === null) {
            return false;
        }

        app(DeliverAiDirectReplyAction::class)->handle($profile->player_id, $raiderId, $message);

        return true;
    }

    /**
     * How many senders one session thanks. Sociability is the account's own: a
     * chatty account works through a whole pile, a quiet one thanks the first
     * sender and leaves the rest for a later session.
     */
    private function maximumInitiations(AiProfile $profile): int
    {
        return max(1, (int) round(self::MAXIMUM_INITIATIONS_PER_SESSION * $this->sociability($profile)));
    }

    private function sociability(AiProfile $profile): float
    {
        return PersonaTaste::fromSeed((int) $profile->random_seed, app(RandomSource::class))->sociability;
    }

    /**
     * A received transport no exchange has dealt with yet: the same "an exchange
     * is the marker an observation was handled" rule the reply path uses.
     *
     * @return Collection<int, AiObservation>
     */
    private function unthankedTransfers(int $playerId): Collection
    {
        return AiObservation::query()
            ->where('player_id', $playerId)
            ->where('kind', AiObservationKind::TransferReceived)
            ->whereNotIn('id', AiSocialExchange::query()->whereNotNull('source_observation_id')->select('source_observation_id'))
            ->oldest('id')
            ->get();
    }

    private function thank(AiObservation $observation, AiProfile $profile, CarbonImmutable $now): bool
    {
        $senderId = (int) $observation->subject_player_id;

        if ($senderId <= 0 || $senderId === $profile->player_id) {
            return false;
        }

        $exchange = app(RecordAiSocialExchangeAction::class)->handle(
            $profile->player_id,
            $senderId,
            $observation->id,
            AiSocialExchangeType::TransportThanks,
            [],
            null,
            1,
        );

        if ($exchange === null) {
            return false;
        }

        $evaluated = app(EvaluateAiSocialExchangeAction::class)->handle($exchange->id, 0, $now);

        if ($evaluated === null) {
            return false;
        }

        $message = app(BuildAuthoredSocialReplyAction::class)->handle($evaluated, $profile);

        if ($message === null) {
            return false;
        }

        app(DeliverAiDirectReplyAction::class)->handle($profile->player_id, $senderId, $message);

        return true;
    }

    /**
     * A membership start no exchange has dealt with yet, i.e. a joiner this
     * account has not greeted. The observation is written for every co-member,
     * so the same join becomes a hello from the chatty members and silence from
     * the quiet ones.
     *
     * @return Collection<int, AiObservation>
     */
    private function unwelcomedJoins(int $playerId): Collection
    {
        return AiObservation::query()
            ->where('player_id', $playerId)
            ->where('kind', AiObservationKind::AllianceMembershipJoined)
            ->whereNotIn('id', AiSocialExchange::query()->whereNotNull('source_observation_id')->select('source_observation_id'))
            ->oldest('id')
            ->get();
    }

    /**
     * Greets a newcomer through the authored pipeline. Sociability is the
     * account's own: a chatty member says hello, a quiet one leaves the welcome
     * to someone else, so the social surface diverges by taste rather than firing
     * in unison.
     */
    private function welcome(AiObservation $observation, AiProfile $profile, CarbonImmutable $now): bool
    {
        if ($this->sociability($profile) < self::WELCOME_SOCIABILITY_FLOOR) {
            return false;
        }

        $joinerId = (int) $observation->subject_player_id;

        if ($joinerId <= 0 || $joinerId === $profile->player_id) {
            return false;
        }

        $exchange = app(RecordAiSocialExchangeAction::class)->handle(
            $profile->player_id,
            $joinerId,
            $observation->id,
            AiSocialExchangeType::Greeting,
            [],
            null,
            1,
        );

        if ($exchange === null) {
            return false;
        }

        $evaluated = app(EvaluateAiSocialExchangeAction::class)->handle($exchange->id, 0, $now);

        if ($evaluated === null) {
            return false;
        }

        $message = app(BuildAuthoredSocialReplyAction::class)->handle($evaluated, $profile);

        if ($message === null) {
            return false;
        }

        app(DeliverAiDirectReplyAction::class)->handle($profile->player_id, $joinerId, $message);

        return true;
    }
}
