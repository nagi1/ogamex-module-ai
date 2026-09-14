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

**Phases 1–2 are recorded in module commit `e1c48a2`. Phase 3 slices 3A–3L are implemented and committed: 3K wires the conversation path into the session so an account answers a real message in ordinary play, and 3L offers a substantive reply to the optional provider on its own lane. 3J is complete apart from the capacity runs: by owner decision the 100/500/1,000-player measurements stay at the very end of the phase, and a 10-account pilot stands in for them — that pilot has run, and what it measured is recorded below.** The module has profiles, leased/idempotent work, a normal validated building adapter, legal perception, seeded archetype policies, session schedules and decision traces.

Phase 3 adds source-backed facts, claims, relationships, commitments and affect, typed zero-LLM social protocols with authored replies, outcome-based experience cases, a sealed host delivery ledger, bounded context selection and atomic daily usage ledgers. The optional language slice is implemented behind `LanguageGateway` with a disabled default and the pinned `laravel/ai` SDK: normal gameplay, structured exchanges and AI-to-AI replies still make no generative call, and the provider is reached only by a substantive reply on an enabled lane or by the opt-in sanitized conformance command. FAtiMA/CiF behind `AffectEngine`/`SocialCognition`, CBRKit behind `ExperienceEngine` and AgentOS behind the memory driver are implemented and opt-in but disabled by default, because none of the three has cleared Gate 2; PsychSim, embeddings and the 100/500/1,000-player measurements are not implemented, and session choices remain recorded intents rather than proof that an action executed. **The 10-account pilot of 14 September 2026 is the evidence for that last wording:** ten sessions ran end to end with zero provider requests and every one chose `DoNothing`, because the ordinary-play observation publishes no ability and nothing creates the executable first-building work item. Publishing those abilities and executing the chosen intent is therefore the evidenced next pre-LLM slice. **Package 5 does not start until Package 4 is complete and signed off.** See the [current-state assessment](details/research/phase-3-current-state.md) for the pre-Phase-3 snapshot, integration limitations and corrections to older diagrams.

## Decisions already made

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

**Current work: the disclosed pilot.** Packages 1–4 are implemented. Package 3 delivers slices 3A–3L: deterministic social cognition, experience, memory, the conversation cycle and the bounded provider escalation, which is off by default. Package 4 gives operators the caps, the staff switch, decision inspection, a read-only scenario replay, production-refusing seeding and a pilot report on the existing admin page. What remains is evidence plus one measured gap: 3H's real-provider conformance artifact, Gate 2 for the three opt-in drivers, the 100/500/1,000-player runs at the very end, and the ability-publishing/action-execution slice the 10-account pilot showed is missing. Do not repeat completed Package 1/2 work, and do not treat the capacity measurements as already proven.

**Not started: Package 5.** It waits on Package 4 being complete *and signed* — acceptance criteria met with evidence, the 10-account pilot report reviewed and its findings accepted, and that acceptance recorded in [details/DECISIONS.md](details/DECISIONS.md).

The detailed [implementation map](details/IMPLEMENTATION.md) is the technical source for proposed files, migrations, services, event boundaries and tests. Open only the section named by the assigned package. Everything else under `details/` is reference material, not an entry point or a task list.
