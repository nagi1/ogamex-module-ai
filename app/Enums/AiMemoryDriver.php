<?php

namespace Modules\AI\Enums;

/**
 * Selects the long-term recall implementation.
 *
 * The module's own facts table stays authoritative whichever driver answers, so this
 * chooses who performs retrieval, never who owns the truth.
 */
enum AiMemoryDriver: string
{
    case Native = 'native';

    /**
     * Ranked recall from the module's AgentOS memory sidecar. The driver stores nothing: the
     * module sends the authorised candidate set with every request and keeps the facts.
     */
    case AgentOs = 'agentos';
}
