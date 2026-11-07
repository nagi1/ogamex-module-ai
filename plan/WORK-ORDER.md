# Work order: 3-day sprint (8-10 November 2026)

This is the only work order. Background and rules: `plan/CONSULTANT-GUIDE-2026-11-07.md`. If anything older
disagrees with this file, this file wins.

**What the sprint delivers:** a clean module, one reference world, the proven war-world behaviours living in PHP, a
playable 1000x universe, and two human play sessions with a ranked list of tells. That is week 1 and the first half of
week 2 of the guide. Nothing else is in scope. No training, no new arms, no new plan files.

---

## 1. Lanes: four agents, disjoint files, one owner

An agent owns the files in its lane and nothing else. If a task needs a file outside the lane, the agent stops that
task, writes the need in its end-of-day report, and moves to the next task. It never edits the file.

| Lane | Agent | Owns (may edit) | Never touches | Runs |
| --- | --- | --- | --- | --- |
| **DOCS** | 1 | `AGENTS.md`, `plan/**` (this file included) | any code, tests, `resources/` | nothing |
| **SIM** | 1 | `rl/ogsim/**`, `rl/ogrl/**`, `rl/scripts/**`, `storage/rl/**` | `app/`, `tests/`, `resources/` | `cargo`, python; never pest |
| **PHP** | 1 | `app/Domain/Decision/RaidPlanner.php`, `app/Domain/Decision/QueueableSpyPlanner.php`, `app/Domain/Decision/QueueableUnitPlanner.php`, `app/Domain/Raid/**`, `app/Domain/Login/FleetSlots.php`, `app/Domain/Choice/**`, `resources/behavior/**`, `resources/doctrine/**`, module `tests/**` for those classes, `config/rl.php` | `rl/`, `plan/`, `local-docker-dev/`, host `app/Services/**` | pest through `bash scripts/ogamex test-one`, the only lane that runs pest |
| **WORLD** | 1 | `local-docker-dev/**`, `scripts/ogamex`, `scripts/*.sh`, `app/Console/Commands/**`, `database/seeders/**` | `app/Domain/**`, `rl/`, `plan/` except `plan/PLAY-CARD.md` | docker, artisan on the `grand` universe |
| **OWNER** | you | host repo (`app/Services/PlayerService.php` newbie bands), merges, play sessions, decisions | | everything |
| **ADVISOR** | 1, outside the lanes | campaign consultation; the conversation, reply and compose actions; `AdvanceAiAlliancePoliticsAction`; `config/{campaign-consultation,language,routing}.php`; `AiSettings`, `AiRuntimeSettings` and the settings panel; `app/Domain/Advisor/**` | every lane's files | set by the owner |

Lane notes (owner additions, 7 Nov):
- **The play universe runs every model:** economy, fleet, the scripted raider, the engine-driven social teacher, the
  Empire UI, DeepSeek V4.1 Flash language (Flash only, Pro dropped) and the Jev classifier through its live API. All of
  it through config only; defaults stay unchanged.
- **A separate ADVISOR agent runs outside the lanes.** It lets AI accounts optionally consult DeepSeek Flash in four
  areas: hard offence, diplomacy, conversation and costly actions. No lane touches its files.

Mechanics that make this hold:
1. **One worktree per lane.** `git worktree add ../ai-<lane> -b sprint/<lane>` from `Modules/AI`. An agent works only in
   its worktree. The owner merges the four branches into `main` at the end of each day, in the order DOCS, SIM, PHP,
   WORLD (PHP and WORLD cannot conflict by construction; DOCS and SIM cannot either).
2. **Commit per deliverable,** `git add <explicit files>`. Never `-A`, `stash`, `reset`, `clean`, force-push.
   The commit message names the behaviour or the tell ("a raider uses every fleet slot it has").
3. **The PHP test lane is single.** `scripts/ogamex` already serialises pest on the shared database through
   `with_test_lane`. Only the PHP lane runs pest. The full module suite runs once per day, by the PHP lane, before the
   owner merges.
4. **No subagents.** Each lane is one session. A lane that wants to fan out writes the sub-tasks in its report instead.
5. **End-of-day report, ≤ 8 lines per lane,** appended to `plan/SPRINT-LOG.md`: what landed (commit hash), what is
   blocked and on which file, what is next. Nothing uncommitted at the end of the day.

---

## 2. Day 1 (8 Nov): freeze and clean

