<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Enums\FirstBuildingTarget;
use Modules\AI\Models\AiProfile;

interface BuildingScoringPolicy
{
    public function score(AiProfile $profile, FirstBuildingTarget $target): int;
}
