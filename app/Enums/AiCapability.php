<?php

namespace Modules\AI\Enums;

/** Explicitly published capabilities that may become safe Phase 2 intents. */
enum AiCapability: string
{
    case Build = 'build';
    case Research = 'research';
    case QueueUnits = 'queue_units';
    case Spy = 'spy';
    case Colonize = 'colonize';

    public function actionType(): AiCandidateActionType
    {
        return match ($this) {
            self::Build => AiCandidateActionType::Build,
            self::Research => AiCandidateActionType::Research,
            self::QueueUnits => AiCandidateActionType::QueueUnits,
            self::Spy => AiCandidateActionType::Spy,
            self::Colonize => AiCandidateActionType::Colonize,
        };
    }
}
