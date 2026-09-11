<?php

namespace Modules\AI\Enums;

enum AiActionReceiptResultKey: string
{
    case QueueId = 'queue_id';
    case Reason = 'reason';
    case Decision = 'decision';
    case PlanetId = 'planet_id';
}
