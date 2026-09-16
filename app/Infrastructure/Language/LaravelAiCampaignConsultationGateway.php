<?php

namespace Modules\AI\Infrastructure\Language;

use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Modules\AI\Ai\Agents\OgameCampaignConsultationAgent;
use Modules\AI\Contracts\CampaignConsultationGateway;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationRecommendation;
use Modules\AI\Domain\CampaignConsultation\CampaignConsultationRequest;
use Modules\AI\Enums\AiCampaignConsultationRisk;
use Modules\AI\Enums\AiCampaignConsultationStatus;
use RuntimeException;
use Throwable;

/**
 * The SDK-backed consultation transport.
 *
 * The SDK walks the ordered provider ladder and reports failover; this class only maps the
 * structured response into a typed recommendation and classifies transport versus schema
 * failures. It never writes module state and never reads beyond the already-built brief.
 */
class LaravelAiCampaignConsultationGateway implements CampaignConsultationGateway
{
    public function recommend(CampaignConsultationRequest $request): CampaignConsultationRecommendation
    {
        if ($request->ladder->isEmpty()) {
            return $this->failed(new RuntimeException('No provider rung is available for this consultation.'), $request);
        }

        try {
            $response = app()->makeWith(OgameCampaignConsultationAgent::class, ['request' => $request])
                ->prompt($request->serializedBrief, provider: $request->ladder->toProviderMap(), timeout: $request->timeoutSeconds);
        } catch (Throwable $exception) {
            return $this->failed($exception, $request);
        }

        return $this->recommendation($response, $request);
    }

    private function recommendation(AgentResponse $response, CampaignConsultationRequest $request): CampaignConsultationRecommendation
    {
        // A plain text reply breaks the consultation lane's output contract: the brief requires
        // a structured envelope, so a non-structured answer is a provider failure, not a schema
        // mismatch.
        if (!$response instanceof StructuredAgentResponse) {
            return $this->failed(new RuntimeException('The provider answered with a non-structured response.'), $request);
        }

        $candidateId = $response['candidate_id'] ?? null;
        $risk = AiCampaignConsultationRisk::tryFrom((string) ($response['risk'] ?? ''));
        $reason = $response['reason'] ?? null;
        $evidenceIds = $response['evidence_ids'] ?? null;

        if ($risk === null || !is_string($reason) || trim($reason) === '' || !is_array($evidenceIds)) {
            return $this->invalid($request, $response);
        }

        if ($candidateId !== null && !is_int($candidateId)) {
            return $this->invalid($request, $response);
        }

        foreach ($evidenceIds as $evidenceId) {
            if (!is_int($evidenceId) || $evidenceId < 1) {
                return $this->invalid($request, $response);
            }
        }

        return app()->makeWith(CampaignConsultationRecommendation::class, [
            'status' => AiCampaignConsultationStatus::Completed,
            'candidateId' => $candidateId,
            'risk' => $risk,
            'reason' => trim($reason),
            'evidenceIds' => $evidenceIds,
            'inputTokens' => $response->usage->promptTokens,
            'outputTokens' => $response->usage->completionTokens,
            'providerRequestId' => $response->invocationId,
            'provider' => $response->meta->provider,
            'model' => $response->meta->model,
        ]);
    }

    private function invalid(CampaignConsultationRequest $request, AgentResponse|null $response = null): CampaignConsultationRecommendation
    {
        $attribution = $this->attribution($request);

        return app()->makeWith(CampaignConsultationRecommendation::class, [
            'status' => AiCampaignConsultationStatus::Invalid,
            'candidateId' => null,
            'risk' => null,
            'reason' => null,
            'evidenceIds' => [],
            'inputTokens' => $response?->usage->promptTokens ?? 0,
            'outputTokens' => $response?->usage->completionTokens ?? 0,
            'providerRequestId' => $response?->invocationId,
            'provider' => $response?->meta->provider ?? $attribution['provider'],
            'model' => $response?->meta->model ?? $attribution['model'],
        ]);
    }

    private function failed(Throwable $exception, CampaignConsultationRequest $request): CampaignConsultationRecommendation
    {
        $description = strtolower($exception::class . ' ' . $exception->getMessage());
        $status = str_contains($description, 'timeout') || str_contains($description, 'timed out')
            ? AiCampaignConsultationStatus::TimedOut
            : AiCampaignConsultationStatus::Failed;
        $attribution = $this->attribution($request);

        return app()->makeWith(CampaignConsultationRecommendation::class, [
            'status' => $status,
            'candidateId' => null,
            'risk' => null,
            'reason' => null,
            'evidenceIds' => [],
            'inputTokens' => 0,
            'outputTokens' => 0,
            'providerRequestId' => null,
            'provider' => $attribution['provider'],
            'model' => $attribution['model'],
        ]);
    }

    /**
     * @return array{provider: string, model: string}
     */
    private function attribution(CampaignConsultationRequest $request): array
    {
        return $request->ladder->primary() ?? ['provider' => '', 'model' => ''];
    }
}
