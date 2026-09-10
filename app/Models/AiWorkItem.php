<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;

/**
 * @property int $id
 * @property int $player_id
 * @property AiWorkKind $kind
 * @property \Illuminate\Support\Carbon $due_at
 * @property array<string, mixed>|null $payload
 * @property int $attempts
 * @property string $idempotency_key
 * @property AiWorkState $state
 */
class AiWorkItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['kind' => AiWorkKind::class, 'state' => AiWorkState::class, 'due_at' => 'datetime', 'lease_until' => 'datetime', 'payload' => 'array'];
    }
}
