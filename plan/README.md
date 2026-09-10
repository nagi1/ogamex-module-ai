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

**Phase 1 / Package 1 is complete locally and remains unmerged.** The following work exists:

- The host has a shared player-state refresh service and a building-only module
  action gateway. Human building requests delegate through that gateway.
- Focused host tests cover a legal build, rejected foreign planet, and rejected
  non-building. Rector, Pint and PHPStan pass. The full parallel suite exposed
  a pre-existing shared-settings lock timeout; that exact test passes alone.
- The AI module has profile/work/receipt migrations, enum-backed state
  definitions, a swappable deterministic building-scoring policy, a work job
  and a due-work command.

Migration-backed parallel tests cover successful work, duplicate delivery, lock
contention and host rejection. Rector, Pint and PHPStan pass.

## Decisions already made

- Use the existing OGameX Next module system. AI behavior stays in the AI module.
- The game remains responsible for rules, costs and execution. Add small shared extension points where the module needs them.
- Ordinary gameplay and game-event memory use algorithms and database records, with **zero language-model tokens**.
- Use language models selectively for conversation. Test Mem0 or alternatives only if basic memory proves insufficient.
- Give accounts different skills, routines and priorities. They use legal information, suffer real losses and rebuild normally.

## Build it in five phases

### 1. Connect the existing module — complete

Let a module-controlled account read the information it is allowed to see and perform normal game actions safely. Complete missing game notifications and validation connections as needed.

**Done when:** one account can queue a building through the normal game rules, and retrying the work cannot queue it twice.

### 2. Make believable players

First add growth, research, sessions and fleetsaving. Then add colonies, spying, selective raids, losses and recovery. Mix miners, casual players, traders and fleeters.

**Done when:** a small group can play through a simulated month with sensible routines and real consequences, using no language model.

### 3. Add relationships and conversation

Remember rivals, favors and agreements. Add alliance cooperation, delayed replies and optional generated conversation within a strict budget.

**Done when:** accounts remember important interactions across sessions and keep playing when the language provider is unavailable.

### 4. Test with humans, then grow

Run a small disclosed pilot. Check whether opponents are interesting, cooperation is useful and losses remain manageable. Measure cost and performance before increasing account numbers.

**Done when:** player feedback supports continuing and the population stays within tested operating limits.

### 5. Add cooperative PvE

Reuse the same accounts and policies under an AI faction coordinator. Give human alliances shared campaign goals, useful support roles and enforced protection from human-on-human hostility.

**Done when:** a coalition can complete a campaign, recover from a failed attempt and play without unintended human PvP.

This is a committed separate phase, not a second AI engine. Its design draws on researched cooperation mechanics; the exact adaptation still needs testing.

## Working with multiple agents

Use [WORK-PACKAGES.md](WORK-PACKAGES.md). It gives each agent an exclusive scope, dependencies, allowed paths, output and handoff format. Do not assign two agents to the same package. An agent may inspect another package but must not edit its files or implement its work. The coordinator integrates package pull requests in order.

**Work only on Package 1 now.** The module scaffold exists and repository inspection is complete; gameplay implementation has not started. Package 1 connects one account's legal game view and building action, then proves retries cannot repeat it.

The detailed [implementation map](details/IMPLEMENTATION.md) is the technical source for proposed files, migrations, services, event boundaries and tests. Open only the section named by the assigned package. Everything else under `details/` is reference material, not an entry point or a task list.
