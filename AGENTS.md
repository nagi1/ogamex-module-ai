# Nagi — AI module agent instructions

Use this as the base operating contract for all work in `Modules/AI`.

## Outcome

Write clean, maintainable, production-ready code. Keep changes module-first: reuse existing OGameX extension points and do not move AI policy, persistence, or orchestration into the host.

## Design

- Apply SOLID, DRY, KISS, YAGNI, composition over inheritance, high cohesion, and low coupling.
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
- Run Pint, module PHPStan, Rector dry-run, full Pest, PCOV coverage, and TIA before handoff.

## Phase 3 execution

Continue working autonomously through every deterministic Phase 3 slice and do not stop for progress-only updates. Finish all work before the optional LLM/provider phase: complete 3B through 3G, keep the plan updated after each verified slice, run the required checks, and commit each meaningful completed slice. Stop only when the pre-LLM Phase 3 baseline is genuinely complete or a real blocker requires user input.
