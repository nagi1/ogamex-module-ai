<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Modules\AI\Domain\Conversation\ContactImpactPolicy;
use Modules\AI\Enums\AiConversationReplyState;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Models\AiConversationReply;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiSocialExchange;
use OGame\Models\ChatMessage;

/**
 * Answers pending inbound messages with the module's authored social protocol.
 *
 * The cycle is composed by the session, which already holds the player lease and the
 * per-player lock, so a bounded conversation costs no extra work item, no extra job and no
 * extra lock contention. It makes no generative call: a message is answered only when the
 * classifier places it as a known exchange, and the decision, the wording and the delivery
 * are all authored.
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

        return $answered;
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
        $sealed = app(SealAiAuthoredReplyAction::class)->handle($reply?->id ?? 0);

        app(DeliverAiSealedReplyAction::class)->handle($sealed?->id ?? 0);
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
