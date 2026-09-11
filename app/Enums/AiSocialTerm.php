<?php

namespace Modules\AI\Enums;

enum AiSocialTerm: string
{
    case Amount = 'amount';
    case Resource = 'resource';
    case AcknowledgesHarm = 'acknowledges_harm';
    case Repair = 'repair';
    case OfferedResource = 'offered_resource';
    case OfferedAmount = 'offered_amount';
    case RequestedResource = 'requested_resource';
    case RequestedAmount = 'requested_amount';
    case Coercive = 'coercive';
    case Scope = 'scope';
}
