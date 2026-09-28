<?php

namespace Modules\AI\Domain\Persona;

use Modules\AI\Enums\AiActivityBand;
use Modules\AI\Enums\AiDefenseDoctrine;
use Modules\AI\Enums\AiEconomicRole;
use Modules\AI\Enums\AiStockpileStrategy;

/**
 * The persisted persona dimensions that cannot be expressed as the continuous
 * taste scores: how much the account is around, what it believes about static
 * defence, what it does with accumulated resources, and how it approaches the
 * market. Generated together by AiPersonaFactory, never drawn independently.
 */
readonly class AiPersona
{
    public function __construct(
        public AiActivityBand $activityBand,
        public AiDefenseDoctrine $defenseDoctrine,
        public AiStockpileStrategy $stockpileStrategy,
        public AiEconomicRole $economicRole,
    ) {}
}
