<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Enums\AiCommitmentDirection;
use Modules\AI\Enums\AiCommitmentState;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSocialResource;
use Modules\AI\Enums\AiSocialTerm;
use Modules\AI\Models\AiCommitment;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;
use OGame\Models\Message;
use OGame\Models\Planet;

/**
 * Reduces one committed cross-player transport into a transfer-arrival observation and,
 * when the sender owed the AI a promised compensation, fulfils that commitment.
 *
 * The transport_received message is the legal visibility boundary: it is the same row the
 * recipient can already read in game, and the origin planet names the sender. This is the
 * production caller DEF-012 named: a raider's compensation is observed to arrive, so the
 * accepted promise is fulfilled and the relationship is repaired through the ordinary path.
 */
class RecordObservedTransferAction
{
    public function __construct(private readonly AiClock $clock)
    {
    }

    public function handle(int $messageId): AiObservation|null
    {
        /** @var Message|null $message */
        $message = Message::query()->find($messageId);

        if ($message === null || $message->key !== 'transport_received') {
            return null;
        }

        $recipientId = (int) $message->user_id;

        if (!AiProfile::query()->where('player_id', $recipientId)->where('enabled', true)->exists()) {
            return null;
        }

        $senderId = $this->senderId($message->params['from'] ?? null);

        if ($senderId === null || $senderId <= 0 || $senderId === $recipientId) {
            return null;
        }

        // The unique source identity makes retrying an after-commit callback safe.
        $observation = AiObservation::firstOrCreateAtomically([
            'player_id' => $recipientId,
            'source_type' => AiObservationSource::FleetMessage,
            'source_id' => $message->id,
        ], [
            'kind' => AiObservationKind::TransferReceived,
            'subject_player_id' => $senderId,
            'source_time' => $message->created_at ?? $this->clock->now(),
            'observed_at' => $this->clock->now(),
        ]);

        if (!$observation->wasRecentlyCreated) {
            return $observation;
        }

        $this->fulfillCoveredCommitment($recipientId, $senderId, $message, $observation);

        return $observation;
    }

    /**
     * The origin planet id is the only sender identity the transport_received message carries;
     * the planet tag survives a deleted body as a bare coordinate, which cannot name a sender.
     */
    private function senderId(mixed $fromTag): int|null
    {
        if (!is_string($fromTag) || preg_match('/^\[planet\](\d+)\[\/planet\]$/', $fromTag, $matches) !== 1) {
            return null;
        }

        return Planet::query()->whereKey((int) $matches[1])->value('user_id');
    }

    /**
     * One delivery settles one promise: the oldest outstanding accepted promise from the sender
     * whose full amount this shipment actually covers. A partial delivery settles nothing, so a
     * raider cannot buy trust back with a token shipment below what was promised.
     */
    private function fulfillCoveredCommitment(int $recipientId, int $senderId, Message $message, AiObservation $observation): void
    {
        $commitment = AiCommitment::query()
            ->where('player_id', $recipientId)
            ->where('counterparty_player_id', $senderId)
            ->where('direction', AiCommitmentDirection::ExpectedFromCounterparty)
            ->where('state', AiCommitmentState::Accepted)
            ->oldest('due_at')
            ->oldest('id')
            ->first();

        if ($commitment === null || !$this->covers($commitment, $message->params)) {
            return;
        }

        app(FulfillAiCommitmentAction::class)->handle(
            $commitment->id,
            $observation->id,
            CarbonImmutable::instance($observation->observed_at),
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function covers(AiCommitment $commitment, array $params): bool
    {
        $terms = $commitment->terms;
        $resource = $terms[AiSocialTerm::Resource->value] ?? null;
        $amount = $terms[AiSocialTerm::Amount->value] ?? null;

        // ponytail: a commitment created by the language lane uses OfferedResource/OfferedAmount
        // keys instead, so it is not auto-fulfilled here; the ordinary compensation path uses
        // Resource/Amount. Align the language lane's term mapping before expecting both to settle.
        if (!is_string($resource) || AiSocialResource::tryFrom($resource) === null) {
            return false;
        }

        if (!is_int($amount) && !is_float($amount)) {
            return false;
        }

        return (float) ($params[$resource] ?? 0) >= (float) $amount;
    }
}
