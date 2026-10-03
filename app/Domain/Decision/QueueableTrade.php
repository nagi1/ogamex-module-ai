<?php

namespace Modules\AI\Domain\Decision;

/**
 * A resource-merchant trade on one own body: sell `giveAmount` of the overflowing resource for the
 * scarce one (LOOP-002). The merchant's rates are the host's, drawn when the merchant is called.
 */
readonly class QueueableTrade
{
    public function __construct(
        public int $planetId,
        public string $giveResource,
        public string $receiveResource,
        public int $giveAmount,
    ) {
    }
}
