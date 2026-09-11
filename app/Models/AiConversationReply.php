<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\AI\Enums\AiConversationReplyState;

/**
 * A sealed reply keeps its source range and delivery receipt together so a retry cannot send twice.
 *
 * @property int $id
 * @property int $player_id
 * @property int $counterparty_player_id
 * @property int $source_first_message_id
 * @property int $source_last_message_id
 * @property string $message
 * @property AiConversationReplyState $state
 * @property string|null $delivery_key
 * @property int|null $delivered_chat_message_id
 * @property Carbon|null $expires_at
 * @property Carbon|null $sealed_at
 * @property Carbon|null $delivered_at
 * @property int $revision
 */
#[Unguarded]
class AiConversationReply extends Model
{
    protected function casts(): array
    {
        return [
            'state' => AiConversationReplyState::class,
            'expires_at' => 'datetime',
            'sealed_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
