# The plan: believable players for OGameX Next

**This is the only entry point.** Read this page, then open only the work package assigned to you. Do not browse the reference library unless the package tells you to.

The untouched source plan is preserved at [details/reference/raw-original-plan.md](details/reference/raw-original-plan.md). It is input material, not implementation instructions.

## What we are building

An extension of the existing AI module that makes quiet servers feel alive. Its accounts build, compete, cooperate, make mistakes and remember what happened to them.

There are two modes:

- **Normal mode:** varied AI players live alongside humans as opponents, allies and trading partners.
- **Cooperative PvE mode:** human alliances unite against an AI Empire in a separate universe. It uses the same AI module.

The goal is enjoyable play for humans. We increase the population only when the experience and server performance support it.

## Current implementation status

**Phases 1–2 are recorded in module commit `e1c48a2`. Phase 3 slices 3A–3M are implemented and committed: 3K wires the conversation path into the session so an account answers a real message in ordinary play, 3L offers a substantive reply to the optional provider on its own lane, and 3M publishes only the capabilities the module can execute and queues a real building from the chosen intent. 3J is complete apart from the scale runs: by owner decision they stay at the very end of the phase, rescaled on 14 September 2026 from 100/500/1,000 players to 2/5/10 accounts, and a 10-account pilot stands in for them — that pilot has run and the cohort now acts, as recorded below.** The module has profiles, leased/idempotent work, a normal validated building adapter, legal perception, seeded archetype policies, session schedules and decision traces.

Phase 3 adds source-backed facts, claims, relationships, commitments and affect, typed zero-LLM social protocols with authored replies, outcome-based experience cases, a sealed host delivery ledger, bounded context selection and atomic daily usage ledgers. The optional language slice is implemented behind `LanguageGateway` with a disabled default and the pinned `laravel/ai` SDK: normal gameplay, structured exchanges and AI-to-AI replies still make no generative call, and the provider is reached only by a substantive reply on an enabled lane or by the opt-in sanitized conformance command, which has now been run for real against `deepseek-flash` with 4/4 cases matching and the injection case refused. FAtiMA/CiF behind `AffectEngine`/`SocialCognition`, CBRKit behind `ExperienceEngine` and AgentOS behind the memory driver are implemented and opt-in but disabled by default, because none of the three has cleared Gate 2; PsychSim, embeddings and the scale runs (2/5/10 accounts) are not implemented, and a session choice executes only where an executor exists: `build` queues a real building through the ordinary host action path, while `build` and `research` execute and `queue_units` can queue a first cargo hull; `save_resources`, `spy`, `colonize`, `fleet_save` and `raid` remain recorded intents. **The 10-account pilot of 14 September 2026 is the evidence for both halves:** its first run showed ten sessions deciding end to end with zero provider requests and every one choosing `DoNothing`, because the ordinary-play observation published no ability; slice 3M closed that, and the cohort's next sessions queued real buildings — player 29129 a solar plant, player 29132 a crystal mine — for `actions: Accepted 2` where the window previously read `none`. **Package 5's hybrid integration is implemented and measured but not signed off; Package 6 (cooperative PvE) remains blocked until Packages 1–5 are fully complete with every gate closed; Package 7 is the evidence-gated strategic and social follow-on after Package 6 is signed and reviewed.** See the [current-state assessment](details/research/phase-3-current-state.md) for the pre-Phase-3 snapshot, integration limitations and corrections to older diagrams. **A goal-level audit of what is still missing is in the [gap register](details/GAP-REGISTER.md)**, which records eighteen gaps against the eleven authenticity signals and the four planning failures behind them. It supersedes any "implemented" claim in this file that does not name the mechanism producing an observable.

## Decisions already made

