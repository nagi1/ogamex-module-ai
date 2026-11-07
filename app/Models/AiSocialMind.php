<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * One AI account's living mind (SOCIAL-ENGINES-CONTRACT.md): its persona, what it saw, and the state the cognition
 * engines hand back to be sent with the next call.
 *
 * @property int $user_id
 * @property array<string, mixed> $state
 */
#[Unguarded]
class AiSocialMind extends Model
{
    protected function casts(): array
    {
        return ['state' => 'array'];
    }
}
