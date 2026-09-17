---
description: AI module — verify the live behaviour of what already shipped, then claim and finish one ready slice
---

# AI module — check the live behaviour, then work one slice

You are picking up implementation work in `Modules/AI` of the OGameX host. Another agent may be
editing this same checkout, so read the collision rules below before you touch anything.

## 0. Read first (no shortcuts)

1. `Modules/AI/AGENTS.md` and `Modules/AI/.github/copilot-instructions.md` — the three gates, the
   laziness ladder, no `else`/`elseif`, business logic in action classes resolved through `app()`,
   no new dependencies.
2. `plan/tasks/USAGE.md` — the task DB is the work index, the plan docs are the authority.
3. `plan/details/DECISIONS.md`, the last ten entries only — what shipped on 17 September and why.

## 1. Check the index — do not trust this prompt's list of rows

- `python3 plan/tasks/task.py ready` is the authoritative startable set. Claim exactly one:
  `python3 plan/tasks/task.py claim WP-010 <your-name>`. If it prints `NOT claimed`, someone else
  holds it — take the next row.
- Work from the row's `doc_refs` (normally `plan/details/research/repos/WORK-PACKAGE.md`) and its
  `file_ref`. Prefer the lowest-numbered P2 in WORK-PACKAGE's "Proposed slice order" (`WP-010` next);
  `LLM-010` is independent of the gameplay queue and can be taken instead if you want a
  self-contained change on the usage/settlement path.
- **Do not start:** `P7-*` and `PVE-*` (evidence-gated — the gate is written in the row's notes),
  `RP-*` (provenance only — the "Per-repo residue audit" section of WORK-PACKAGE.md shows every
  repo's mechanisms are already `WP-*` slices), or `WP-001`…`WP-008`, which are shipped.

## 2. Check the current behaviour before changing it (bounded and read-only)

- Read the comparable module code and the real schema first — host service, existing planner, actual
  columns. One bounded pass, not a survey.
- The live universe is up (`ogamex-grand`). If your slice changes something observable, capture the
  **before** figure from artifacts that already exist (`ai_decision_traces`, `ai_stop_counters`,
  `ai:pilot-report`, the leader status row) so the handoff can show before/after. Read-only: no
  writes, no new collection, no generative calls on the read path.
- Every review record in `plan/details/reviews/` predates `WP-001`…`WP-008`. If your slice touches
  one of those behaviours — debris recycling, the raid loot/survival gates, fleetsave safety,
  harvest-save, storage-before-build, standing defence — the live read is the only thing that says
  whether it works, and one open question is already owed: whether the raid pipeline recovers now
  that the fleet ceiling (`IMPL-035`) and the probe budget (`IMPL-038`) are fixed.

## 3. Work the slice

- Take the smallest mechanism that closes the gap: one class, one loop, one sort key. No abstraction
  with a single implementation, no config for a value that never varies, no forwarding layer, no
  unmeasured optimisation. Delete what your slice makes dead.
- **Gate 1** — the object universe (buildings, ships, defence, technologies, prices, requirements) is
  read from the host at decision time. Never encode an object id, machine name, price or requirement
  as a source of truth; adding a host object must make it usable with no module edit.
- **Gate 3** — name the behaviour as something an experienced OGame player does. If it cannot be
  named, it is not ready; stop and say so.
- Never re-implement a capability a supported driver, the host, or an already-shipped slice owns. The
  module keeps scope, attribution, permission, current-validity, validation, budgets, persistence,
  failure mapping and translation — nothing more.
- Tests use real host services, models, database state and validation paths; native Pest 5 syntax,
  named datasets, no Mockery, no Xdebug; `/home/nagi/code/ogamex-next/local-docker-dev` is the only
  container stack. Leave one runnable check behind for the non-trivial logic.

## 4. Verify

- `OGAMEX_RUNNER=local-docker-dev bash Modules/AI/scripts/ogamex test <your test path>` — must pass.
- `bash Modules/AI/scripts/ogamex gate` — the Gate 2 review must exit clean; fix every must-fix
  finding before handoff.
- The full `quality` and `coverage` passes are **deferred by standing owner direction**. Do not run
  them unless asked — report exactly that in the handoff instead.
- After any task-DB edit: `python3 plan/tasks/dump_seed.py`, so `seed.sql` still reproduces `tasks.db`.

## 5. Handoff — return exactly this

- Row code; `python3 plan/tasks/task.py done <code>` only once it is merged and verified.
- Changed files.
- The check commands and their results, verbatim.
- The before/after figure if your slice changed an observable behaviour — or that the before was not
  available, and why.
- Unresolved risks, and anything you deliberately did not do.
- The next `ready` row you would take, and why.

## 6. Never

- `git add -A`. The checkout is shared: stage explicit paths only.
- Commit or edit another agent's in-flight work — read `git status` first.
- Re-introduce anything the audits closed as already shipped or refused.
- Push a slice whose behaviour nobody can observe from the artifacts the module already writes.
