<?php

namespace Modules\AI\Enums;

/** Explicitly published capabilities that may become safe Phase 2 intents. */
enum AiCapability: string
{
    case SaveResources = 'save_resources';
    case Build = 'build';
    case Research = 'research';
    case QueueUnits = 'queue_units';
    case Spy = 'spy';
    case Colonize = 'colonize';

    public function actionType(): AiCandidateActionType
    {
        return match ($this) {
            self::SaveResources => AiCandidateActionType::SaveResources,
            self::Build => AiCandidateActionType::Build,
            self::Research => AiCandidateActionType::Research,
            self::QueueUnits => AiCandidateActionType::QueueUnits,
            self::Spy => AiCandidateActionType::Spy,
            self::Colonize => AiCandidateActionType::Colonize,
        };
    }
}
