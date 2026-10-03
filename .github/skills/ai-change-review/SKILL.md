---
name: ai-change-review
description: Review a Modules/AI change against its row, its proof and the three gates before it is committed or closed.
---

1. `python3 plan/tasks/task.py show CODE`: the row's notes, `file_ref` and proof.
2. North star: the proof names an aspect, situation or invariant; the change is wired into the
   runtime path an account runs; `bash scripts/ogamex prove CODE` failed before and passes after (or
   says "too early" for an aspect, which is not a failure of the change).
3. Gates: nothing hardcoded the host owns (1); the smallest mechanism, no rival class, nothing dead,
   `bash scripts/ogamex gate` clean (2); nameable as ordinary experienced play (3).
4. Behaviour values live in `resources/behavior/*.yaml`, loaded by name; tests drive the real path and
   cover the bound, past it and zero.

5. Quality verdict (DEF-003): read the newest `QUALITY:` line from `scripts/verify-cohorts.php` (the
   harness prints it each pass). A slice whose behaviour fails an invariant is refused unless the
   review says why the invariant is wrong; a verdict older than the slice's commit is "too early",
   not a pass.

Do not broaden into unrelated cleanup. Report blockers first, then say explicitly whether
`task.py done` may close the row.
