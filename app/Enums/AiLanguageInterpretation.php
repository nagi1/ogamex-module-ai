<?php

namespace Modules\AI\Enums;

enum AiLanguageInterpretation: string
{
    case None = 'none';
    case Claim = 'claim';
    case Commitment = 'commitment';
}
