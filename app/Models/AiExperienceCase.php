<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AiExperienceCaseFamily;
use Modules\AI\Enums\AiExperienceOutcome;

/**
 * @property int $id
 * @property int $player_id
 * @property int $outcome_observation_id
 * @property AiExperienceCaseFamily $family
 * @property AiExperienceOutcome $outcome
 * @property string $feature_version
 * @property string $ruleset_version
 * @property array<string, int|float|string|null> $features
 * @property float $utility
 * @property float $uncertainty
 */
#[Unguarded]
class AiExperienceCase extends Model
{
    protected function casts(): array
    {
        return ['family' => AiExperienceCaseFamily::class, 'outcome' => AiExperienceOutcome::class, 'features' => 'array', 'utility' => 'decimal:4', 'uncertainty' => 'decimal:4'];
    }
}
