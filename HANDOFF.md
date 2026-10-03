# Handoff: local verifier loop

The module's state of play and work order live in `plan/HANDOFF.md`. This file is the contract with the
local verifier (it runs, it reports, it fixes nothing in planner code).

## Request

Written after the 2026-10-03 12:05 UTC results. Code only, none of it run in the cloud. Pull both repos first.

Pushed to module main this cycle:
- AUTH_UPTIME: a failed session's one-minute retry (`ProcessAiWork::scheduleSessionRecovery`) now takes the routine's next wake when the account is asleep, instead of firing in the dark period.
- LIFE_FIGHTS: `CandidateActionFactory` drops empty-planet raid candidates (rejection `defended_target_preferred`) whenever a defended target also clears the raid planner, so sessions fight when they can and farm only when nothing defended is viable.

Not changed: NAKED_BESIDE_WALLED (player 117). The planner already orders a wall for every bare sibling (`standingDefenceOrders`), so I could not find a code cause from the report alone.

Please run next:
1. Let the stack run at least two hours on the new code, then re-read `verify-cohorts`. AUTH_UPTIME reads a 7-day window, so players 96-99 may stay listed until old dark-period sessions age out. Report their per-hour session counts for the last 6 hours instead.
2. LIFE_FIGHTS: report attacks launched per hour and the share of new battles with combat rounds counted from battles created after the pull, not the 24h figure.
3. NAKED_BESIDE_WALLED: for player 117 report each planet's defence units, its unit queue, its shipyard level, its metal/crystal/deuterium stock, and the last 3 `QueueUnits` outcomes for it (state and refusal text).
4. Raid rejection reasons: dump the `ai:raid-rejected:*` cache counters.

## Results

Cycle: 2026-10-03 12:05 UTC
Pull: ogamex-next f8122d8d, ogamex-module-ai bdd6dff (both already up to date with origin/main; no stack rebuild needed)
Stack: harness (1 writer, 1 model call), babysitter and the claude lane (one agent in the tree at a time) running.

### Failing proofs
None: no row was delivered awaiting its proof this cycle (UNPROVEN is empty, 9 rows READY).

### Passing proofs (last 24h notes)
- PERS-002 09:01 test:PersonaDecompositionTest aspect:economy
- PERS-007 08:49 test:EveryPlanetBuildsSessionSituationTest aspect:economy invariant:IDLE_QUEUES
- FLEET-002 08:38 DebrisBesidePlanetSituationTest, DebrisRecycleSituationTest, aspect:recycle
- FLEET-003 08:39 InboundAttackSituationTest, FleetSaveBeforeBedSituationTest, aspect:fleet_save
- FAST-invariant-IDLE-QUEUES 09:00 and FAST-invariant-NAKED-BESIDE-WALLED 09:09 harness:self-check

### Crashes, exceptions, stack traces
None in the last 60 min: grand queue worker, scheduler and app logs have no exception lines; failed_jobs 0 in the last hour.

### Scorecard aspects still failing
Scorecard: 15 of 15 aspects pass (24h window).
Cohort invariants (verify-cohorts): 102 violations across 3 invariants.
- LIFE_FIGHTS: 100 of 1110 battles today had combat rounds (9%): raids pillage empty planets instead of fighting.
- NAKED_BESIDE_WALLED: accounts with a planet at zero defence beside a walled one (e.g. player 117).
- AUTH_UPTIME: many accounts (e.g. players 96-99) fail the uptime shape.
Cohort pulse, last 15 min: 1912 sessions on 100 accounts, backlog 14 late items (was 430+), sessions chose QueueUnits 57%, Transfer 29%, Raid 0%; missions launched: Transport 448, Expedition 27, Attack 4, Colonisation 3.
Raid and fight share is the open gap: attacks fell to 4 in this window.
