# Enhancement plan — ogame-ninja/scripts

## Verdict summary (one line)

Most of the repo's 46 mechanisms already ship host-read in our module (fleet save/recall, raid, spy, expedition, routine dark period, unit roles, transport); the two real gaps worth building are **debris recycling** and **attacker outreach**, everything else is ALREADY-DONE or REFUSE (hardcoded universe / over-engineering / non-human).

## ADOPT-IDEA — table

| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| Debris recycling (own DF + expedition DF, M19/M34/M37) | Sends recyclers to a debris field (`RecyclersNeeded` ≤ owned recyclers) and returns the loot | New planner beside `app/Domain/Decision/QueueableRaidPlanner`-style; host `app/GameMissions/RecycleMission.php` already exists | Closes the only missing ordinary-play loop after raids/expeditions; "send recyclers to the DF you made" is textbook play, host mission exists, nothing else covers it |
| Message a new attacker (M17) | On a hostile inbound/committed attack, send one casual authored line ("online :)") to the attacker | Extend `app/Actions/RunAiConversationCycleAction.php` + `BuildAuthoredSocialReplyAction.php` (currently inbound-only) | Alert-human-defender reply is ordinary play; reuses the existing authored-dialogue protocol, no new dependency |

## ENHANCE — table

| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| `QueueableUnitPlanner` buys defence only reactively when `underAttack` (`app/Domain/Decision/QueueableUnitPlanner.php`) | `clone_defenses.go` / `Standard_Defences.go` top defence to a standing per-planet target on a slow cadence, not only under fire | Add a slow "standard defence" pass: defence value kept at a persona ratio of fleet value (taste over host data, not a hardcoded count list) so a turtled/miner account keeps a baseline wall between attacks | `app/Domain/Decision/QueueableUnitPlanner.php`, `app/Domain/Decision/Policies/TurtlePolicy.php` / `MinerPolicy.php` |
| `QueueableTransferPlanner` ferries only a colony's next-level shortfall (`app/Domain/Decision/QueueableTransferPlanner.php`) | `repatriate_all.go` consolidates surplus to one homeworld when a body overflows | Add consolidation: when a planet's stored resources near its storage cap, ship the surplus to the best-developed body instead of letting mines stall | `app/Domain/Decision/QueueableTransferPlanner.php` |

## ALREADY-DONE (confirmed by grep)

- Activity-star reading + avoid-just-touched targets — `app/Domain/Perception/ActivityIntelReader.php` (`activityAt`, `moonOnlyActivity`, `activityProbabilityAtEta`); used by `QueueableSpyPlanner.php`, `QueueAiRaidAction.php`
- Spy candidate selection (skip vacation/admin/active, rank by known yield + closeness) — `app/Domain/Decision/QueueableSpyPlanner.php`
- Raid scoring: profit test, bashing limit, storage-fill cadence, loot-to-fuel tier — `app/Domain/Decision/RaidPlanner.php`, `app/Domain/Raid/RaidEstimate.php`, `app/Infrastructure/Battle/NativeRaidEstimator.php`
- Fleetsave (deployment between own planets) + recall — `app/Domain/Decision/QueueableFleetSavePlanner.php`, `QueueableRecall.php`, `app/Actions/QueueAiFleetSaveAction.php`, `QueueAiRecallAction.php`
- Proactive save before a real absence (night-fleetsave equivalent) — `QueueableFleetSavePlanner::proactivePlan()`
- Fleetsave that can fail (overnight gamble) — `app/Domain/Decision/SaveFailurePolicy.php`
- Sleep/dark-period schedule with drift + absences — `app/Domain/Routine/SessionPlanner.php`, `RoutineProfile.php`
- Expedition slot fill — `app/Domain/Decision/QueueableExpeditionPlanner.php`, `app/Actions/QueueAiExpeditionAction.php`
- Unit roles in opening order (cargo → colony → probe → defence → escort) — `app/Domain/Decision/QueueableUnitPlanner.php`
- Demand-driven resource ferry — `app/Domain/Decision/QueueableTransferPlanner.php`
- Building/colony opening, storage-overflow, energy-before-throttle — `app/Domain/Decision/FacilityChain.php`, `EconomyUpgrades.php`, `EnergyCapacity.php`, `QueueableBuildingPlanner.php`, `QueueableColonyPlanner.php`
- Authored social reply to inbound messages — `app/Actions/RunAiConversationCycleAction.php`, `ClassifyInboundSocialExchangeAction.php`, `BuildAuthoredSocialReplyAction.php`
- Flight-arrival-anchored wake (vs fixed poll) — `app/Domain/Scheduling/SessionDecisionService.php` `nextMaterialEventWake()`

