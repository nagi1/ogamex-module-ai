<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;

/**
 * @property int $id
 * @property int $player_id
 * @property AiArchetype $archetype
 * @property AiSkillBand $skill_band
 * @property int $random_seed
 * @property bool $enabled
 * @property array<string, mixed>|null $settings
 */
class AiProfile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['archetype' => AiArchetype::class, 'skill_band' => AiSkillBand::class, 'enabled' => 'boolean', 'settings' => 'array'];
    }
}
