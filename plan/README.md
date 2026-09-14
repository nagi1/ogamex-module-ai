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

Phase 3 adds source-backed facts, claims, relationships, commitments and affect, typed zero-LLM social protocols with authored replies, outcome-based experience cases, a sealed host delivery ledger, bounded context selection and atomic daily usage ledgers. The optional language slice is implemented behind `LanguageGateway` with a disabled default and the pinned `laravel/ai` SDK: normal gameplay, structured exchanges and AI-to-AI replies still make no generative call, and the provider is reached only by a substantive reply on an enabled lane or by the opt-in sanitized conformance command, which has now been run for real against `deepseek-flash` with 4/4 cases matching and the injection case refused. FAtiMA/CiF behind `AffectEngine`/`SocialCognition`, CBRKit behind `ExperienceEngine` and AgentOS behind the memory driver are implemented and opt-in but disabled by default, because none of the three has cleared Gate 2; PsychSim, embeddings and the scale runs (2/5/10 accounts) are not implemented, and a session choice executes only where an executor exists: `build` queues a real building through the ordinary host action path, while `save_resources`, `research`, `queue_units`, `spy`, `colonize`, `fleet_save` and `raid` remain recorded intents. **The 10-account pilot of 14 September 2026 is the evidence for both halves:** its first run showed ten sessions deciding end to end with zero provider requests and every one choosing `DoNothing`, because the ordinary-play observation published no ability; slice 3M closed that, and the cohort's next sessions queued real buildings — player 29129 a solar plant, player 29132 a crystal mine — for `actions: Accepted 2` where the window previously read `none`. **Package 5 does not start until Packages 1–4 are fully complete with every gate closed, and Package 4 is signed off.** See the [current-state assessment](details/research/phase-3-current-state.md) for the pre-Phase-3 snapshot, integration limitations and corrections to older diagrams. **A goal-level audit of what is still missing is in the [gap register](details/GAP-REGISTER.md)**, which records eighteen gaps against the eleven authenticity signals and the four planning failures behind them. It supersedes any "implemented" claim in this file that does not name the mechanism producing an observable.

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

## Working with multiple agents

Use [WORK-PACKAGES.md](WORK-PACKAGES.md). It gives each agent an exclusive scope, dependencies, allowed paths, output and handoff format. Do not assign two agents to the same package. An agent may inspect another package but must not edit its files or implement its work. The coordinator integrates package pull requests in order.

**Current work: the disclosed pilot.** Packages 1–4 are implemented. Package 3 delivers slices 3A–3L: deterministic social cognition, experience, memory, the conversation cycle and the bounded provider escalation, which is off by default. Package 4 gives operators the caps, the staff switch, decision inspection, a read-only scenario replay, production-refusing seeding and a pilot report on the existing admin page. What remains is evidence: Gate 2 for the three opt-in drivers, the 2/5/10-account runs at the very end, the ability-publishing and action-execution slice the first pilot showed was missing (landed as 3M, with the remaining selections still to gain executors), the disclosed pilot at scale with recorded human feedback, and the two acceptance wordings currently met with narrower evidence. 3H's real-provider artifact is no longer outstanding: on 14 September 2026 all four sanitized cases ran against `deepseek-flash`, matched their expected interpretation and refused the injection case, for about a tenth of a cent. Do not repeat completed Package 1/2 work, and do not treat the capacity measurements as already proven.

**Not started: Package 5.** It waits on Package 4 being complete *and signed* — acceptance criteria met with evidence, the 10-account pilot report reviewed and its findings accepted, and that acceptance recorded in [details/DECISIONS.md](details/DECISIONS.md).

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

The [gate audit](details/GATE-AUDIT.md) is the pass over the existing module against the three gates, and
it is what turns this research into slices.
