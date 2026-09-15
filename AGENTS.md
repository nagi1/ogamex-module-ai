# Nagi — AI module agent instructions

Use this as the base operating contract for all work in `Modules/AI`.

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
decides how much machinery is allowed in between.

## Outcome

Write clean, maintainable, production-ready code. Keep changes module-first: reuse existing OGameX extension points and do not move AI policy, persistence, or orchestration into the host.

## Decision baseline

Check these two criteria before any material design choice. A choice that fails either is not made.

- **The goal** is accounts a human player cannot distinguish from other humans in ordinary play. Authenticity is measured by what a player can observe — reaction latency to a probe or attack and whether a save ever fails, the shape of the uptime across the day, the public hourly growth curve, the self-similarity of the action sequence, and the breadth of social contact — never by message polish. Read `plan/details/research/account-authenticity.md` and `plan/details/research/player-personas.md` before designing anything behavioural.
- **The reference deployment profile** is a small VPS: **2 vCPU, 2 GB RAM, no GPU**, already running the Laravel app, queue workers, the database and Redis. The native engines are the only cognition path that fits it; sidecar drivers are opt-in for hosts with measured headroom, and no driver is enabled there without a measured resident footprint. Read `plan/details/specs/budgets.md`.
- **Memory is paid for with generative calls in every modern product.** Do not adopt one, and do not reimplement one: adopt the mechanisms (decay from last access, weighted retrieval, write-time importance from authored rules, citation pointers, validity windows, selective forgetting). Read `plan/details/research/agent-memory-tooling.md`.

## Nagi implementation baseline

- Apply SOLID, DRY, KISS, YAGNI, composition over inheritance, high cohesion, and low coupling.
- Keep business logic in descriptive action classes. Do not add forwarding-only proxy methods.
- Prefer small, single-purpose methods, practical immutability, shallow control flow, readable code, and measured optimization only.
- Use early returns, strategies, lookup tables, or `match`; never write `else`, `else if`, or `elseif`.
- Handle failures explicitly. Comments are short, meaningful explanations of *why*, never narration of syntax.
- Before changing a feature, inspect comparable module and host code and verify the real schema. Follow existing patterns; do not add dependencies, speculative refactors, or abstractions without an evidenced module need and approval.
- Consult official Filament v5 documentation before changing Filament components.

## Design

- Apply SOLID, DRY, KISS, YAGNI, composition over inheritance, high cohesion, and low coupling.
- Never duplicate a capability a supported driver already provides. A seam exists for swap-ease, not for a PHP reimplementation: do not port a driver's algorithm, do not write a second implementation intended to match its output, and do not add a parallel "native equivalent" purely to compare against. A .NET or Python service does appraisal, social volition and case retrieval better than PHP will, and a duplicate leaves two authorities that can drift.
- Around a driver the module owns only its own authority: scope, attribution, permission, current-validity, validation, budgets, persistence, failure mapping and translation to and from the driver's wire format. Where a driver returns a proposal or evidence, the ordinary module policy still decides how much weight it carries.
- Existing native engines (`NativeAffectEngine`, `NativeExperienceEngine`, `NativeSocialCognition`) stay as the default and as the fallback an absent or failed driver degrades to. The rule forbids new duplication; it does not ask for shipped fallbacks to be removed.
- Use action classes for business logic. Do not add proxy/wrapper methods that only forward a call.
- Prefer small, single-purpose methods, descriptive names, and practical immutability.
- Choose readable code before clever code, optimize only from measured evidence, and handle errors explicitly.
- Resolve container-managed actions, jobs, services, policies, and collaborators through `app()` or `app()->makeWith()`. Bind defaults in `AIServiceProvider` so they remain replaceable.
- Do not use `new` for module-managed actions, services, policies, drivers, or domain payloads; resolve them through `app()` / `app()->makeWith()`. Laravel-required anonymous migration classes are the only exception.
- Use enums for stable domain values. A one-use dynamic value may remain a string when an enum would not improve the design.
- Never use `else`, `else if`, or `elseif`; use early returns, strategies, lookup tables, or a `match` expression where appropriate.
- Do not add dependencies, abstractions, or refactors without a demonstrated module need and user approval when the scope is material.

## Phase 3 architectural memory

- Read `plan/details/specs/phase-3-cognition.md` and the assigned milestone before implementation; `plan/details/research/phase-3-current-state.md` distinguishes shipped behavior from proposals.
- Do not write PHP that duplicates a driver's capability. Adding a driver is an integration and swap exercise, never an excuse to reimplement its algorithm better in PHP.
- Keep cognition drivers behind small module-owned contracts and Laravel bindings. OGame-specific state/feature mapping and action resolution stay in module adapters; no standalone framework, generic game planner or second player runtime yet.
- Native structured truth, persona, obligations and outcome records belong to the module. FAtiMA/CiF, CBRKit, AgentOS and PsychSim are candidate implementations, never alternate authorities or mandatory sidecars.
- Ordinary gameplay, native event/memory processing, structured CBR and AI-to-AI social exchanges make zero generative calls. Use authored dialogue before optional human-language escalation; one foreground request may include validated proposals, never a separate extraction chain.
- Semantic retrieval, ML compression, Theory of Mind, strategic advice and deferred model batches need their documented activation/measurement gates. Mem0 is rejected. Preserve attribution/permissions and provider-off behavior across every driver swap.
- Validate libraries, Linux deployment and performance through pinned real-adapter experiments; do not treat claims or hypothetical graphs in the imported conversation as implemented facts.

## Reading and comments

- Study comparable host/module code and database schema before changing a feature. Follow established Laravel patterns.
- Consult official Filament v5 documentation before changing a Filament component; do not guess its API.
- Add a brief comment only for a non-obvious invariant, architectural boundary, algorithmic tradeoff, or reason a simpler-looking implementation is unsafe.
- Do not narrate syntax or put comments on every line. Prefer clear names over comments; comments explain *why*, never merely *what*.

## Tests and verification

- Tests use native Pest 5 syntax, named datasets for repeated scenarios, PAO, and PCOV. Never use Xdebug.
- Use only `/home/nagi/code/ogamex-next/local-docker-dev` for container-backed development and verification. Do not start or use another OGameX Docker environment.
- Prefer real OGameX models, services, database state, queues, locks, and validation paths. Mockery is prohibited. A narrow container override is allowed only to exercise an explicitly replaceable seam and must be named and justified.
- Cover every affected behavior, edge case, and branch with meaningful tests; changed module code must maintain 100% PCOV coverage.
- Run the Gate 2 review (`bash scripts/ogamex gate`) and resolve every must-fix finding before handoff.
- Run Pint, module PHPStan, Rector dry-run, full Pest, PCOV coverage, and TIA before handoff.

## Phase 3 execution

Continue working autonomously through every deterministic Phase 3 slice and do not stop for progress-only updates. Finish all work before the optional LLM/provider phase: complete 3B through 3G, keep the plan updated after each verified slice, run the required checks, and commit each meaningful completed slice. Stop only when the pre-LLM Phase 3 baseline is genuinely complete or a real blocker requires user input.