## REFUSE — list with one-line reason

- M16 fake activity (`activities.go`) — fabricates the activity star; gate 3 non-human detection noise.
- M12 IPM volley (`ipm.go`) — war-only niche; hardcoded count + construction time.
- M21 lifeform tech tree (`auto_lifeform_techs.go`) — hardcoded tier-1 tech list (gate 1).
- M22 offer of the day (`buy_offer_of_the_day.go`) — pay-to-win automation, not ordinary play.
- M33 auction bidding (`auction.go`) — gate 2 canonical over-engineering + hardcoded price tiers (gate 1).
- M27 highscore crawl (`highscore_crawler.go`) — bot-like page crawl, no gameplay value.
- M23/M24 DF-gone alert + system watch (`debris_field_watcher.go`, `watch_systems.go`) — 24/7 polling/telemetry, machine-shaped.
- M25/M26 Telegram bot + chat forwarding (`handle_telegram_msg.go`, `private_chat_notifications.go`) — out-of-scope notification infra / host-side daemon.
- M38 probe transport (`TransportWithProbe.go`) — manually-entered round-trip time, niche.
- M45 build-then-cancel (`build_cancel.go`) — resource-hiding exploit, marginal value.
- M06 ABM topping (`abm_builder.go`) — hardcoded missile counts, niche war upkeep.
- M46 find master (`find_master.go`) — fleet-value "master" selection; covered by `QueueableFleetSavePlanner::rankedDestinations()`.
- M14 logout/login deploy-recall daemon part (`deploy_recall_fleetsave.go`) — host-side daemon (forbidden); the fleet-move half is already shipped.
- M36 expedition by ship-list auto-split (`expedition_by_list_of_ships.go`) — over-engineered fleet splitting; our single-hull expedition is sufficient.

## Priority recommendation — single highest-value change

Build **debris recycling** first. It is the one genuine missing ordinary-play loop: the module already raids (creating DF) and expedites (creating DF), but never sends recyclers to collect, so loot the host models is left on the field. The host already exposes `RecycleMission` (`/home/nagi/code/ogamex-next/app/GameMissions/RecycleMission.php`), the rule is a one-loop planner (`recyclers needed ≤ owned` → dispatch), it names a human behaviour ("send recyclers to your own DF"), and it needs no new dependency. Start from `QueueableTransferPlanner`/`RaidPlanner` shape, host-read, with recycler count and debris coordinates from the host.

## Open questions / risks

- **Host debris model:** verify `RecycleMission` and the host's debris-field rows expose recyclers-needed and coordinates read-only to module code before building (no host-side daemon).
- **Gate 1 on the defence pass:** the "standard defence" target must be taste-over-host-data (a defence-to-fleet-value ratio), never the repo's literal `21000 rocket / 4000 LL` list.
- **Attacker-message frequency:** a human does not message every probe; the outreach must be bounded (once per distinct attacker, never machine-paced) or it becomes M17's bot-shaped cousin.
- **Recycling duplication risk:** confirm the host has no auto-recycle AI already, or this becomes a second authority over the same debris.
