<?php

namespace Modules\AI\Enums;

/** The durable meaning an observation has for the receiving AI player. */
enum AiObservationKind: int
{
    case DirectChatMessageReceived = 1;
}
