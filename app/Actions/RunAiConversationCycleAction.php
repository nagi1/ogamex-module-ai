<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Modules\AI\Domain\Conversation\ContactImpactPolicy;
use Modules\AI\Domain\Conversation\ConversationRoutePolicy;
use Modules\AI\Enums\AiConversationReplyState;
use Modules\AI\Enums\AiConversationRoute;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Jobs\GenerateAiReply;
use Modules\AI\Models\AiConversationReply;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiSocialExchange;
use OGame\Models\BattleReport;
use OGame\Models\ChatMessage;

/**
 * Answers pending inbound messages with the module's authored social protocol.
 *
 * The cycle is composed by the session, which already holds the player lease and the
 * per-player lock, so a bounded conversation costs no extra work item, no extra job and no
 * extra lock contention. It makes no generative call of its own: a message is answered only
 * when the classifier places it as a known exchange, and the decision and the wording are
 * authored. A substantive exchange may hand its sealed reply to the language lane, which is
 * off by default and never dispatches a request this action would refuse.
 *
 * The protocol is bounded by turns rather than by interest, so two automated neighbours can
 * greet each other without exchanging messages forever.
 */
class RunAiConversationCycleAction
{
    /** An opening and one response turn is the whole protocol; after that the AI goes quiet. */
    private const MAXIMUM_RESPONSE_TURNS = 2;

    private const MAXIMUM_PENDING_MESSAGES = 20;

    /**
     * @return int the number of exchanges this cycle answered
     */
    public function handle(int $playerId, CarbonImmutable $now): int
    {
        // An observer normally records a committed message; this catches up on anything it
        // could not, such as messages that arrived while the module was disabled.
        app(ReconcileAiChatObservationsAction::class)->handle($playerId);

        $answered = 0;

        foreach ($this->unansweredObservations($playerId) as $observation) {
            if ($this->answer($observation, $now)) {
                $answered++;
            }
        }

        foreach ($this->unansweredAttackObservations($playerId) as $observation) {
            if ($this->answerAttacker($observation, $now)) {
                $answered++;
            }
        }

        return $answered;
    }

    /**
     * Committed attacks where this account was the defender and the attacker has not yet
     * been answered. The battle report names the defender in its planet owner, and the
     * reply is the same bounded authored path as a chat answer, once per distinct attacker
     * (a human pings the raider once, not every raid).
     *
     * @return Collection<int, AiObservation>
     */
    private function unansweredAttackObservations(int $playerId): Collection
    {
        return AiObservation::query()
            ->where('player_id', $playerId)
            ->where('kind', AiObservationKind::BattleReportObserved)
            ->whereIn('source_id', BattleReport::query()->where('planet_user_id', $playerId)->select('id'))
            ->whereNotIn('subject_player_id', AiSocialExchange::query()
                ->where('player_id', $playerId)
                ->where('type', AiSocialExchangeType::AttackerNotice)
                ->select('counterparty_player_id'))
            ->oldest('id')
            ->limit(self::MAXIMUM_PENDING_MESSAGES)
            ->get();
    }

    private function answerAttacker(AiObservation $observation, CarbonImmutable $now): bool
    {
        $playerId = (int) $observation->player_id;
        $attackerId = (int) $observation->subject_player_id;

        if ($attackerId <= 0 || $playerId === $attackerId) {
            return false;
        }

        $exchange = app(RecordAiSocialExchangeAction::class)->handle(
            $playerId,
            $attackerId,
            $observation->id,
            AiSocialExchangeType::AttackerNotice,
            [],
            null,
            1,
        );

        if ($exchange === null) {
            return false;
        }

        // A notice has no inbound message to reply to, so it skips the
        // chat-sourced queue/seal/deliver pipeline and sends the authored line
        // straight through the direct path the sealed reply would have used.
        $evaluated = app(EvaluateAiSocialExchangeAction::class)->handle($exchange->id, 0, $now);
        if ($evaluated === null) {
            return false;
        }

        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return false;
        }

        $message = app(BuildAuthoredSocialReplyAction::class)->handle($evaluated, $profile);
        if ($message === null) {
            return false;
        }

        app(DeliverAiDirectReplyAction::class)->handle($playerId, $attackerId, $message);

