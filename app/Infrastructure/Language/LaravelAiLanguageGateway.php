<?php

namespace Modules\AI\Infrastructure\Language;

use Carbon\CarbonImmutable;
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
use Throwable;

class LaravelAiLanguageGateway implements LanguageGateway
{
    public function generateConversationReply(LanguageRequest $request): LanguageResult
    {
        try {
            $response = app()->makeWith(OgameConversationReplyAgent::class, ['request' => $request])
                ->prompt($request->context->serialized, provider: $request->provider, model: $request->model, timeout: $request->timeoutSeconds);
        } catch (Throwable $exception) {
            return $this->failedResult($exception, $request);
        }

        /** The SDK guarantees structured responses for agents with HasStructuredOutput. */
        /** @var StructuredAgentResponse $response */
        return $this->structuredResult($response, $request);
    }

    private function structuredResult(StructuredAgentResponse $response, LanguageRequest $request): LanguageResult
    {
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

    private function invalidResult(LanguageRequest $request, StructuredAgentResponse|null $response = null): LanguageResult
    {
        return app()->makeWith(LanguageResult::class, [
            'status' => AiLanguageResultStatus::Invalid,
            'text' => null,
            'interpretation' => null,
            'proposals' => [],
            'inputTokens' => $response?->usage->promptTokens ?? 0,
            'outputTokens' => $response?->usage->completionTokens ?? 0,
            'providerRequestId' => $response?->invocationId,
            'provider' => $response?->meta->provider ?? $request->provider,
            'model' => $response?->meta->model ?? $request->model,
        ]);
    }

    private function failedResult(Throwable $exception, LanguageRequest $request): LanguageResult
    {
        $failureDescription = strtolower($exception::class . ' ' . $exception->getMessage());
        $status = str_contains($failureDescription, 'timeout') || str_contains($failureDescription, 'timed out')
            ? AiLanguageResultStatus::TimedOut
            : AiLanguageResultStatus::Failed;

        return app()->makeWith(LanguageResult::class, [
            'status' => $status,
            'text' => null,
            'interpretation' => null,
            'proposals' => [],
            'inputTokens' => 0,
            'outputTokens' => 0,
            'providerRequestId' => null,
            'provider' => $request->provider,
            'model' => $request->model,
        ]);
    }
}
