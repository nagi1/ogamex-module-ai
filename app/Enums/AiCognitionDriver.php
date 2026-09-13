<?php

namespace Modules\AI\Enums;

/**
 * Selects the cognition driver pair.
 *
 * Affect and social cognition share one enum because the module requires a single
 * integrated character state behind both contracts; selecting them independently
 * would allow two character states to diverge.
 */
enum AiCognitionDriver: string
{
    case Native = 'native';
    case Fatima = 'fatima';
}
