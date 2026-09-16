<?php

namespace Modules\AI\Domain\Operability;

use Modules\AI\Enums\AiCampaignConsultationMode;
use Modules\AI\Enums\AiStopReason;

/**
 * The outcome of one campaign-consultation admission check.
 *
 * `mode` is the resolved lane state even when admission is refused, so a caller that wants
 * to record "observe" without applying anything can distinguish it from "off", which never
 * reached a provider. The reason travels with the refusal so the operator page can show
 * exactly which gate stopped the consultation.
 *
 * @property array<string, mixed> $context
 */
readonly class CampaignConsultationAdmission
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public bool $allowed = false,
        public AiCampaignConsultationMode $mode = AiCampaignConsultationMode::Off,
        public AiStopReason|null $stopReason = null,
        public array $context = [],
    ) {
    }
}