| Lane | Tasks | Done when |
| --- | --- | --- |
| DOCS | (a) Rewrite `AGENTS.md` to ≤ 80 lines: the north-star sentence, the ten rules, the three gates, the lane table. (b) Move every file under `plan/` except `WORK-ORDER.md`, `CONSULTANT-GUIDE-2026-11-07.md`, `SPRINT-LOG.md`, `PLAY-CARD.md`, `rl/STRONG-PLAYER-PLAN.md` (the log) and `details/specs/cognition-gates.md` into `plan/archive/` with `git mv`. (c) Write `plan/PLAY-CARD.md`: the five questions, one row per AI account for "was this a bot, and how". | `AGENTS.md` ≤ 80 lines; `find plan -maxdepth 1` shows ≤ 8 entries; card exists |
| SIM | (a) Audit the arms: one table in the commit message, arm → kept / dropped, from the log. Kept arms become the default and the flag is deleted; dropped arms are deleted with their code. Target ≤ 10 flags. (b) One reference-world file (`storage/rl/ogsim/world.json` or the existing catalogue): churn 0:1, personas, alliances, the kept arms. `season.rs` reads it; no arm string on the command line. (c) `paired_sides.py` and `reality.py` print `NOISE` and no number when the CI crosses zero or the effect < MDE. | `cargo test` green; `grep -c has_arm` under 10; a 10-seed, 60-day season of the reference world runs in ≤ 15 min and its HDS/reality numbers are recorded as the baseline in `SPRINT-LOG.md` |
| PHP | Port, one commit each, each with a `situation` test: (1) real fleet slots in `RaidPlanner` (every slot the host grants, not a fixed 4); (2) every planet and moon is a target candidate, with the bashing limit of 6 per body per 24 h; (3) probe stock: flying probes do not count as idle, probe orders are exempt from the building saving. | Each commit's situation test passes through `ogamex test-one`; `ogamex gate` clean |
| WORLD | (a) Reseed `grand` at 1000x: one human account, 20 AI accounts, persona mix 8 miners / 7 raiders / 5 savers, spread over 3 galaxies, no inactive neighbours (owner rule: no farms). (b) Switch the fleet errand choice to the scripted raider (`config/rl.php` is PHP lane: ask PHP lane to flip it, or the owner). (c) Stop every `rl-soc-*` unit and the social RL job. (d) Verify with `ogamex pulse 30` that AI accounts log in, build, and the raiders send probes. | A human can log in and see 20 live accounts; pulse shows probes within the first 2 h |
| OWNER | Add the newbie-protection bands in the host (`PlayerService::protectsNewbie`) if not yet committed to the host repo; merge the four branches in the evening. | host test suite green; `main` holds all four lanes |

---

## 3. Day 2 (9 Nov): finish the port, first play session

| Lane | Tasks | Done when |
| --- | --- | --- |
| DOCS | (a) Read every PHP commit from day 1 and 2; each must name a player behaviour. One that does not gets a one-line note in the log for the PHP lane to fix. (b) Archive the plan files the owner names in the morning. | log updated |
| SIM | (a) Run the regression on the reference world after the owner's day-1 merge; record HDS, raids/day, r1/r10 beside the day-1 baseline. (b) Delete `train_world.py`, `evolve.py`, `teacher_es.py` and the ES/evolve run scripts (Rule 3: frozen 60 days; git keeps them). (c) Stop: no other change. | regression within 0.05 HDS of baseline, or the gap named with the mechanic |
| PHP | Port, one commit each, with a situation test: (4) newbie protection bands used by target selection (`score_viable` reads the host rule); (5) attack under uncertainty: `ReportedPlanet::estimated` fills the hidden section from public military points, the fight is forecast on the estimate, loss tolerance decides; (6) re-scout: a redacted fresh report on a rich or defended body gets the next larger volley. Then run the full module suite once. | 6 situation tests green; full suite green or every failure named with the file |
| WORLD | (a) Run the cohort for 4 h on the day-1 merge; read `ogamex pulse`, `ogamex account` on three raiders and two savers; confirm probes, raids, and at least one fleet save happen. (b) Anything that does not happen is a **blocker**, written in the log with the account id and the `ogamex why` output; no fix in `app/Domain` from this lane. | pulse shows probes, raids, saves; blockers listed |
| OWNER | Evening: **play session 1**, one hour, fill `PLAY-CARD.md`. Merge in the evening. | card filled; tells list v1 in `SPRINT-LOG.md` |

---

## 4. Day 3 (10 Nov): second session, first tells fixed

| Lane | Tasks | Done when |
| --- | --- | --- |
| OWNER | Morning: **play session 2**, one hour, card again. Rank the tells by how many sessions and accounts showed them. Hand the top two to the PHP lane, in writing, each as "an observer saw account X do Y; a human would have done Z". | tells list v2, ranked |
| PHP | Fix tell 1, then tell 2, as rules in PHP, each with a situation test that plants the exact state the owner saw. Full suite once at the end. | two commits, both situation tests green, suite green |
| SIM | Regression after each PHP merge (the scripted raider in PHP has no simulator twin yet; the regression only guards the economy model and the world). Record. | within 0.05 HDS |
| WORLD | Rerun the cohort 4 h on the day-3 merge; confirm the two tells no longer appear in `pulse` or `account`. | confirmed or the remaining tell is logged |
| DOCS | Write the sprint close in `SPRINT-LOG.md`: what landed, the baseline numbers, the tells list, what is open. Propose the next 2-week cycle in ≤ 20 lines at the end of this file. | the log reads start to finish in 5 minutes |

Evening of day 3: the owner merges, tags `sprint-1`, and reads the card. The card is the result of the sprint.

---

## 5. Sprint exit (all must hold)

- `AGENTS.md` ≤ 80 lines; `plan/` has ≤ 8 top-level entries; nothing uncommitted.
- Simulator flags ≤ 10; one reference-world file; judge scripts print NOISE on noise; training scripts deleted.
- Six war-world behaviours live in PHP with situation tests; fleet errands fly on the scripted raider; module suite green.
- A 1000x universe where 20 AI accounts probe, raid and save against one human.
- Two filled play cards and a ranked tells list, with the top two tells fixed and re-tested.

## 6. Out of scope (do not start, even if idle)

Training or tuning of any kind, new simulator arms, social RL, diplomacy, new plan or research files, lifeforms,
expedition changes, HDS work on the bottom cells, performance work on the simulator. An idle lane re-reads its own
commits for Rule 10 (the unseen-state test) and adds the test where it is missing.
