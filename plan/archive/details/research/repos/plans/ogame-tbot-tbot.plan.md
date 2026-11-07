# Enhancement plan — ogame-tbot/TBot

## Verdict summary
TBot's defender/fleetsave discrimination, its single-planet save fallback and its scheduled recall are the three ideas worth building as host-read versions; its hardcoded object tables, captcha solver, spycrash and 24/7 machinery are refused.

## ADOPT-IDEA

| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| Fleetsave mission fallback (M15) | When a deployment is impossible (no second own body / no moon), save to a debris field with recyclers on a harvest mission, or to a planet on the last legal mission | `app/Domain/Decision/QueueableFleetSavePlanner.php` (`saveFor`), `app/Actions/QueueAiFleetSaveAction.php` | A one-planet account currently **cannot save at all** (`rankedDestinations()` needs a second own planet), so a young account under attack does nothing — itself a machine tell. A human parks on a DF. Host-read: recycler machine name + own in-flight debris. |
| Probe-only inbound is no threat (M08) | Treat an espionage-probe-only inbound as "no save needed" instead of a hostile | `app/Domain/Perception/PlayerObservationService.php` (`inboundThreat`) | If the host ever flags a probe as "under attack", we currently fleetsave from a single probe — a reaction no human makes. One host mission-type check, no new code path. |

## ENHANCE

| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| Reactive save fires for **any** host-declared under-attack with ≥2 own bodies; `saveFor()` never checks fleet value | `DefenderWorker` ignores weak attacks (inbound fleet points < local fleet points / ratio) and ignores "nothing to save" (`TotalResources` and `fleetPoints` below thresholds) | Add the persona exposure band already computed in `exposureBand()` (and a host fleet-points comparison) to the reactive save: skip when the local fleet is below the band, or the inbound points are a small fraction of the parked fleet | `QueueableFleetSavePlanner.php` (`saveFor`), `PlayerObservationService.php` (`inboundThreat`) |
| Recall is eligibility-driven only: `recallState()` offers it whenever a hostile is gone and the next session happens to run | `FleetScheduler.AutoFleetSave` schedules a recall timer at **half the deployment duration + jitter** | Schedule the recall at ~half the save's duration (deterministic per-account jitter) instead of waiting for an arbitrary later session | `QueueableFleetSavePlanner.php` (`recallPlan`), `Actions/ScheduleAiIntentAction.php` (`scheduleRecall`) |
| Spy origin is the **first** planet with an idle probe (`origin()` iterates planet order) | `AutoFarmWorker.GetBestOrigin` picks the **closest** celestial with probes | Pick the closest own planet with an idle probe to the chosen target, not the first in collection order | `app/Domain/Decision/QueueableSpyPlanner.php` (`origin`, `target`) |

## ALREADY-DONE (confirmed by grep)

- Session self-scheduling + humanized (Weibull/heavy-tailed) waits — `app/Domain/Scheduling/SessionDecisionService.php`, `app/Domain/Routine/SessionPlanner.php`, `app/Domain/Scheduling/NextDueTimeCalculator.php` (covers M01/M02, beats M03's fixed ms buckets).
- Dark-period "sleep" window with drift — `SessionPlanner::dayWindow()` / `isAwake()` (`CORE_DARK_MINUTES = 540`) — M04.
- Inbound/attack detection — `PlayerObservationService::inboundThreat()` via host `currentPlayerUnderAttack()` — M05.
- Reactive fleetsave — deployment at slowest speed, cargo lifted, shadow split — `QueueableFleetSavePlanner.php` + `Actions/QueueAiFleetSaveAction.php` — M14/M16/M17.
- Deliberate save failure ("overnight gamble") — `app/Domain/Decision/SaveFailurePolicy.php` (TBot has nothing like this).
- Recall executor with ownership guard — `Actions/QueueAiRecallAction.php` + `QueueableFleetSavePlanner::recallPlan()` — M19.
- Proactive save before an absence — `QueueableFleetSavePlanner::proactivePlan()` + `upcomingAbsenceMinutes` — M20.
- Mine selection by host-read payback — `app/Domain/Decision/EconomyUpgrades.php` — M33/M34.
- Research selection via chain — `app/Domain/Decision/QueueableBuildingPlanner.php`, `FacilityChain.php` — M35.
- Transfer/repatriate netting in-flight transports — `app/Domain/Decision/QueueableTransferPlanner.php` — M36.
- Distance / fuel / cargo from the host — `FleetMissionService` calls in `RaidPlanner.php`, `QueueableSpyPlanner.php`, `QueueableFleetSavePlanner.php` — M43–M46.
- Minimum-rank filter — `PlayerObservationService::scoreViable()` — M22.
- Raid triage + profit test via host battle engine — `RaidPlanner.php` + `Infrastructure/Battle/NativeRaidEstimator.php` — M25/M26/M27.
- Authored message-to-attacker — `Actions/BuildAuthoredSocialReplyAction.php` — M13.
- Faction whitelist — `app/Support/CooperativeHostilityPolicy.php` — M07.
- Bounded empty-slot colonisation scan — `QueueableColonyPlanner.php` — M40 (partial).
- Expedition feasibility + slot gating, minimal single civil ship — `QueueableExpeditionPlanner.php` — M29/M31/M32.

## REFUSE

- Captcha solver (M42) — ToS-violating image-hash table; gate-3 fail.
- Spycrash (M41) — deliberate probe-crash debris exploit; bot-only signature.
- Offer of the day (M38) — premium-shop automation, not ordinary play.
- Whole-galaxy AutoDiscovery (M39) — systematic enumeration is a bot signature.
- Hardcoded price/cargo/speed tables + `CalcPrice` (M43–M46) — gate-1; host supplies these.
- Fixed ms interval buckets (M03) — machine-shaped; our heavy-tailed waits are better.
- Fake activity login (M06) — daemon-shaped tell.
- Optimal farm speed (M27) — machine play.
- Telegram remote control / WebUI proxy / ogamed subprocess — out of scope, forbidden daemon.

## Priority recommendation

Add the **weak-attack / nothing-to-save gate to the reactive fleetsave**. `saveFor()` saves any fleet whenever the host says "under attack", even a lone cargo against an incoming single probe — an over-reaction that repeats identically every time. Reuse `exposureBand()` plus one host fleet-points comparison inside `QueueableFleetSavePlanner::saveFor()` and its call site. Smallest diff, fully host-read, named as ordinary play ("I don't bother moving one small cargo").

## Open questions / risks

1. Does the host's `currentPlayerUnderAttack()` ever return true for an espionage-probe mission? If not, M08 is moot.
2. Does the host expose fleet **points** for the redacted inbound composition? If not, use mission type as the proxy.
3. Recall-at-half-duration needs a **future-dated** recall work item; confirm `ScheduleAiIntentAction` can enqueue a recall with `due_at`.
4. Mission fallback requires the account to own recyclers; verify the host's harvest mission accepts an own-debris-field target.
5. Discrimination must stay inside gate 3: a **fleeter** still saves anything real — the gate skips only below the persona band, never above it.
