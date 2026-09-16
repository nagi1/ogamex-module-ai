<?php

namespace Modules\AI\Infrastructure\Language;

use Carbon\CarbonImmutable;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Modules\AI\Ai\Agents\OgameConversationReplyAgent;
use Modules\AI\Contracts\LanguageGateway;
use Modules\AI\Domain\Conversation\LanguageProposal;
use Modules\AI\Domain\Conversation\LanguageRequest;
use Modules\AI\Domain\Conversation\LanguageResult;
use Modules\AI\Enums\AiLanguageInterpretation;
use Modules\AI\Enums\AiLanguageProposalType;
use Modules\AI\Enums\AiLanguageResultStatus;
use Modules\AI\Enums\AiSocialResource;
use RuntimeException;
use Throwable;

class LaravelAiLanguageGateway implements LanguageGateway
{
    public function generateConversationReply(LanguageRequest $request): LanguageResult
    {
        if ($request->ladder->isEmpty()) {
            // No credential is a configuration state rather than a provider fault, so refuse before
            // spending a round trip and let the caller deliver the authored text it already holds.
            return $this->failedResult(new RuntimeException('No provider rung is available for this language request.'), $request);
        }

        try {
            // The ladder is the SDK's own ordered provider list, so its failover is what walks it.
            $response = app()->makeWith(OgameConversationReplyAgent::class, ['request' => $request])
                ->prompt($request->context->serialized, provider: $request->ladder->toProviderMap(), timeout: $request->timeoutSeconds);
        } catch (Throwable $exception) {
            return $this->failedResult($exception, $request);
        }

        return $this->structuredResult($response, $request);
    }

    private function structuredResult(AgentResponse $response, LanguageRequest $request): LanguageResult
    {
        // The SDK builds a StructuredAgentResponse only when the provider answered with a
        // structured envelope; a plain text reply breaks the lane's output contract and is
        // classified as a provider failure, not a schema mismatch.
        if (!$response instanceof StructuredAgentResponse) {
            return $this->failedResult(new RuntimeException('The provider answered with a non-structured response.'), $request);
        }

        $text = $response['text'] ?? null;
        $interpretation = AiLanguageInterpretation::tryFrom((string) ($response['interpretation'] ?? ''));
        $candidates = $response['candidates'] ?? null;

        if (!is_string($text) || trim($text) === '' || mb_strlen(trim($text)) > $request->maximumReplyCharacters || $interpretation === null || !is_array($candidates)) {
            return $this->invalidResult($request, $response);
        }

        $proposals = $this->proposals($candidates);

        if ($proposals === null) {
            return $this->invalidResult($request, $response);
        }

        return app()->makeWith(LanguageResult::class, [
            'status' => AiLanguageResultStatus::Completed,
            'text' => trim($text),
            'interpretation' => $interpretation,
            'proposals' => $proposals,
            'inputTokens' => $response->usage->promptTokens,
            'outputTokens' => $response->usage->completionTokens,
            'providerRequestId' => $response->invocationId,
            'provider' => $response->meta->provider,
            'model' => $response->meta->model,
        ]);
    }

    /** @param array<mixed> $candidates
     * @return list<LanguageProposal>|null
     */
    private function proposals(array $candidates): array|null
    {
        if (count($candidates) > 2) {
            return null;
        }

        $proposals = [];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                return null;
            }

            $proposal = $this->proposal($candidate);

            if ($proposal === null) {
                return null;
            }

            $proposals[] = $proposal;
        }

        return $proposals;
    }

    /** @param array<string, mixed> $candidate */
    private function proposal(array $candidate): LanguageProposal|null
    {
        $type = AiLanguageProposalType::tryFrom((string) ($candidate['type'] ?? ''));
        $resource = array_key_exists('resource', $candidate) && $candidate['resource'] !== null
            ? AiSocialResource::tryFrom((string) $candidate['resource'])
            : null;
        $amount = $candidate['amount'] ?? null;
        $dueAt = $this->dueAt($candidate['due_at'] ?? null);

        if ($type === null || !is_int($candidate['source_message_id'] ?? null) || $candidate['source_message_id'] < 1 || ($candidate['resource'] ?? null) !== null && $resource === null || $amount !== null && (!is_int($amount) || $amount < 1 || $amount > 2_000_000_000) || ($candidate['due_at'] ?? null) !== null && $dueAt === null) {
            return null;
        }

        if ($type === AiLanguageProposalType::Claim && ($resource === null || $amount === null || $dueAt !== null)) {
            return null;
        }

        if ($type === AiLanguageProposalType::Commitment && ($resource === null || $amount === null || $dueAt === null)) {
            return null;
        }

        return app()->makeWith(LanguageProposal::class, [
            'type' => $type,
            'sourceMessageId' => $candidate['source_message_id'],
            'resource' => $resource,
            'amount' => $amount,
            'dueAt' => $dueAt,
        ]);
    }

    private function dueAt(mixed $value): CarbonImmutable|null
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function invalidResult(LanguageRequest $request, AgentResponse|null $response = null): LanguageResult
    {
        $attribution = $this->attribution($request);

        return app()->makeWith(LanguageResult::class, [
            'status' => AiLanguageResultStatus::Invalid,
            'text' => null,
            'interpretation' => null,
            'proposals' => [],
            'inputTokens' => $response?->usage->promptTokens ?? 0,
            'outputTokens' => $response?->usage->completionTokens ?? 0,
            'providerRequestId' => $response?->invocationId,
            'provider' => $response?->meta->provider ?? $attribution['provider'],
            'model' => $response?->meta->model ?? $attribution['model'],
        ]);
    }

    private function failedResult(Throwable $exception, LanguageRequest $request): LanguageResult
    {
        $failureDescription = strtolower($exception::class . ' ' . $exception->getMessage());
        $status = str_contains($failureDescription, 'timeout') || str_contains($failureDescription, 'timed out')
            ? AiLanguageResultStatus::TimedOut
            : AiLanguageResultStatus::Failed;
        $attribution = $this->attribution($request);

        return app()->makeWith(LanguageResult::class, [
            'status' => $status,
            'text' => null,
            'interpretation' => null,
            'proposals' => [],
            'inputTokens' => 0,
            'outputTokens' => 0,
            'providerRequestId' => null,
            'provider' => $attribution['provider'],
            'model' => $attribution['model'],
        ]);
    }

    /**
     * The rung an attempt is attributed to when no provider answered it.
     *
     * The receipt records the provider that replied for a completed call; for a call that never
     * got that far, naming the rung that was meant to answer is the difference between a receipt
     * that explains the attempt and one that only says `failed`.
     *
     * @return array{provider: string, model: string}
     */
    private function attribution(LanguageRequest $request): array
    {
        return $request->ladder->primary() ?? ['provider' => '', 'model' => ''];
    }
}
