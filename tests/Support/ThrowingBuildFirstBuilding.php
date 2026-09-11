<?php

namespace Modules\AI\Tests\Support;

use Modules\AI\Domain\Decision\BuildFirstBuilding;
use Modules\AI\Models\AiProfile;
use RuntimeException;

class ThrowingBuildFirstBuilding extends BuildFirstBuilding
{
    public function __construct()
    {
    }

    public function choose(AiProfile $profile): array
    {
        throw app()->makeWith(RuntimeException::class, ['message' => 'test decision failure']);
    }
}
