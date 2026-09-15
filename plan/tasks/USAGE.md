# Task DB — the one-stop implementation index

A small SQLite database (`tasks.db`) that is the **reference for all implementation work**. It
indexes every remaining task, its plan references, status, notes and dependencies, so multiple agents
can claim work without colliding and the dependency graph tells each agent what is safe to start.

## Source of truth

The **plan docs are authoritative**; the DB is a derived index. When a plan doc changes, re-seed (or
edit the row) — never let the DB and the docs disagree.

- Gaps: `plan/details/GAP-REGISTER.md` (wave 6 rows `W6-1..6` are the strategy-depth gaps)
- Principles: `plan/details/research/strategy-principles.md`
- Code mapping: `plan/details/research/architecture-mapping.md`
- Algorithms: `plan/details/specs/gameplay-algorithms.md` (blocks `SP7`, `T6-T8`, `N4/N5`, `V6-V8`, `F1-F6`, `U-series`)
- Integration gates: `plan/details/specs/strategy-mining.md` → "Integration gates (L)"

## Files

| File | Purpose |
| --- | --- |
| `plan/tasks/seed.sql` | Schema + seed data (editable, committed) |
| `plan/tasks/tasks.db` | Generated from `seed.sql` (regenerable) |
| `plan/tasks/task.py` | CLI wrapper over the DB — the everyday interface |
| `plan/tasks/USAGE.md` | This file |

Rebuild from seed at any time:

```
sqlite3 plan/tasks/tasks.db < plan/tasks/seed.sql
```

(If `sqlite3` is absent, `python3 -c "import sqlite3,io; c=sqlite3.connect('plan/tasks/tasks.db'); c.executescript(open('plan/tasks/seed.sql').read()); c.commit()"` does the same.)

## CLI (preferred interface)

`python3 plan/tasks/task.py` wraps every query below; prefer it over raw SQL.

```
python3 plan/tasks/task.py ready                    # what can start now
python3 plan/tasks/task.py blocked                  # todo but blocked
python3 plan/tasks/task.py graph                    # task list + direct deps
python3 plan/tasks/task.py claim IMPL-013 $(whoami) # guarded claim
python3 plan/tasks/task.py done IMPL-013            # mark merged + verified
python3 plan/tasks/task.py block IMPL-017 reason    # todo -> blocked, with a note
python3 plan/tasks/task.py unblock IMPL-017
python3 plan/tasks/task.py deps IMPL-017            # transitive needs
python3 plan/tasks/task.py depends-on REV-001       # who needs this
python3 plan/tasks/task.py add IMPL-021 "title" impl P2 --depends IMPL-017,REV-001 \
    --gap W6-x --principles XXX-001 --file app/... --notes "..."
```

`sqlite3 plan/tasks/tasks.db "..."` also works once the CLI is installed
(`sudo apt-get install -y sqlite3`); Python's bundled `sqlite3` module is always the fallback.

## Schema

- `tasks` — `code`, `title`, `kind` (`review`/`doc`/`impl`/`deferred`/`discovery`), `status`
  (`todo`/`in_progress`/`blocked`/`done`/`deferred`/`open`), `priority` (`P0..P3`), `assignee`,
  `gap_ref`, `principle_refs`, `algorithm_ref`, `doc_refs`, `file_ref`, `notes`, `updated_at`.
- `dependencies` — `(task_id, depends_on)` with a `reason`; a task is only ready when **every**
  dependency is `done`.
- `ready_tasks` view — `todo` tasks whose dependencies are all `done`.
- `blocked_tasks` view — `todo` tasks with at least one unmet dependency.

## Everyday queries

```sql
-- what can I start right now?
SELECT code, title, priority, file_ref FROM ready_tasks ORDER BY priority, id;

-- everything open, with its blockers
SELECT t.code, t.status, t.priority, group_concat(dep.code)
FROM tasks t LEFT JOIN dependencies d ON d.task_id = t.id
LEFT JOIN tasks dep ON dep.id = d.depends_on
WHERE t.kind <> 'done'
GROUP BY t.id ORDER BY t.id;

-- claim a task (exactly one agent per task)
UPDATE tasks SET status='in_progress', assignee='agent-name', updated_at=datetime('now')
WHERE code='IMPL-013' AND status='todo';
SELECT changes();  -- 0 means someone else already took it

-- finish a task
UPDATE tasks SET status='done', updated_at=datetime('now') WHERE code='IMPL-013';

-- block / reopen a task, with a note
UPDATE tasks SET status='blocked', notes=notes || ' | blocked: <reason>' WHERE code='IMPL-017';
UPDATE tasks SET status='todo', assignee=NULL WHERE code='IMPL-017';

-- add a new dependency (e.g. a task turns out to need another)
INSERT OR REPLACE INTO dependencies (task_id, depends_on, reason)
VALUES ((SELECT id FROM tasks WHERE code='IMPL-017'), (SELECT id FROM tasks WHERE code='IMPL-014'), 'reason');
```

## Agent rules

1. **One agent, one task.** Claim with the `status='in_progress'` guarded update above; check
   `changes()` — if 0, the task is already taken.
2. **Start only from `ready_tasks`.** If a task is in `blocked_tasks`, its dependencies are not done;
   do not start it.
3. **The docs win.** `doc_refs` names the file that defines the work; read it before writing code.
   If the doc and this DB disagree, fix the DB (or the seed), not just the row.
4. **Keep statuses honest.** `done` means merged and verified (module gate green). A half-done task
   stays `in_progress` with a note.
5. **Never re-seed to erase statuses you don't own.** Re-seeding resets everything; if you must
   change the seed, preserve the status/assignee of tasks other agents hold.
6. **New work = new row**, with `gap_ref`/`principle_refs` pointing at the plan, and a dependency on
   whatever it needs. Don't duplicate an existing row.

## Status flow

```
todo ──claim──▶ in_progress ──merged──▶ done
  │                 │
  └──blocked────────┘ (dependency or external blocker)
todo ──▶ deferred (recorded follow-up, e.g. DEF-*)
todo ──▶ open     (open discovery, e.g. DISC-*)
```

## What blocks implementation today

`REV-001` (catalog review, status `blocked`) gates every `impl` task. Until it is `done`,
the only ready work is the `DOC-*` tasks (write the algorithm blocks). Once reviewed:
`IMPL-013` (SP7 reader) and `IMPL-018` (fleet composition, parallel-safe) are the first two
`impl` tasks ready.

## The executor agent

The `plan-executor` custom agent (`.github/agents/plan-executor.agent.md`) is the role that works
this DB: it reads `ready`, claims one task, follows its `doc_refs`, verifies, and marks `done`,
adding tasks and dependencies as the work expands. Invoke it (or run the CLI yourself) with a task
code or `plan` to see the next ready task.
