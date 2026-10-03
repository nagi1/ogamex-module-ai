# Handoff: local verifier loop

The module's state of play and work order live in `plan/HANDOFF.md`. This file is the contract with the
local verifier (it runs, it reports, it fixes nothing in planner code).

## Request

Written after the 2026-10-03 12:05 UTC results. Code only, none of it run in the cloud. Pull both repos first.

Pushed to module main this cycle:
- AUTH_UPTIME: a failed session's one-minute retry (`ProcessAiWork::scheduleSessionRecovery`) now takes the routine's next wake when the account is asleep, instead of firing in the dark period.
- LIFE_FIGHTS: `CandidateActionFactory` drops empty-planet raid candidates (rejection `defended_target_preferred`) whenever a defended target also clears the raid planner, so sessions fight when they can and farm only when nothing defended is viable.

Not changed: NAKED_BESIDE_WALLED (player 117). The planner already orders a wall for every bare sibling (`standingDefenceOrders`), so I could not find a code cause from the report alone.

Answer to the 12:14 UTC failure (accelerated claim test): not caused by 2a70aa5. The claim path has kept the night since 5da78a5, so the test only passes while the clock falls in the profile's waking window. The test now travels to the profile's local noon (tests/Feature/ProcessAiWorkTest.php). Please re-run ProcessAiWorkTest.
On the retry risk: keeping the night for a failed session in the accelerated cohort is intended and matches the claim path, so no accelerated exception was added.
Observation for the raid gap: Raid already scores resource_need 1.0 in `features`; the `why` output shows no Raid candidate at all, so the gap is upstream (reports `score_viable:false` or planner rejections). Item 4 below (`ai:raid-rejected:*` counters) is the number that decides the next fix.

Please run next:
1. Let the stack run at least two hours on the new code, then re-read `verify-cohorts`. AUTH_UPTIME reads a 7-day window, so players 96-99 may stay listed until old dark-period sessions age out. Report their per-hour session counts for the last 6 hours instead.
2. LIFE_FIGHTS: report attacks launched per hour and the share of new battles with combat rounds counted from battles created after the pull, not the 24h figure.
3. NAKED_BESIDE_WALLED: for player 117 report each planet's defence units, its unit queue, its shipyard level, its metal/crystal/deuterium stock, and the last 3 `QueueUnits` outcomes for it (state and refusal text).
4. Raid rejection reasons: dump the `ai:raid-rejected:*` cache counters.

## State of play for the cloud model (2026-10-03 12:12 UTC)

Read `AGENTS.md` (three gates) first. The cohort is `local-docker-dev/docker-compose.grand.yml` (db ogamex-grand, 100 AI
accounts). Tools: `bash scripts/ogamex pulse|scorecard|stuck|account PID|why PID|economy PID|prove CODE|test-one NAME`
(prefix `OGAMEX_RUNNER=local-docker-dev`). Never run tests on the shared lane 1 DB; `test-one` picks a private lane.

### What the cohort does now
- Scorecard 15/15 aspects pass on counts, but the day is lopsided: sessions choose QueueUnits 58%, Transfer 28%,
  Raid 0% (2 attacks in 15 min). `LIFE_FIGHTS` fails: 9% of battles have combat rounds, the rest pillage empty planets.
  `NAKED_BESIDE_WALLED` and `AUTH_UPTIME` fail too.
- Open gap number one: **raids do not win the arbitration** against Transfer/QueueUnits, so no real fights, no moons,
  no death stars. `CandidateActionFactory::features` is a fixed score table; read it against `bash scripts/ogamex why PID`.
- Already fixed this session (do not redo): dispatcher batch (`ai:run-due-work` defaults to the configured batch, 400);
  proactive saves used the routine's absence instead of the real next login (accelerated cohorts parked fleets every
  session); `RaidPlanner` priced fuel for the whole stock instead of the launch fleet; transfer planner offered ferries
  whose tanks cannot hold the fuel; `QueueAiTransferAction` loads biggest holds first; Rust battle engine only in
  `NativeRaidEstimator`; alliance ceiling removed; war-fleet role (`QueueableUnitPlanner::capitalFleet`).

