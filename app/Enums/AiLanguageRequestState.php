<?php

namespace Modules\AI\Enums;

enum AiLanguageRequestState: string
{
    case Generating = 'generating';
    case Completed = 'completed';
    case Failed = 'failed';
    case Invalid = 'invalid';
    case Uncertain = 'uncertain';
}
