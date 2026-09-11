<?php

namespace Modules\AI\Enums;

enum AiSocialResponseReason: string
{
    case RoutineAcknowledgement = 'routine_acknowledgement';
    case MissingOrInvalidAmount = 'missing_or_invalid_amount';
    case TooManyOutstandingCommitments = 'too_many_outstanding_commitments';
    case InsufficientAvailableAmount = 'insufficient_available_amount';
    case TrustedAndSafe = 'trusted_and_safe';
    case InsufficientTrust = 'insufficient_trust';
    case TermsNeedConfirmation = 'terms_need_confirmation';
    case HarmNotAcknowledged = 'harm_not_acknowledged';
    case HarmNotRepaired = 'harm_not_repaired';
    case ApologyAcknowledged = 'apology_acknowledged';
    case CompensationNeeded = 'compensation_needed';
    case MissingOrInvalidTradeTerms = 'missing_or_invalid_trade_terms';
    case TransportCapabilityUnavailable = 'transport_capability_unavailable';
    case MissingCeasefireExpiry = 'missing_ceasefire_expiry';
    case UnsafeCeasefireRequest = 'unsafe_ceasefire_request';
    case CeasefireEnforcementUnavailable = 'ceasefire_enforcement_unavailable';
    case CoerciveWarning = 'coercive_warning';
    case WarningAcknowledgedWithoutCommitment = 'warning_acknowledged_without_commitment';
    case MissingCooperationScope = 'missing_cooperation_scope';
    case CooperationCapabilityUnavailable = 'cooperation_capability_unavailable';
    case MissingCompensationDueAt = 'missing_compensation_due_at';
    case MissingOrInvalidCompensationTerms = 'missing_or_invalid_compensation_terms';
    case CompensationRecorded = 'compensation_recorded';
}
