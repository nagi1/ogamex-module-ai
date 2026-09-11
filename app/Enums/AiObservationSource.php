<?php

namespace Modules\AI\Enums;

/** Sources whose committed rows may become module-owned AI observations. */
enum AiObservationSource: int
{
    case ChatMessage = 1;
}
