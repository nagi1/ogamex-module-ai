<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Conversation\LanguageProposal;
use Modules\AI\Enums\AiCommitmentDirection;
use Modules\AI\Enums\AiLanguageProposalRejectionReason;
use Modules\AI\Enums\AiLanguageProposalState;
use Modules\AI\Enums\AiLanguageProposalType;
use Modules\AI\Enums\AiMemoryEvidenceKind;
use Modules\AI\Enums\AiMemoryPredicate;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSocialTerm;
use Modules\AI\Models\AiConversationReply;
use Modules\AI\Models\AiLanguageProposal;
use Modules\AI\Models\AiLanguageRequest;
use Modules\AI\Models\AiObservation;
use OGame\Models\ChatMessage;

class RecordAiLanguageProposalAction
{
    public function handle(AiLanguageRequest $request, AiConversationReply $reply, LanguageProposal $proposal): AiLanguageProposal
    {
        $rejectionReason = $this->rejectionReason($reply, $proposal);

        if ($rejectionReason !== null) {
            return $this->record($request, $proposal, AiLanguageProposalState::Rejected, $rejectionReason);
        }

        $observation = AiObservation::query()
            ->where('player_id', $reply->player_id)
            ->where('source_type', AiObservationSource::ChatMessage)
            ->where('source_id', $proposal->sourceMessageId)
            ->first();

        if ($observation === null) {
            return $this->record($request, $proposal, AiLanguageProposalState::Rejected, AiLanguageProposalRejectionReason::SourceNotObserved);
        }

        $this->persistProposalMeaning($reply, $proposal, $observation);

        return $this->record($request, $proposal, AiLanguageProposalState::Accepted);
    }

    private function rejectionReason(AiConversationReply $reply, LanguageProposal $proposal): AiLanguageProposalRejectionReason|null
    {
        if ($proposal->sourceMessageId < $reply->source_first_message_id || $proposal->sourceMessageId > $reply->source_last_message_id) {
            return AiLanguageProposalRejectionReason::SourceNotAuthorized;
        }

        $message = ChatMessage::query()
            ->whereKey($proposal->sourceMessageId)
            ->where('sender_id', $reply->counterparty_player_id)
            ->where('recipient_id', $reply->player_id)
            ->whereNull('alliance_id')
            ->first();

        if ($message === null) {
            return AiLanguageProposalRejectionReason::SourceNotAuthorized;
        }

        if (!$this->termsAreExplicit($message->message, $proposal)) {
            return AiLanguageProposalRejectionReason::TermsNotExplicit;
        }

        return null;
    }

    private function termsAreExplicit(string $message, LanguageProposal $proposal): bool
    {
        if ($proposal->resource === null || $proposal->amount === null) {
            return false;
        }

        if (!str_contains(mb_strtolower($message), $proposal->resource->value)) {
            return false;
        }

        if (preg_match('/(?<!\\d)' . preg_quote((string) $proposal->amount, '/') . '(?!\\d)/', $message) !== 1) {
            return false;
        }

        if ($proposal->type === AiLanguageProposalType::Claim) {
            return $proposal->dueAt === null;
        }

        return $proposal->dueAt !== null && str_contains($message, $proposal->dueAt->toIso8601String());
    }

    private function persistProposalMeaning(AiConversationReply $reply, LanguageProposal $proposal, AiObservation $observation): void
    {
        if ($proposal->type === AiLanguageProposalType::Claim) {
            app(RecordAiMemoryFactAction::class)->handle(
                $reply->player_id,
                $reply->counterparty_player_id,
                AiMemoryPredicate::ResourceDebt,
                AiMemoryEvidenceKind::Claimed,
                $this->terms($proposal),
                $observation->id,
                CarbonImmutable::instance($observation->observed_at),
                speakerPlayerId: $reply->counterparty_player_id,
            );

            return;
        }

        app(RecordAiCommitmentAction::class)->handle(
            $reply->player_id,
            $reply->counterparty_player_id,
            $this->terms($proposal),
            $observation->id,
            $proposal->dueAt,
            AiCommitmentDirection::ExpectedFromCounterparty,
        );
    }

    /** @return array<string, string|int> */
    private function terms(LanguageProposal $proposal): array
    {
        if ($proposal->resource === null || $proposal->amount === null) {
            return [];
        }

        return match ($proposal->type) {
            AiLanguageProposalType::Claim => [
                AiSocialTerm::Resource->value => $proposal->resource->value,
                AiSocialTerm::Amount->value => $proposal->amount,
            ],
            AiLanguageProposalType::Commitment => [
                AiSocialTerm::OfferedResource->value => $proposal->resource->value,
                AiSocialTerm::OfferedAmount->value => $proposal->amount,
            ],
        };
    }

    private function record(
        AiLanguageRequest $request,
        LanguageProposal $proposal,
        AiLanguageProposalState $state,
        AiLanguageProposalRejectionReason|null $rejectionReason = null,
    ): AiLanguageProposal {
        return AiLanguageProposal::query()->firstOrCreate([
            'language_request_id' => $request->id,
            'source_message_id' => $proposal->sourceMessageId,
            'type' => $proposal->type,
        ], [
            'state' => $state,
            'rejection_reason' => $rejectionReason,
            'terms' => $this->terms($proposal),
        ]);
    }
}
