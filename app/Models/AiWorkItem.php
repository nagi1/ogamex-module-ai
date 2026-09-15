<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;

/**
 * @property int $id
 * @property int $player_id
 * @property AiWorkKind $kind
 * @property Carbon $due_at
 * @property array<string, mixed>|null $payload
 * @property int $attempts
 * @property string $idempotency_key
 * @property AiWorkState $state
 * @property string|null $lease_token
 * @property Carbon|null $lease_until
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Unguarded]
class AiWorkItem extends Model
{
    protected function casts(): array
    {
        return ['kind' => AiWorkKind::class, 'state' => AiWorkState::class, 'due_at' => 'datetime', 'lease_until' => 'datetime', 'payload' => 'array'];
    }
}
