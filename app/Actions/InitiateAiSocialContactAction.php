<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\AI\Domain\Decision\Enemies;
use Modules\AI\Domain\Persona\PersonaTaste;
use Modules\AI\Enums\AiCommitmentState;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Models\AiCommitment;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Models\AiSocialExchange;
use Modules\AI\Support\RandomSource;
use OGame\Models\Alliance;
use OGame\Models\User;

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

    /** A warned raider that keeps raiding is pleaded with this long after the warning. */
    private const PLEA_AFTER_HOURS = 6;

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

        foreach ($this->brokenTruces($playerId) as $truce) {
            if ($sent >= $maximum) {
                break;
            }

            if ($this->reproach($truce, $profile)) {
                $sent++;
            }
        }

        foreach ($this->unpleadedRaiders($playerId, $now) as $observation) {
            if ($sent >= $maximum) {
                break;
            }

            if ($this->open($observation, $profile, 'ceasefire_plea', AiSocialExchangeType::CeasefireRequest)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * A raider who kept hitting after the warning: the account asks for a truce once, a quarter day after it warned, when
     * a raid newer than the warning has landed. Players warn, then plead, before they give up the planet or hit back; the
     * raider's own cognition answers the plea (a grudge at the war line refuses it).
     *
     * @return Collection<int, AiObservation>
     */
    private function unpleadedRaiders(int $playerId, CarbonImmutable $now): Collection
    {
        $warned = AiSocialExchange::query()->where('player_id', $playerId)->where('type', AiSocialExchangeType::Warning)
            ->where('created_at', '<=', $now->subHours(self::PLEA_AFTER_HOURS))->get(['counterparty_player_id', 'created_at'])
            ->mapWithKeys(static fn (AiSocialExchange $exchange): array => [(int) $exchange->counterparty_player_id => $exchange->created_at]);
        $pleaded = AiSocialExchange::query()->where('player_id', $playerId)->where('type', AiSocialExchangeType::CeasefireRequest)->pluck('counterparty_player_id');

        return AiObservation::query()
            ->where('player_id', $playerId)
            ->where('kind', AiObservationKind::BattleReportObserved)
            ->whereIn('subject_player_id', $warned->keys()->all())
            ->whereNotIn('subject_player_id', $pleaded)
            ->oldest('id')
            ->get()
            ->filter(static fn (AiObservation $observation): bool => $observation->source_time->greaterThan($warned[(int) $observation->subject_player_id]))
            ->unique('subject_player_id');
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

    /**
     * Ceasefires the other side broke by hitting inside the window, not yet answered: a player whose truce was broken
     * says so, once, before anything else.
     *
     * @return Collection<int, AiCommitment>
     */
    private function brokenTruces(int $playerId): Collection
    {
        $reproached = AiSocialExchange::query()->where('player_id', $playerId)->where('type', AiSocialExchangeType::Warning)->pluck('source_observation_id');

        return AiCommitment::query()
            ->where('state', AiCommitmentState::Broken)
            ->whereIn('id', AiSocialExchange::query()->where('type', AiSocialExchangeType::CeasefireRequest)->whereNotNull('commitment_id')->select('commitment_id'))
            ->where(static fn ($side) => $side->where('player_id', $playerId)->orWhere('counterparty_player_id', $playerId))
            ->whereNotIn('source_observation_id', $reproached)
            ->get()
            ->filter(static fn (AiCommitment $truce): bool => (int) ($truce->terms['broken_by'] ?? 0) > 0 && (int) $truce->terms['broken_by'] !== $playerId);
    }

    private function reproach(AiCommitment $truce, AiProfile $profile): bool
    {
        $breakerId = (int) $truce->terms['broken_by'];
        $message = app(BuildAuthoredSocialReplyAction::class)->line($profile, 'ceasefire_broken', (string) $breakerId);
        $exchange = $message === null ? null : app(RecordAiSocialExchangeAction::class)->handle($profile->player_id, $breakerId, $truce->source_observation_id, AiSocialExchangeType::Warning, [], null, 1);

        if ($exchange === null) {
            return false;
        }

        app(DeliverAiDirectReplyAction::class)->handle($profile->player_id, $breakerId, $message);

        return true;
    }

    private function warn(AiObservation $observation, AiProfile $profile): bool
    {
        return $this->open($observation, $profile, 'raid_warning', AiSocialExchangeType::Warning);
    }

    /** One opening line to a raider, recorded as sent: the raider's own turn answers it, through its own cognition. */
    private function open(AiObservation $observation, AiProfile $profile, string $key, AiSocialExchangeType $type): bool
    {
        $raiderId = (int) $observation->subject_player_id;
        $lines = app(BuildAuthoredSocialReplyAction::class);
        $message = $lines->line($profile, $key, (string) $raiderId);

        if ($raiderId <= 0 || $raiderId === $profile->player_id || $message === null) {
            return false;
        }

        // A player names what was done to them ("that's 6 attacks"); a bare "stop" repeated to everyone reads as a script.
        $hits = AiObservation::query()->where('player_id', $profile->player_id)->where('kind', AiObservationKind::BattleReportObserved)->where('subject_player_id', $raiderId)->count();
        $detail = $hits > 1 ? $lines->line($profile, $key . '.detail', (string) $raiderId, ['hits' => (string) $hits]) : null;
        $message = $detail === null ? $message : $message . ' ' . $detail;

        $exchange = app(RecordAiSocialExchangeAction::class)->handle($profile->player_id, $raiderId, $observation->id, $type, [], null, 1);

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
        $allianceId = $joinerId > 0 ? User::query()->whereKey($joinerId)->value('alliance_id') : null;

        if ($joinerId === $profile->player_id || $allianceId === null || $this->alreadyWelcomed($observation, $joinerId)) {
            return false;
        }

        // One member welcomes a newcomer, in the alliance channel where the club can see it, not eight DMs to the joiner.
        $line = app(BuildAuthoredSocialReplyAction::class)->line($profile, 'member_welcome', (string) $observation->id, [
            'name' => (string) User::query()->whereKey($joinerId)->value('username'),
            'tag' => (string) Alliance::query()->whereKey($allianceId)->value('alliance_tag'),
        ]);
        $exchange = $line === null ? null : app(RecordAiSocialExchangeAction::class)->handle($profile->player_id, $joinerId, $observation->id, AiSocialExchangeType::Greeting, [], null, 1);

        return $exchange !== null && app(DeliverAiAllianceReplyAction::class)->handle($profile->player_id, (int) $allianceId, $line) !== null;
    }

    /** Another member already greeted this join: the same membership row is observed by every co-member. */
    private function alreadyWelcomed(AiObservation $observation, int $joinerId): bool
    {
        return AiSocialExchange::query()->where('type', AiSocialExchangeType::Greeting)->where('counterparty_player_id', $joinerId)
            ->whereIn('source_observation_id', AiObservation::query()->where('kind', AiObservationKind::AllianceMembershipJoined)
                ->where('source_type', $observation->source_type)->where('source_id', $observation->source_id)->select('id'))->exists();
    }
}
