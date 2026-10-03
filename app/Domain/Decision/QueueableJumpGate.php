<?php

namespace Modules\AI\Domain\Decision;

/**
 * All transferable ships of one gate-moon, jumped to another own gate-moon that is ready (WIK-035/046).
 */
readonly class QueueableJumpGate
{
    public function __construct(
        public int $sourceMoonId,
        public int $targetMoonId,
    ) {
    }
}
