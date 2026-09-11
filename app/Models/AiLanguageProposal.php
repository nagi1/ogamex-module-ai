<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Modules\AI\Enums\AiLanguageProposalRejectionReason;
use Modules\AI\Enums\AiLanguageProposalState;
use Modules\AI\Enums\AiLanguageProposalType;

#[Unguarded]
class AiLanguageProposal extends Model
{
    protected function casts(): array
    {
        return [
            'type' => AiLanguageProposalType::class,
            'state' => AiLanguageProposalState::class,
            'rejection_reason' => AiLanguageProposalRejectionReason::class,
            'terms' => 'array',
        ];
    }
}
