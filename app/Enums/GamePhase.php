<?php

namespace Modules\AI\Enums;

/**
 * The account's game phase, derived from host reads only (RV-011).
 *
 * The phase gates which class of raid target is eligible, never what exists: the
 * object universe, prices and requirements stay host-read, and the thresholds
 * below are policy over host data — astrophysics level and planet count — not a
 * hardcoded object list.
 */
enum GamePhase: string
{
    /** The opening: one planet, no colonies, farming inactives only. */
    case Early = 'early';

    /** A colony exists: active (fleet-less) players are fair game. */
    case Mid = 'mid';

    /** Astrophysics 23+: even a defended fleet can be crashed. */
    case Late = 'late';
}
