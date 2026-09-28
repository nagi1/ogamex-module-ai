<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AiActivityBand;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiDefenseDoctrine;
use Modules\AI\Enums\AiEconomicRole;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiStockpileStrategy;

/**
 * @property int $id
 * @property int $player_id
 * @property AiArchetype $archetype
 * @property AiSkillBand $skill_band
 * @property AiActivityBand|null $activity_band
 * @property AiDefenseDoctrine|null $defense_doctrine
 * @property AiStockpileStrategy|null $stockpile_strategy
 * @property AiEconomicRole|null $economic_role
 * @property int $persona_version
 * @property int $random_seed
 * @property bool $enabled
 * @property array<string, mixed>|null $settings
 */
#[Unguarded]
class AiProfile extends Model
{
    protected function casts(): array
    {
        return [
            'archetype' => AiArchetype::class,
            'skill_band' => AiSkillBand::class,
            'activity_band' => AiActivityBand::class,
            'defense_doctrine' => AiDefenseDoctrine::class,
            'stockpile_strategy' => AiStockpileStrategy::class,
            'economic_role' => AiEconomicRole::class,
            'enabled' => 'boolean',
            'settings' => 'array',
        ];
    }
}
