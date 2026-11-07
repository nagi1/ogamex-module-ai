# AI module: agent instructions

A new agent reads two files: this one and `plan/WORK-ORDER.md`. Nothing else is binding; `plan/archive/` is history.

## North star (frozen for 90 days from 7 Nov 2026)

> A human plays OGameX on a normal-speed server against 20 AI accounts and says at the end of the session: these are
> real players, the best of them beat me, and I want to play again.

Measured by humans only, on the play card (`plan/PLAY-CARD.md`). Every simulator number is an instrument, not the goal.

## The ten rules (90 days, no changes; full text in `plan/CONSULTANT-GUIDE-2026-11-07.md` §3)

1. **One north star.** Any change is written down and deferred to the quarterly review.
2. **One brain, in PHP.** Behaviour lives in the module. The simulator keeps scripted players only as training opponents
   and a regression check; no behaviour enters it before it exists in PHP. A feature written twice stops until its home
   is decided.
3. **Training and tuning frozen for 60 days.** No PPO, ES, evolution, knob sweeps or search-as-player. Served: economy
   `best-es3-s10`; fleet errands fly on the scripted raider; social RL is cancelled. Reopens only when a play session
   named a weakness and a 15-minute simulator test showed ≥ 10% headroom.
4. **The arms die.** One reference-world file. Kept arms become the default and lose their flag; the rest are deleted.
   Under 10 flags; persona numbers locked.
5. **One page.** This file ≤ 80 lines, one work order ≤ 200 lines, one log (`plan/SPRINT-LOG.md`, one line per result
   with date and commit hash). No new plan, research or handoff files.
6. **The feedback loop is a human play session** with the five-question card. Questions 1 and 3 give the tells; the
   tells list, ranked by frequency, is the only backlog.
7. **Rules first.** Every new component is rules, judged by Rule 6. The economy model is the only learned component.
8. **The judge rejects noise mechanically.** It prints `NOISE` and no number when the CI crosses zero or the effect is
   below the MDE. ≥ 10 seeds; promotion seeds 11-60, quarantine 61-100 once. HDS is a smoke alarm: no drop over 0.05,
   top and mid cells only.
9. **One editor per file, nothing open overnight.** Lanes own disjoint files (table below). Everything is committed or
   discarded at day end; a result without a commit hash does not exist.
10. **No cheating, with a test.** Decisions use only what a real player can see. Every new decision path ships a test
    that changing a rival's hidden state does not change the decision.

## The three gates (full statement: `plan/details/specs/cognition-gates.md`)

- **Gate 1: no static, hardcoded AI.** Mods and modules add objects, so the object universe, kinds, prices and
  requirements are read from the host (`ObjectService`, `app/GameObjects/`) at planning time. Adding an object to the
  host makes it usable with no module edit. Code names no object; doctrine data under `resources/doctrine/` may (amended
  3 Oct), an unknown object is skipped, and every manager keeps its derived rule as the fallback.
- **Gate 2: relatively simple.** The smallest mechanism that closes the gap: one class, one loop, one sort key. No
  single-implementation abstraction, no config for a constant, no optimisation without a measurement, no forwarding
  layer. A slice deletes what it makes dead.
- **Gate 3: what a good professional OGame player does.** Every mechanism is nameable as ordinary experienced play
  (opening economy, prerequisites first, mining while short, a fleet save that can fail). Name it in the commit and test.

Gate 3 decides what the account does, Gate 1 how it is derived, Gate 2 how much machinery sits in between.

## Lanes (sprint 7-10 Nov 2026; detail and daily tasks in `plan/WORK-ORDER.md` §1)

| Lane | Owns (may edit) | Runs |
| --- | --- | --- |
| DOCS | `AGENTS.md`, `plan/**` | nothing |
| SIM | `rl/ogsim/**`, `rl/ogrl/**`, `rl/scripts/**`, `storage/rl/**` | cargo, python; never pest |
| PHP | `RaidPlanner`, `QueueableSpyPlanner`, `QueueableUnitPlanner`, `app/Domain/{Raid,Choice}/**`, `FleetSlots`, `resources/{behavior,doctrine}/**`, their tests, `config/rl.php` | pest via `bash scripts/ogamex test-one` (only lane) |
| WORLD | `local-docker-dev/**`, `scripts/ogamex`, `scripts/*.sh`, `app/Console/Commands/**`, `database/seeders/**`, `plan/PLAY-CARD.md` | docker, artisan on `grand` |
| ADVISOR | campaign consultation; conversation, reply and compose actions; `AdvanceAiAlliancePoliticsAction`; `config/{campaign-consultation,language,routing}.php`; `AiSettings`, `AiRuntimeSettings`, settings panel; `app/Domain/Advisor/**` | narrow tests only via `bash scripts/ogamex test-one <Name>`; full suite is PHP lane only |
| OWNER | host repo, merges, play sessions, decisions | everything |

Notes (owner additions, 7 Nov):
- **The play universe runs every model:** economy, fleet, the scripted raider, the engine-driven social teacher, the
  Empire UI, DeepSeek V4.1 Flash language (Flash only, Pro dropped) and the Jev classifier through its live API. All of
  it through config only; defaults stay unchanged.
- **ADVISOR** lets AI accounts optionally consult DeepSeek Flash in four areas: hard offence, diplomacy, conversation and
  costly actions. It runs outside the lanes; no lane touches its files.

Working rules: one worktree per lane (`../ai-<lane>`, branch `sprint/<lane>`); no subagents; commit per deliverable with
`git add <explicit files>`, never `-A`, stash, reset, clean or force-push; a file outside your lane is a need written in
the day report, never an edit; end-of-day report ≤ 8 lines appended to `plan/SPRINT-LOG.md`.

## Code and tests (unchanged house style)

- Module-first: never move AI policy into the host or restate a host rule. Logic in descriptive action classes; resolve
  with `app()`, never `new`. No `else`/`elseif`. Comments say why, in one or two lines.
- Pest 5 Feature tests on real models and services; no Mockery, no `tests/Unit`. `bash scripts/ogamex gate` clean.
