<?php

namespace Modules\AI\Enums;

enum AiLanguageRequestState: string
{
    /** A request this process is making right now; only it may settle the attempt. */
    case Generating = 'generating';
    case Completed = 'completed';
    case Failed = 'failed';
    case Invalid = 'invalid';
    /** A timed-out call that may still be completing remotely, so its attempt stays charged open. */
    case Uncertain = 'uncertain';
    /** An attempt charged at its reserved maximum once no completion could be observed. */
    case Unobserved = 'unobserved';
}
