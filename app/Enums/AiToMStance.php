<?php

namespace Modules\AI\Enums;

/**
 * The account's theory-of-mind read of a counterparty: will it cooperate or exploit.
 *
 * PsychSim answers in these exact terms, so the enum is the module's typed boundary for the
 * driver's wire values. A null read is represented by the absence of the enum at the call site,
 * not by a third case: an account with no read keeps its native stance instead of pretending
 * it reasoned.
 */
enum AiToMStance: string
{
    case Cooperate = 'cooperate';
    case Defect = 'defect';

    /**
     * The human-readable word a prompt or brief carries to an LLM: the account is open to a
     * counterparty it models as cooperative, wary of one it models as exploitative.
     */
    public function word(): string
    {
        return match ($this) {
            self::Cooperate => 'open',
            self::Defect => 'wary',
        };
    }
}
