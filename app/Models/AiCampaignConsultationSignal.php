<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AiCampaignConsultationTrigger;

/**
 * One material campaign event that has not yet been consulted on.
 *
 * A signal is transient: the lane consumes it on the next campaign decision whether or not the
 * consultation produced an applied recommendation, so a disabled lane never grows a backlog
 * that fires the moment an operator turns it on.
 *
 * @property int $campaign_id
 * @property AiCampaignConsultationTrigger $trigger
 */
#[Unguarded]
class AiCampaignConsultationSignal extends Model
{
    protected function casts(): array
    {
        return [
            'trigger' => AiCampaignConsultationTrigger::class,
            'fired_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
