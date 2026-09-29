<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Doctrine;

use LogicException;

/**
 * The "def-miner" play style as the source states it: a definition, never evidence.
 *
 * The source names the school and remarks on its rhythm, but states no ratio, no
 * threshold and no mechanic. Callers may therefore use the label to describe a
 * player, never to justify a number. If a later source states figures, this stance
 * yields to that source instead of restating a value on its own.
 */
final class DefMinerStance
{
    public function schoolPosition(): ClaimType
    {
        return ClaimType::Documented;
    }

    public function activityRhythm(): ClaimType
    {
        return ClaimType::Anecdotal;
    }

    public function implicitMechanics(): ClaimType
    {
        return ClaimType::Contested;
    }

    public function confidence(): StanceConfidence
    {
        return StanceConfidence::Medium;
    }

    /**
     * The source states no numeric threshold for this play style.
     *
     * @return list<string>
     */
    public function numericThresholds(): array
    {
        return [];
    }

    /**
     * @throws LogicException always: the source states no ratio to return.
     */
    public function buildRatio(): never
    {
        throw new LogicException(
            'The def-miner source states no build ratio; the stance yields to a sourced ratio.'
        );
    }
}

/**
 * Declared beside the stance because no other source in the module draws this
 * distinction between a stated position, a passing remark and a mechanic that is
 * only implied.
 */
enum ClaimType: string
{
    case Documented = 'documented';
    case Anecdotal = 'anecdotal';
    case Contested = 'contested';
}

enum StanceConfidence: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
