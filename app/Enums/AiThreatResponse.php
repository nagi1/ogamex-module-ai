<?php

namespace Modules\AI\Enums;

/**
 * What an account answers an inbound hostile fleet with.
 *
 * The host decides whether an attack is happening and what the account owns; which of these the
 * account answers with is module policy, and each one is carried out by the planner that already
 * owns that kind of order — the fleet-save planner, the ferry planner, the defence planners — so
 * nothing in this vocabulary names a unit, a coordinate or an amount.
 */
enum AiThreatResponse: string
{
    /** The fleet that is worth the trip leaves the threatened body before impact. */
    case EvacuateFleet = 'evacuate_fleet';

    /** The stock the body would otherwise lose leaves on the hulls the account keeps at home. */
    case EvacuateResources = 'evacuate_resources';

    /** The wall the body's exposure asks for is bought now, while the inbound is still flying. */
    case ReinforceDefense = 'reinforce_defense';

    /** The fleet stays home as bait, because the wall already covers what it is worth. */
    case AttemptNinja = 'attempt_ninja';

    /** The account noticed the inbound and has nothing worth doing about it. */
    case DoNothing = 'do_nothing';
}