- **The three cognition gates are non-negotiable, and they outrank every other decision on this page.** (1) **No static, hardcoded AI** — the object universe, its kinds, prices and requirements are read from the host at planning time, because mods, modules and future extensions add buildings, ships, defence, technologies and premium officers; an object id, machine name or requirement may not be a source of truth in module code or config, and adding an object to the host must make it usable with no module edit. (2) **Relatively simple, never over-engineered** — the smallest mechanism that closes the gap, and a slice deletes what it makes dead. (3) **What a good professional OGame player does** — fifteen years of ordinary play is the reference, not an efficient game of our own, and every mechanism must be nameable as something an experienced player does. Gate 3 decides what the account does, gate 1 decides how it is derived, gate 2 decides how much machinery is allowed in between. Full statement: [cognition gates](details/specs/cognition-gates.md).
- Use the existing OGameX Next module system. AI behavior stays in the AI module.
- The game remains responsible for rules, costs and execution. Add small shared extension points where the module needs them.
- Ordinary gameplay and game-event memory use algorithms and database records, with **zero language-model tokens**.
- Use authored social dialogue before selectively escalating human language to an LLM through the first-party Laravel AI SDK. Include validated extraction proposals in the same request.
- Add native affect/social cognition and structured experience behind small Laravel-bound module contracts. FAtiMA/CiF and CBRKit are candidates, not prerequisites.
- Keep advanced recall optional. AgentOS memory-only is implemented and opt-in behind the recall driver, still disabled pending Gate 2; Mem0 is rejected. Embeddings, PsychSim and ML compression require their own evidence.
- Keep OGame-specific competence outside cognitive contracts. Prove real driver swaps in this module before considering a standalone framework.
- Give accounts different skills, routines and priorities. They use legal information, suffer real losses and rebuild normally.
- The goal is accounts a human player cannot distinguish from other humans in ordinary play, and authenticity is measured by what a player can observe: reaction latency and whether a save ever fails, the shape of the day, the public hourly growth curve, the self-similarity of the action sequence and the breadth of social contact. See [account authenticity](details/research/account-authenticity.md) and [player personas](details/research/player-personas.md).
- The reference deployment profile is a small VPS with **2 vCPU, 2 GB RAM and no GPU**, already running the app, workers and database. Native cognition is the only path that fits it; sidecar drivers are opt-in for hosts with measured headroom. See [operating budgets](details/specs/budgets.md#reference-deployment-profile).
- Keep the memory write path **zero-generative**, and adopt mechanisms rather than products: every surveyed memory product needs an LLM on the ingestion path. See [agent memory tooling](details/research/agent-memory-tooling.md).

## Build it in five phases

### 1. Connect the existing module — complete

Let a module-controlled account read the information it is allowed to see and perform normal game actions safely. Complete missing game notifications and validation connections as needed.

**Done when:** one account can queue a building through the normal game rules, and retrying the work cannot queue it twice.

### 2. Make believable players — deterministic slice complete

First add growth, research, sessions and fleetsaving. Then add colonies, spying, selective raids, losses and recovery. Mix miners, casual players, traders and fleeters.

**Done when:** a small group can play through a simulated month with sensible routines and real consequences, using no language model.

### 3. Add social cognition, experience and conversation — deterministic slice complete

Remember rivals, favors, claims and agreements. Add goal-aware affect, CiF-style social protocols, outcome-based CBR, authored dialogue, delayed/coalesced replies and optional human-language generation. The LLM remains a language specialist with no gameplay authority.

**Done when:** real feature scenarios prove memory, social consequences, outcome learning and permission-checked conversation across sessions; native behavior works with optional services absent; enabled drivers pass conformance/swap and budget checks.

Read the [detailed Phase 3 architecture](details/specs/phase-3-cognition.md), including eight end-to-end flows, minimal contracts, failure behavior, small delivery slices and the final diagram. The [Laravel AI SDK integration](details/specs/laravel-ai-sdk.md) specifies the 3H language adapter and its boundaries. The [decision history](details/DECISIONS.md) explains superseded technologies; [validation](details/specs/validation.md) and [evaluation](details/research/memory-comparison.md) define evidence still needed. Rare strategic advice is a disabled later experiment, not a baseline task or periodic call.

### 4. Test with humans, then grow

Run a small disclosed pilot. Check whether opponents are interesting, cooperation is useful and losses remain manageable. Measure cost and performance before increasing account numbers.

**Done when:** player feedback supports continuing and the population stays within tested operating limits.

### 5. Add cooperative PvE

Reuse the same accounts and policies under an AI faction coordinator. Give human alliances shared campaign goals, useful support roles and enforced protection from human-on-human hostility.

**Done when:** a coalition can complete a campaign, recover from a failed attempt and play without unintended human PvP.

This is a committed separate phase, not a second AI engine. Its design draws on researched cooperation mechanics; the exact adaptation still needs testing.

## After it works: read the results and improve

A module that runs is not yet a module that played well, and the only way to tell is to read what it produced. Once the accounts are playing, every closed slice and every pilot window ends with a **review**: the decision traces, work receipts, stop counters, pilot report, the account's public state and the human feedback are read together and answered against the goal — did the account reach the next stage of the chain, is the growth explicable by visible behaviour, does the cohort diverge, does it react like a player under pressure, is the server more alive. Findings become register rows, then a named algorithm, then the smallest slice, with the before and after measured on a frozen clock. A shortcoming is fixed in the mechanism — or in the plan, when the plan was what failed — never by moving an acceptance wording, hardcoding a per-account value or adding a layer. Reading a window is itself cheap: one bounded pass, structured output a script can diff, counters aggregated where the row is written, and no model anywhere in the read path. It is also invisible to play — nothing in it runs inside a session or a job, its one added write is a scheduled hourly sample whose cost is measured before it ships — and one switch, `ai.review.enabled`, is on by default and turns that collection off without touching the records operability already depends on. Records live in [reviews](details/reviews/) and the rules are [the review loop](details/specs/improvement-loop.md).

## Working with multiple agents

Use [WORK-PACKAGES.md](WORK-PACKAGES.md). It gives each agent an exclusive scope, dependencies, allowed paths, output and handoff format. Do not assign two agents to the same package. An agent may inspect another package but must not edit its files or implement its work. The coordinator integrates package pull requests in order.

**Current work: Package 6, with the wave-8 ordinary-play defects registered beside it.** Packages 1–5 are complete and signed (Package 5 signed 16 September 2026), and the 2/5/10-account runs, the disclosed human pilot and the operator feedback file are reclassified to run alongside Package 6 rather than block it. Package 6's deterministic slices — campaign records, objective resolution, the campaign director, the reward allocator, the cooperative hostility policy, and the 6A/6C consultation lane — were written and verified once, but that whole pass landed as a single commit named `wip` that has not been gated or split since, while the host half of the same pair is still uncommitted. The queue the task index actually holds, in order: **(1)** `REV-002` — run the gates over that batch and pair-commit the host side, so the module stops depending on host classes a fresh host checkout does not have; **(2)** `DOC-006` — name the wave-8 algorithms, which is what unblocks the five decision-core rows behind it; **(3)** the wave-8 live-play rows (`IMPL-035` dispatch ceiling, `IMPL-036` full-storage spend, `IMPL-037` colonise lock, `IMPL-038` probe budget, `IMPL-040` quiet-decision reason, `IMPL-041` sample trail) recorded in [Wave 8](details/GAP-REGISTER.md#wave-8--grand-test-live-play-read-16-september-2026); and **(4)** `REV-004` — the 2/5/10 capacity comparison, which is the gate `IMPL-031` (6B) and `DEF-001` (V2 reaction wake) wait on. Claim work from `plan/tasks/tasks.db` (`python3 plan/tasks/task.py ready`); the plan docs remain the source of truth. Package 7 stays planned: it starts only after Package 6 is signed and a review names the measured gap its slice will close.

**Wave 10 is the session-cost read (17 September 2026), filed but not yet confirmed.** A live AI session job measures 1–36 s where its sibling work kinds measure ~310 ms, and the read puts a perception build at 439 queries with ~95 % of its wall clock inside query calls. The two host rows — [W10-1 and W10-2](details/GAP-REGISTER.md#wave-10--session-cost-read-17-september-2026) — are 105 of those queries (the mission catalogue instantiated inside `FacilityChain` for metadata that is already `static`) and the remaining ~334 (the service graph rebuilt per lookup), so **neither closes the gap alone: W10-1 leaves ~15 s**. `REV-005` reproduces the figures on the committed build first; `IMPL-043` (P1, mission catalogue), `IMPL-044` (P2, one player reload per job) and `IMPL-045` (P1, the module-side share, which needs no host edit and goes first) all depend on it. The `CACHE_STORE` misconfiguration and the 405-query colony walk found in the same pass are already fixed in the working tree and recorded in the wave's preamble so the rows are read against the right baseline. Note the register's caution: on this host the same statement measures 45.8 ms through raw PDO and 2.5 ms through the query builder interleaved, so the query counts are the portable finding and the seconds are not.

**Provider routing R1 is implemented:** one ordered vendor ladder per task kind, with rungs that can be gated to a vendor's peak or off-peak window, a vendor without a credential dropped before the call, and the SDK's own failover left to do the walking. R2-R5 (failover attribution, per-vendor budgets and cost accounting, operator visibility, a per-vendor conformance run) remain.

The detailed [implementation map](details/IMPLEMENTATION.md) is the technical source for proposed files, migrations, services, event boundaries and tests. Open only the section named by the assigned package. Everything else under `details/` is reference material, not an entry point or a task list.

## Strategy and simulation research

The source-backed [experienced-player strategy and deterministic simulation](details/research/strategy-simulation-and-bot-patterns.md) note translates fleet safety, ROI, intelligence, raids and event timing into a low-dependency implementation plan. It examines public bot code only as engineering evidence for the OGameX in-product AI and offline simulator; it must never be used to automate official OGame accounts.

Three further research notes were taken on 14 September 2026 and are the evidence behind the plan's
opening behaviour:

- [How experienced players actually play](details/research/veteran-play.md) — the opening and how it is
  really decided, the contested energy doctrine, storage, research priorities, the daily routine, fleet
  saving, raiding, colonisation, trade, plus the activity parameters a persona needs (sessions, window,
  latency, absence, mistake rate).
- [What automation tools already solved](details/research/ogame-automation-algorithms.md) — eleven public
  projects read for eight recurring algorithms, with the warning that they all hardcode the object and
  requirement tables gate 1 forbids.
- [Which decision technique to use, and which not to build](details/specs/decision-techniques.md) — the
  answer to "least dependence on language models": utility scoring, marginal payback, threshold rules,
  hysteresis and a routine distribution; no solver, no planner subprocess, no learned model.

A second, deeper pass on 14 September 2026 re-read all sixteen automation projects at source level,
re-fetched every guide link and read the host itself. It produced two documents that the remaining gap
work is built on:

- [The gameplay algorithms](details/specs/gameplay-algorithms.md) — **the execution strategy**: for every
  gap in the register, the concrete algorithm that closes it, its host inputs, its constants and where they
  come from, how it fails, its acceptance evidence, and the corrections this pass made to earlier notes.
  Start here when closing a gap.
- [What the host already answers, and what it does not](details/research/host-capability-map.md) — the
  exact host methods behind each algorithm, the ten rules that live only in controllers (the host
  obligations), and the two findings that changed the plan: the battle engine is neither seedable nor
  side-effect free, and the host's own bot detector publishes the cadence thresholds the routine must
  satisfy.
- [What the module needs from the host](details/specs/host-change-request.md) — the other direction of the
  same survey: eight asks ordered by what they unblock, each with the minimal shape that satisfies it,
  what lands in the module when it does, and what happens if it never does. One of them (a read-only,
  seedable battle question) is what makes the raid estimator executable at all.

The [gate audit](details/GATE-AUDIT.md) is the pass over the existing module against the three gates, and
it is what turns this research into slices.
