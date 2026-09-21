<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\AI\Domain\Persona\PersonaTaste;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiSocialExchange;
use Modules\AI\Support\RandomSource;

/**
 * Initiates contact the way a player does: after something happened. A received
 * transport earns a thank-you through the same authored path a reply uses. The
 * other sourced initiations SOC1 names — a report share after a raid, a greeting
 * to a prober — wait on host surfaces that do not exist yet (no report-share API,
 * no inbound-probe observation), so only the transport thank-you ships here.
 */
class InitiateAiSocialContactAction
{
    /** One session thanks at most this many senders, so a pile never reads as spam. */
    private const MAXIMUM_INITIATIONS_PER_SESSION = 5;

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

        return $sent;
    }

    /**
     * How many senders one session thanks. Sociability is the account's own: a
     * chatty account works through a whole pile, a quiet one thanks the first
     * sender and leaves the rest for a later session.
     */
    private function maximumInitiations(AiProfile $profile): int
    {
        $sociability = PersonaTaste::fromSeed((int) $profile->random_seed, app(RandomSource::class))->sociability;

        return max(1, (int) round(self::MAXIMUM_INITIATIONS_PER_SESSION * $sociability));
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
}
