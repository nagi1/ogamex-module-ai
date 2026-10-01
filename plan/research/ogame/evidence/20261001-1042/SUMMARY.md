# Cohort evidence run — 2026-10-01

One-shot evidence collection on the machine that hosts the cohorts. Collect only: no code was
fixed, refactored or edited. Branch: `claude/friendly-hypatia-hve9xk` @ `41e0d19`
("prompts: one-shot cohort evidence run for the machine that hosts the cohorts").

## Step times (UTC)

| Step | When (UTC) | What |
|------|-----------|------|
| 0    | 10:42      | prepare: fetch, checkout branch @ 41e0d19, EVID folder `plan/research/ogame/evidence/20261001-1042` |
| 1    | 10:42–10:45 | baseline scorecards (grand/pve) + cohort verify (grand/pve) |
| 2    | **10:45:13** | **queue:restart on grand and pve (the "after" clock starts here)** |
| 3    | 10:45–10:48 | test-one x2, full module test, gate, versions |
| 4    | 10:48–11:08 | situation list + situation all (grand, pve) |
| 5    | 11:09–11:14 | harness status, self-check, model-check, log tail, attempts, agents |
| 6    | 11:14       | host facts (read-only) |
| 7    | 11:15       | cohort SQL (grand, pve) |
| 8    | 11:47–11:52 | after-scorecards (grand/pve) + prove ECON-001 (60 min after step 2) |
| 9    | 11:52+      | SUMMARY.md, commit, push, restart harness |

## Step -> verdict

| Step | Verdict | One-line reason |
|------|---------|-----------------|
| 0    | PASS    | clean tree, no harness running, checked out target branch |
| 1    | PASS*   | scorecards run; cohort verify FAILs (see below) |
| 2    | PASS    | queue restart broadcast on grand + pve |
| 3    | FAIL    | test-one x2 PASS, gate PASS, but full module test cannot collect (FilesystemIterator) |
| 4    | FAIL    | situations run but hang/minutes-per-scenario while cohorts are live |
| 5    | PASS    | all harness/DeepSeek commands ran |
| 6    | PASS    | host facts collected (read-only) |
| 7    | PASS    | cohort SQL collected for grand + pve |
| 8    | FAIL    | after-scorecards ran; ECON-001 proof still FAIL (IDLE_QUEUES + every-planet-builds) |
| 9    | PASS    | commit + push + restart harness |

\* "PASS" means the evidence command ran; the behaviour it measured is FAIL (see notes).

## Errors verbatim

### Full module test (`bash scripts/ogamex test`) — collection aborts the whole suite
```
No stale test sessions to reap.
{"tool":"pest","raw":["ErrorException","The use statement with non-compound name
 'FilesystemIterator' has no effect","at Modules/AI/tests/Feature/Ai/TurtleDefen
ceStaleThresholdsTest.php:3","1 <?php","2","3 use FilesystemIterator;","4 use Re
cursiveDirectoryIterator;","5 use RecursiveIteratorIterator;","6","7 /*","8 * Th
e AI module must not treat the older defence/IPM tallies as current doctrine. Th
e source","9 * documents a turtle-defence figure of 37 and an anti-ballistic tot
al of 100 or more as"]}
```
No `Tests:` summary line: the suite is uncollectable, so the whole run reports nothing.

### `situation all` (pve) — timed out
```
cohort: 2 enabled account(s), no provider call made (safe in a peak window)
SCENARIO: PASS idle-planet
    ... (ticks) ...
Terminated
exit=124
```
Grand `situation all` was killed manually after 16 min (still mid inbound-attack); see
`step4-grand-situation-hang.txt`.

## What was noticed but NOT touched

1. **Situation tool is not "seconds" while cohorts are live.** Each scenario took ~5 minutes
   because the synchronous `ai:run-due-work` blocks on locks held by the live queue workers
   (observed ~3 Redis GET/s on the cache connection = a `Lock::block()` retry loop). Not a hard
   deadlock — the loop progresses — but `situation all` does not complete in seconds.
2. **Leftover planted row (not cleaned up):** `fleet_missions` id 30088 (mission_type 1, user 13 ->
   planet 19, 40 LF / 15 cruiser / 20 SC) planted by the `inbound-attack` scenario; its `--cleanup`
   never ran because the process was killed. Left in place, reported only.
   (`chat_messages` 387 planted by `inbound-message` was correctly soft-deleted by its own cleanup.)
3. **Laravel log spam:** `storage/logs/laravel-2026-10-01.log` (grand) has 20,740 lines, of which
   19,518 are the same warning since 00:00:00:
   `Queue driver "redis" does not support fleet arrival job tracking. Delayed arrival jobs are still
   dispatched, but mission updates cannot reuse existing jobs and recalls cannot delete stale ones,
   so duplicate jobs may run. This is harmless ... but wasteful. Use QUEUE_CONNECTION=database ...`
4. **Harness is NOT running** (pgrep empty at step 0; `/tmp/harness-live.log` last write 10:23 UTC,
   last line "LIVE VERIFICATION FAILED ... the accounts did not play"). It must be restarted in step 9.
5. **A social reply appeared after the kill:** `chat_messages` 388, sender 12 -> recipient 13,
   "still here. :)" at 11:05:07 UTC — the planted inbound-message produced a reply, yet the scenario
   read-back recorded "0 social exchange(s)".
6. **Recent attempt logs (10 newest)** show recurring failures, e.g. missing
   `resources/behavior/planet_temperature.php` (PlanetTemperatureTest, QUAL-5), undefined methods
   (GrowthStallReactionTest, ViolentSpecialityTest, AiAllianceLifeExitTest), and "files are not
   called by any runtime code" refusals (WIK-242, TP-022, WIK-233, FOR-013). See `17-recent-attempts.txt`.
7. **Navigation note:** `cd Modules/AI` in step 0.1 failed with
   `bash: cd: Modules/AI: No such file or directory` because the shell was already inside `Modules/AI`.

## Key numbers (grand / pve)

- Baselines: grand **7/15** aspects pass (FAIL espionage raids recycle fleet_save social chat
  alliance fleet_breadth); pve **9/15** (FAIL espionage raids fleet_save social chat alliance).
- Cohort verify: grand FAIL — 152 violations across NAKED_BESIDE_WALLED, WALL_CEILING,
  ALLIANCE_SHARE, IDLE_QUEUES; pve FAIL — NAKED_BESIDE_WALLED, WALL_CEILING, IDLE_QUEUES.
- Situations: grand `idle-planet` PASS, `every-planet-builds` FAIL (1 of 12 planets built),
  `inbound-message` FAIL (0 social exchanges); pve `idle-planet` PASS then timed out.
- Enabled profiles: grand 20, pve 19. Planets/account: grand 4..9.95..13, pve 1..8.89..14.
- Harness: 0 code rows proven; 20 ready; ECON-001 / HARNESS-004 / QUAL-6 delivered but not proven.
- After (1h): grand **10/15** (FAIL espionage recycle colonisation alliance fleet_breadth); pve **8/15**
  (FAIL espionage raids recycle fleet_save social chat alliance).
- `prove ECON-001`: **FAIL** — ExecuteIntentTest 15/15 PASS, AiCapabilityPublicationTest 17/17 PASS,
  situation `every-planet-builds` FAIL (1 of 12 planets, 190.4s), aspect `economy` PASS (287),
  invariant `IDLE_QUEUES` FAIL (160 violations: NAKED_BESIDE_WALLED WALL_CEILING ALLIANCE_SHARE IDLE_QUEUES).
