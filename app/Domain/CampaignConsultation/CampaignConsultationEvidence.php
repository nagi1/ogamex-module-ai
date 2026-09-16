<?php

namespace Modules\AI\Domain\CampaignConsultation;

use Carbon\CarbonImmutable;

/**
 * One typed, attributed piece of driver evidence a consultation brief may carry.
 *
 * The brief treats every external value as evidence, never as an instruction: the `source`
 * names the driver that produced it, `kind` names the field, `version` the driver release and
 * `collectedAt` when it was read. `authorized` is the module's own inclusion decision for this
 * item; the brief builder drops anything the caller did not authorise, so the lane can never
 * smuggle in a driver field the policy did not admit.
 */
readonly class CampaignConsultationEvidence
{
    public function __construct(
        public string $source,
        public string $kind,
        public string|int|float|bool|null $value,
        public string $version,
        public CarbonImmutable $collectedAt,
        public bool $authorized = true,
    ) {
    }
}