        return true;
    }

    /**
     * An exchange is the marker that an observation has been dealt with, so a message with
     * no exchange is retried and one that already produced an exchange is never reconsidered.
     *
     * @return Collection<int, AiObservation>
     */
    private function unansweredObservations(int $playerId): Collection
    {
        return AiObservation::query()
            ->where('player_id', $playerId)
            ->where('kind', AiObservationKind::DirectChatMessageReceived)
            ->where('source_type', AiObservationSource::ChatMessage)
            ->whereNotIn('id', AiSocialExchange::query()->whereNotNull('source_observation_id')->select('source_observation_id'))
            ->oldest('id')
            ->limit(self::MAXIMUM_PENDING_MESSAGES)
            ->get();
    }

    private function answer(AiObservation $observation, CarbonImmutable $now): bool
    {
        $message = ChatMessage::query()->find($observation->source_id);

        if ($message === null) {
            return false;
        }

        // An unrecognised message is not an exchange, so there is nothing to record, nothing
        // to answer and nothing to remember beyond the message already received.
        $classification = app(ClassifyInboundSocialExchangeAction::class)->handle($message->message);

        if ($classification === null) {
            return false;
        }

        $playerId = (int) $observation->player_id;
        $counterpartyPlayerId = (int) $observation->subject_player_id;
        $turns = $this->answeredTurns($playerId, $counterpartyPlayerId);

        if ($turns >= self::MAXIMUM_RESPONSE_TURNS) {
            return false;
        }

        $exchange = app(RecordAiSocialExchangeAction::class)->handle(
            $playerId,
            $counterpartyPlayerId,
            $observation->id,
            $classification->type,
            $classification->terms,
            null,
            $turns + 1,
        );

        if ($exchange === null) {
            return false;
        }

        $this->recordContact($observation, $classification->type, $classification->terms);
        $this->respond($exchange, $now);

        return true;
    }

    /**
     * A reply that was composed and sealed counts as this AI's turn even while its delivery
     * is still pending. An attempt that expired counts as no turn at all, because the AI
     * never actually spoke to anyone.
     */
    private function answeredTurns(int $playerId, int $counterpartyPlayerId): int
    {
        return AiConversationReply::query()
            ->where('player_id', $playerId)
            ->where('counterparty_player_id', $counterpartyPlayerId)
            ->whereIn('state', [AiConversationReplyState::Sealed, AiConversationReplyState::Delivered])
            ->count();
    }

    /**
     * @param array<string, mixed> $terms
     */
    private function recordContact(AiObservation $observation, AiSocialExchangeType $type, array $terms): void
    {
        $impact = app(ContactImpactPolicy::class)->impactFor($type, $terms);

        app(RecordAiRelationshipInteractionAction::class)->handle(
            (int) $observation->player_id,
            (int) $observation->subject_player_id,
            (int) $observation->id,
            CarbonImmutable::instance($observation->source_time),
            $impact->trust,
            $impact->threat,
            $impact->affinity,
            $impact->respect,
            $impact->socialImportance,
        );
    }

    /**
     * Evaluation, sealing and delivery are composed here without a failure branch of their
     * own: each step already refuses safely, and a reply that was composed but never
     * delivered is visible as its own terminal state rather than as a silent counter.
     */
    private function respond(AiSocialExchange $exchange, CarbonImmutable $now): void
    {
        // No transfer capability is wired, so the amount this AI can actually hand over is
        // nothing. Passing zero is what keeps a decline honest instead of promising
        // resources the module could not deliver.
        app(EvaluateAiSocialExchangeAction::class)->handle($exchange->id, 0, $now);

        $reply = app(QueueAiSocialExchangeReplyAction::class)->handle($exchange->id, $this->replyExpiresAt($now));
        $sealed = app(SealAiAuthoredReplyAction::class)->handle($reply->id ?? 0);

        if ($sealed === null) {
            return;
        }

        $this->deliver($exchange, $sealed);
    }

    /**
     * An authored send costs a database write, which the session can afford while it already
     * holds this player's lock. A realization costs a network call, so it belongs on the
     * language lane where a slow provider delays one reply instead of the session, and where
     * the sealed authored text remains the fallback.
     */
    private function deliver(AiSocialExchange $exchange, AiConversationReply $sealed): void
    {
        if (!$this->mayReachProvider($exchange)) {
            app(DeliverAiSealedReplyAction::class)->handle($sealed->id);

            return;
        }

        GenerateAiReply::dispatch($sealed->id);
    }

    private function mayReachProvider(AiSocialExchange $exchange): bool
    {
        if (!(bool) config('ai.language.enabled', false)) {
            return false;
        }

        return app(ConversationRoutePolicy::class)->routeFor($exchange->type) === AiConversationRoute::Realization;
    }

    /**
     * Observed human behaviour in this game is an answer within minutes to a day, so this
     * bound only stops a stale reply arriving long after the conversation moved on.
     */
    private function replyExpiresAt(CarbonImmutable $now): CarbonImmutable
    {
        return $now->addMinutes(max(1, (int) config('ai.cognition.conversation.reply_ttl_minutes', 180)));
    }
}
