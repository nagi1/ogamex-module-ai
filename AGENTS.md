# Nagi — AI module agent instructions

The base contract for all work in `Modules/AI`, for every agent: the harness writer, DeepSeek agents,
Copilot and the reviewer. Where an older file says otherwise, this file wins; where this file and
`plan/HANDOFF.md` disagree on state or order, the handoff is newer.

## Direction — 1 October 2026 (overrides any older phase or milestone instruction)

State of play, work order and owner actions: `plan/HANDOFF.md`. Read it before taking a row.

The accounts did not play: no raids, one fleet save in a lifetime, no recyclers, no applications, no
social exchanges (`plan/details/specs/play-coverage.md`). Until a cohort read shows every aspect of
play moving, the work is making the existing engine play, not adding to it.

- **Work order.** Take rows only from `python3 plan/tasks/task.py next` (P0 → P1 → P2, on the north-star
  path). Every P3 row, the `WIK-*` corpus, `JEV-*`, the strategy-pipeline PHP rows and new cognition
  work are **frozen** (`deferred`, reason in the row). Do not unfreeze one without the owner.
- **North-star gate.** A code row's proof must name an `aspect:`, `situation:` or `invariant:` step
  (or `harness:` for the loop itself); the ledger, the harness and the writer refuse anything else.
  Before touching a row, say which aspect it moves and what an observer would see the account do.
- **Behaviour before sources.** A slice starts from a step of the player's day and a situation that
  proves it (`scripts/cohort-scenario.php`), never from a wiki page. New strategy is a YAML edit under
  `resources/behavior/` that an existing planner reads; a new class needs a step no planner owns.
- **Shrink before grow.** A class or data file nothing calls is deleted or wired before anything is
  added beside it.
- **Done means proven.** `task.py done CODE` runs the row's proof and refuses on a failure. A green
  test alone is not done. Tools: `bash scripts/ogamex scorecard | situation NAME | economy PLAYER |
  prove CODE | test-one Name`. The workflow is `.github/skills/ai-task-execute/SKILL.md`; follow it.
- **Lanes.** The harness implements `impl` rows at P0–P2 that name a `file_ref`; rows with an empty
  `file_ref` belong to the strong lane (the owner or the reviewer). Wiki ingestion runs only with
  `HARNESS_INGEST=1`.
- **Agents never share a file.** `task.py claim` locks the row and its files, the harness respects
  those locks, tests queue on one lane through `scripts/ogamex`, and commits name their files
  (`git add <files>`; never `-A`, `stash`, `reset`, `clean` or a force-push).

## Learned economy policy (plan/rl)

Work on training data, the choice seam (`app/Domain/Choice`), `rl/` (Python) or the `ai:sim` RL options follows
`.github/skills/ogame-rl-training/SKILL.md`. Its rules (host stays the referee, no object identity as a feature,
seeded runs, teacher path untouched) are binding in addition to the gates below.

## The three gates — non-negotiable

Every AI slice is checked against these before it is accepted. They are design constraints, not
preferences. The full statement, including what each gate forbids and how a reviewer checks it, is in
`plan/details/specs/cognition-gates.md`.

- **Gate 1 — no static, hardcoded AI.** Mods, modules and future extensions add buildings, ships,
  defence, technologies and premium officers, so the object universe, its kinds, prices and
  requirements are read from the host at planning time and are never encoded as a source of truth in
  module code or config. Adding an object to the host must make it usable by the existing module code
  with no module edit. Module policy may express *taste* over host data; it must never be the reason a
  capability is reachable or unreachable.
  **Amended 3 Oct 2026 (owner, architecture diagnosis section 6):** doctrine *data* under
  `resources/doctrine/` may name buildings, ships, defences and research (opening build orders, research
  paths, fleet and defence templates), the way RTS AIs ship build lists. Code still names nothing, an
  object a doctrine file does not know (or the host does not have) is skipped, and every manager keeps
  its generic derived rule as the fallback, so a modded object stays reachable with no edit.
- **Gate 2 — relatively simple, never over-engineered.** Take the smallest mechanism that closes the
  gap: one class, one loop, one sort key. No abstraction with a single implementation, no config for a
  value that never varies, no optimisation without a measurement, no layer that only forwards, and no
  design that needs a paragraph to justify each of its parts. Delete what a slice makes dead. Enforced
  by the standing gate in `plan/details/specs/overengineering-gate.md`, the Gate 2 reviewer agent, and
  `bash scripts/ogamex gate`.
