# Cooperative mode — human coalition versus AI Empire

Owner: the same AI module. Delivery: [Package 5](../../WORK-PACKAGES.md). Evidence: [cooperative precedents](../research/pve-precedents.md). The mode below is a proposed adaptation of existing mechanics, not claimed official OGame lore.

## Separate mode, shared engine

Run a dedicated PvE universe or server instance selected before enrollment. Human alliances keep their own membership, leadership and chat, but share one coalition against the disclosed AI faction. The first version does not mix neutral independent AI populations into this universe. Normal mixed-player universes keep their existing behavior.

Reuse the module's accounts, profiles, routines, economy, research, colonization, saving, combat estimates, memory and scheduler. Add only coalition membership, a campaign coordinator, objective/contribution records and a module-owned campaign page. No second AI runtime, new ship/resource type, custom combat engine or special mission is required.

## Coalition rules

Block human-on-human attacks, hostile espionage, moon destruction and missile attacks through the host's authoritative mode restrictions, including direct requests and alternate launch paths. Friendly transport and ACS cooperation still follow ordinary permissions, capacity and timing rules. Coalition membership does not silently grant access to private chats or reports.

Use [E6–E7](module-extension-points.md) for enforcement and safe shutdown. A social agreement alone cannot make the server PvE. Do not enable this mode until every relevant hostile path is covered. Fix mode membership for active engagements; no last-minute side switch to dodge a flight.

## Campaign loop

These stages are proposals built from standard actions, with criteria published before each campaign:

1. **Prepare:** display campaign duration, participating AI accounts and goals. Humans organize scouting, resource supply, fleets and return windows. Ordinary espionage determines enemy composition; the objective board does not reveal secret fleet state.
2. **Coordinate:** offer a small set of declared AI strongholds—ordinary planets, not a new entity. Humans choose targets and use ACS attacks, defensive support and transport. Different groups can pursue different targets; no universal minimum attendance borrowed from another game.
3. **Resolve:** mark an objective complete only when a committed core battle report records a coalition attack victory against its designated planet, with the configured eligible participants. A draw or retreat without combat does not count. Each objective credits once. Winning does not capture or delete the planet.
4. **Recover or continue:** surviving enemies rebuild normally; humans can prepare another attempt after a failure. Completed objectives stay credited for that campaign even if the enemy recovers.
5. **Conclude:** coalition success means completing the announced objective set before the deadline; otherwise record an incomplete campaign, not server destruction. Publish the outcome and allow a recovery interval. A new campaign reuses persistent identities without resetting their resources or losses.

## Enemy coordination and difficulty

The coordinator assigns goals such as defend a stronghold, move supplies or rebuild; individual accounts still decide feasible actions. Shared faction information consists of legitimately obtained reports with source, time and delivery delay. Coordination can be stronger than normal mode without exposing hidden human state.

The first campaign limits enemy aggression to a published campaign schedule and scope. Attacks still require normal fuel, ships, slots and travel time; announced operations can fail because preparation fails. No teleporting reinforcements, response to secret login status or attacks outside the declared mode rules.

Use disclosed fixed starting conditions, ordinary production and a pre-campaign difficulty profile. Adjust future campaigns using aggregate participation and outcomes; never scale a defender invisibly after humans commit fleets. Review repeated pressure and losses before increasing difficulty.

## Contributions and rewards

Scouts share authorized reports; suppliers deliver real resources to participating allies; defenders use permitted ACS support; attackers and recyclers commit actual fleets. The board distinguishes verified actions from self-reported assistance. Visibility requires consent or ordinary report access.

Initial rewards are ordinary loot/debris plus campaign recognition. Do not mint resources for repeated transport loops or pay a universal damage leaderboard. Deduplicate by operation/report, cap repeated contribution credit and exclude AI participation from human credit. Supporting a coalition should matter without inventing a second economy.

## Shutdown and acceptance

Finish or suspend new operations before changing mode. Core flights already launched still resolve; never erase them to award victory. If the provider disappears while PvE is active, the host blocks new hostile dispatch until operators complete safe shutdown. It must not silently unlock human PvP.

Success requires a small coalition completing a campaign with meaningful support roles, understandable losses and a recoverable failed attempt. Review whether players want another campaign. Separate evidence for this mode from normal-mode retention and test total combat/coordination load within the same module budgets.
