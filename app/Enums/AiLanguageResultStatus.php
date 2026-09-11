<?php

namespace Modules\AI\Enums;

enum AiLanguageResultStatus: string
{
    case Completed = 'completed';
    case Disabled = 'disabled';
    case Invalid = 'invalid';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
}
