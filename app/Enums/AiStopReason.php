<?php

namespace Modules\AI\Enums;

/**
 * Why an admission check stopped a pass from doing all the work it could have done.
 *
 * These are recorded as daily counters rather than log lines: the question an operator asks
 * during a pilot is "why is the population quiet today", not "what happened at 03:14". Every
 * reason is a limit this module owns, so a refusal is always a decision it made and can
 * explain, never a failure it hid.
 */
enum AiStopReason: string
{
    /** Staff turned AI work off; nothing new starts until they turn it back on. */
    case StaffSwitch = 'staff_switch';

    /** The enabled AI population is larger than the universe cap allows. */
    case ProfileCap = 'profile_cap';

    /** As many sessions are in flight as the universe allows at once. */
    case ActiveSessionCap = 'active_session_cap';

    /** One dispatch pass reached its batch size and left the rest of the due work for later. */
    case DispatchLimit = 'dispatch_limit';

    /** The session action cap is zero, so a session plans and schedules but queues nothing. */
    case SessionActionCap = 'session_action_cap';

    /** The campaign consultation lane is off, so no provider is ever contacted. */
    case ConsultationDisabled = 'consultation_disabled';

    /** The operator removed this campaign event from the consultation allowlist. */
    case ConsultationTriggerDisallowed = 'consultation_trigger_disallowed';

    /** The same campaign event was consulted on too recently for a second call. */
    case ConsultationCooldown = 'consultation_cooldown';

    /** A consultation daily ceiling would be exceeded by another call. */
    case ConsultationUsageCap = 'consultation_usage_cap';

    /** As many consultations are in flight as the universe allows at once. */
    case ConsultationConcurrencyCap = 'consultation_concurrency_cap';

    /** A session's only candidate was DoNothing, so nothing else was available to choose. */
    case QuietDecision = 'quiet_decision';
}
