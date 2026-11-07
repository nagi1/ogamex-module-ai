# Over-engineering gate (Gate 2, operational)

Gate 2 of [cognition-gates.md](cognition-gates.md) is the design constraint; this is the standing
procedure that enforces it before a slice is accepted. It has two halves — a deterministic scan and a
reviewer judgement — and both must pass.

## Why a standing gate

The three gates are read at design time, but a slice that starts small can rot into machinery as later
slices build around it. The Gate 2 findings in [../GATE-AUDIT.md](../GATE-AUDIT.md) were found only by
a one-off audit. This gate makes the check repeatable and runs it with every quality pass, so an
over-complicated implementation is caught where it is written, not in the next audit.

## The deterministic scan

`scripts/gate-2-review.php` reports the three shapes a machine can prove exactly:

1. a contract in `app/Contracts/` with exactly one implementation and not on the deliberate-seam
   allowlist;
2. an enum no code references;
3. a config file no code reads.

Exit 1 means at least one non-allowlisted finding. The allowlist lives in the script and is the seam
list the plan commits to (`RunAiSession`, `ContextBuilder`, `ArchetypePolicyResolver`, the seven
`QueueAi*` host action gateways). A new seam enters the allowlist only with a written reason in
`GATE-AUDIT.md` — never silently.

## The reviewer judgement

Run by the Gate 2 reviewer agent (`.github/agents/gate-2-reviewer.agent.md`). It climbs the lazy senior
dev ladder (`.github/copilot-instructions.md`) before the checklist below and reports with the ponytail
review tags (`delete`, `stdlib`, `native`, `yagni`, `shrink`) — the ladder decides whether the code
should exist at all; the checklist decides whether what remains is the smallest shape. It checks what the
scan cannot:

- **An abstraction with one implementation.** Collapse it or document the seam. A contract that a test
  swaps is a seam; a contract no test swaps is the defect.
- **Config for a value that never varies.** A settings key nothing writes, a weight no caller sets.
- **A layer that only forwards.** A class whose methods only delegate to another class.
- **Dead code a slice left behind.** An enum, a settings key, a helper or a test double no caller uses.
- **The paragraph test.** A design that needs a paragraph to justify each of its parts fails.

## Verdict

- **PASS** — the scan is clean and the reviewer names no must-fix finding.
- **FAIL** — any must-fix finding. The slice is changed before it is accepted; a "deliberate" finding
  is recorded with its reason in `GATE-AUDIT.md`, not silently allowed.

The gate runs as `scripts/ogamex gate`, inside `scripts/ogamex quality`, and before handoff (see
`AGENTS.md`).
