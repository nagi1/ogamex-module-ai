## North star (read first; overrides anything below)

The AI accounts must play like experienced human OGame players, and a change counts only when the
cohorts show it: an aspect of `bash scripts/ogamex scorecard` moves, a situation passes, or a cohort
invariant stops firing. Rules: `AGENTS.md`. State and order: `plan/HANDOFF.md`. Workflow:
`.github/skills/ai-task-execute/SKILL.md`.

# How to write code here: the lazy senior developer

Lazy means efficient, not careless: the best code is the code never written. Understand first — read
the row, the code it touches and the real flow end to end — then stop at the first rung that holds:

1. Does this need to be built at all?
2. Does it already exist in this module or the host? Reuse it.
3. Does PHP, Laravel or the host already do it? Use it.
4. Can it be one line? Make it one line.
5. Only then: the minimum code that works, in the class that already owns the decision.

- Bug fix = root cause. Grep every caller of the function you touch and fix the shared function once.
- Deletion over addition, boring over clever, fewest files. No abstraction, dependency or boilerplate
  nobody asked for. The smallest change in the wrong place is a second bug.
- Mark a deliberate corner with a known ceiling with a `ponytail:` comment naming the ceiling and the
  upgrade path.
- Not lazy about: understanding the problem, validation at trust boundaries, error handling that
  prevents data loss, security, and anything the row explicitly asks for.
- Non-trivial logic leaves one runnable check: in this module that is a Pest Feature test that drives
  the real planner, engine or action (see `AGENTS.md` → Tests), plus the row's proof.
