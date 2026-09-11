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
- Use enums for stable domain values. A one-use dynamic value may remain a string when an enum would not improve the design.
- Never use `else`, `else if`, or `elseif`; use early returns, strategies, lookup tables, or a `match` expression where appropriate.
- Do not add dependencies, abstractions, or refactors without a demonstrated module need and user approval when the scope is material.

## Reading and comments

- Study comparable host/module code and database schema before changing a feature. Follow established Laravel patterns.
- Consult official Filament v5 documentation before changing a Filament component; do not guess its API.
- Add a brief comment only for a non-obvious invariant, architectural boundary, algorithmic tradeoff, or reason a simpler-looking implementation is unsafe.
- Do not narrate syntax or put comments on every line. Prefer clear names over comments; comments explain *why*, never merely *what*.

## Tests and verification

- Tests use native Pest 5 syntax, named datasets for repeated scenarios, PAO, and PCOV. Never use Xdebug.
- Prefer real OGameX models, services, database state, queues, locks, and validation paths. Mockery is prohibited. A narrow container override is allowed only to exercise an explicitly replaceable seam and must be named and justified.
- Cover every affected behavior, edge case, and branch with meaningful tests; changed module code must maintain 100% PCOV coverage.
- Run Pint, module PHPStan, Rector dry-run, full Pest, PCOV coverage, and TIA before handoff.
