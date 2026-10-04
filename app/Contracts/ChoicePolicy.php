<?php

namespace Modules\AI\Contracts;

use Modules\AI\Domain\Choice\ChoicePoint;
use Modules\AI\Models\AiProfile;

/** Picks one row of an economy choice; the host still validates whatever it picks. */
interface ChoicePolicy
{
    /** @return int an index into $point->candidates (0 is waiting) */
    public function choose(ChoicePoint $point, AiProfile $profile): int;

    public function name(): string;
}