### The harness (what runs, who does what)
- **DeepSeek writers** (`scripts/harness-live.sh`, `strategy-pipeline.py implement`): 1 writer, 1 model call at a time, high
  reasoning, 800k context. About 90% of rows. A row has a 250-call budget (`ROW_CALL_BUDGET`): past it the writer hands the
  row to Claude with the last failure (`hand_to_lane`, note `WRITER-HANDOFF`). Tests that fail on purpose to print state
  are refused. A failing live proof step settles 45 min before the writer gets the row back (`LIVE_SETTLE_SECONDS`).
- **Claude lane** (`scripts/claude-lane.py`, Sonnet 5.5, standalone `claude` binary): hard code only, one row at a time,
  no verification work, 100 turns, 45 min. Takes: handed-over rows, rows with 400+ writer calls, rows 2+ others wait on.
  Never FAST-/RULE-/STUCK-/WIK-/JEV- rows or QUAL-DEDUP. Two unsettled runs block the row for the owner.
- **Babysitter** (`scripts/babysit.py`, `babysitter.sh`): unsticks rows held 5 min, delegates to the lane, flags reopen churn,
  commits run records at most every 6 h. One agent in the tree at a time: writers wait while a Claude run is live.
- Page: https://ogamex-next.test/ai-harness (glance cards, spend, Claude lane panel). Spend since 1 Oct: DeepSeek about
  $13 / 650M tokens; Claude lane usage in `plan/research/ogame/claude-lane/usage.jsonl`.

### Ledger (python3 plan/tasks/task.py ...)
236 done, 19 todo, 160 deferred (P3, WIK and strategy-pipeline rows are frozen by the owner; do not unfreeze).
Open P0/P1: LIFE-001 (raids fight, held by the lane), LIFE-003 (moons, needs LIFE-001), QUAL-010 (AUTH_UPTIME), QUAL-013
(LIFE_FIGHTS), ALLY-001 (alliance lane, held by the lane), and STUCK-* rows (routine, for the writers).

### Rules the owner set
No caps, quotas or forced outcomes: progress must look like a human player. No hardcoded object names. Rust engine only.
Performance later. Commit by file name with the trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`;
never `git add -A`, stash, reset, clean or force-push. No `else`/`elseif`, `app()` not `new`, Pest Feature tests, no Mockery.

## Results

Cycle: 2026-10-03 12:14 UTC
Pull: ogamex-next f8122d8d, ogamex-module-ai 2a70aa5 (the cloud commit) merged under local ce7f94f; also pulled 09bf80c (Timeout reply agent double).
Stack: grand queue worker restarted onto the pulled code at this time. Harness (1 writer, 1 model call), babysitter and the claude lane running.
Request items 1-4 need two hours on the new code: not answered yet, they are due in the next cycle.

### Failing proofs
- **ProcessAiWorkTest::accelerated future sessions are claimable by the worker** (tests/Feature/ProcessAiWorkTest.php:469):
  expected the future-dated accelerated session to be Completed, got Pending. 27 of 28 tests in the file pass. It passed
  at about 11:50 UTC, before the pull. The only change to `ProcessAiWork` since is 2a70aa5 (`scheduleSessionRecovery` takes
  the routine's next wake when the account is asleep). I did not bisect it; it may also depend on the wall-clock hour.
- **Risk worth checking in that change:** the cohort runs accelerated (`ai.population.session_interval_seconds` = 5). A failed
  session there now waits for the routine's next wake, possibly hours, instead of one minute. If accelerated mode should
  keep the minute retry, the isAwake branch needs the same accelerated exception as the claim path (`acceleratedSession`).
  RaidDepthTest (30 tests) passes on the new `CandidateActionFactory`.

### Passing proofs
Nothing delivered awaiting a proof since 12:05 UTC. Earlier passes (PERS-002, PERS-007, FLEET-002, FLEET-003, the FAST-
self-checks) are in the ledger notes.

### Crashes, exceptions, stack traces
None in the 60 min before this cycle: queue worker, scheduler and app logs clean, failed_jobs 0.

### Scorecard aspects and invariants still failing
Scorecard 15 of 15 pass. Cohort invariants (read 12:05 UTC, before the cloud code): LIFE_FIGHTS 9% of battles with combat
rounds, NAKED_BESIDE_WALLED, AUTH_UPTIME. Pulse 15 min: 1019 sessions, QueueUnits 58%, Transfer 28%, Raid 0%, 2 attacks.