- **Gate 3 — what a good professional OGame player does.** Fifteen years of ordinary play is the
  reference behaviour, not an efficient game of our own: the opening economy and the facilities that
  unlock the rest, prerequisites before the thing they unlock, the easiest unlock before the largest
  one reachable, mining while short, and a fleet save that can also fail. Every mechanism must be
  nameable as something an experienced player does; if it cannot be named, it is not ready.

When they conflict: gate 3 decides what the account does, gate 1 decides how it is derived, and gate 2

## Decision baseline

Check these before any material design choice. A choice that fails one is not made.

- **The goal** is accounts a human player cannot distinguish from other humans in ordinary play,
  measured by what a player can observe — reaction latency to a probe or attack and whether a save ever
  fails, the shape of the uptime across the day, the public hourly growth curve, the self-similarity of
  the action sequence, and the breadth of social contact — never by message polish. Read
  `plan/details/research/account-authenticity.md` and `plan/details/research/player-personas.md` before
  designing anything behavioural.
- **Deterministic play, scarce models.** Ordinary gameplay, event and memory processing, structured
  case retrieval and AI-to-AI exchanges make zero generative calls; thousands of accounts must run on
  rules. Authored dialogue comes before any optional language-model reply.
- **The reference deployment** is a small VPS (2 vCPU, 2 GB RAM, no GPU) already running the app, the
  queue workers, the database and Redis. Owner direction 16 Sep 2026: build the strongest account
  first and optimise later; the profile is an optimisation target, not a gate (`plan/details/specs/budgets.md`).
- **Memory** is adopted as mechanisms (decay from last access, weighted retrieval, write-time
  importance from authored rules, citation pointers, validity windows, selective forgetting), never as
  a product that pays for memory with generative calls (`plan/details/research/agent-memory-tooling.md`).

## Code

- Module-first: reuse OGameX extension points; never move AI policy, persistence or orchestration into
  the host, and never restate a host rule the host already enforces.
- Business logic lives in descriptive action classes. No forwarding-only methods or wrappers.
- Resolve module actions, jobs, services, policies, drivers and domain payloads with `app()` or
  `app()->makeWith()`, never `new`; bind defaults in `AIServiceProvider`. Laravel's anonymous migration
  classes are the only exception.
- Never write `else`, `else if` or `elseif`: early returns, lookup tables, strategies or `match`.
- Small single-purpose methods, descriptive names, practical immutability, shallow control flow,
  explicit failure handling. Enums for stable domain values.
- No new dependency, abstraction or refactor without a demonstrated module need and, when material,
  the owner's approval. Optimise only from a measurement.
- Before changing a feature, read the comparable module and host code and the real schema; follow the
  pattern there. Consult the official Filament v5 documentation before changing a Filament component.
- Comments: one or two lines on *why* — a non-obvious invariant, a boundary, a trade-off, or why the
  simpler-looking version is unsafe. Never narrate syntax; incident history goes in the commit message.

## Tests and verification

- Pest 5, native syntax, named datasets for repeated scenarios, Feature tests that drive the real
  path (`tests/Unit` is not accepted). PAO and PCOV; never Xdebug. Mockery is prohibited; a narrow
  container override is allowed only for an explicitly replaceable seam, named and justified.
- Real OGameX models, services, database state, queues, locks and validation paths. Container work runs
  only in `/home/nagi/code/ogamex-next/local-docker-dev`.
- Every row: its own test and the tests naming the classes it touched (`bash scripts/ogamex test-one`),
  `bash scripts/ogamex gate` clean, then its proof. Before a merge to `main`: the whole module suite
  (`bash scripts/ogamex test`); Pint, module PHPStan, Rector dry-run and the 100% PCOV coverage pass
  (`quality`, `coverage`) run when the owner asks for them.

## Cognition drivers (frozen work; the rules still bind any change that touches them)

- `plan/details/specs/phase-3-cognition.md` and `plan/details/research/phase-3-current-state.md` say
  what shipped versus what was only proposed.
- Never duplicate a capability a supported driver provides (FAtiMA/CiF, CBRKit, AgentOS, PsychSim): no
  ported algorithm, no second implementation to compare against. Around a driver the module owns only
  scope, attribution, permission, current-validity, validation, budgets, persistence, failure mapping
  and wire translation; its proposals carry the weight ordinary module policy gives them.
- The native engines (`NativeAffectEngine`, `NativeExperienceEngine`, `NativeSocialCognition`) stay the
  default and the fallback. Drivers sit behind small module-owned contracts; no standalone framework,
  generic planner or second player runtime.
- Semantic retrieval, ML compression, Theory of Mind, strategic advice and deferred model batches need
  their documented activation gates. Mem0 is rejected. Validate libraries and performance through
  pinned real-adapter experiments, never claims.
