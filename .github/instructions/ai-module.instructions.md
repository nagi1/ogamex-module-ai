---
name: AI Module
description: Architecture and workflow rules for Modules/AI work.
applyTo: "Modules/AI/**"
---

## North star (read first; overrides anything below)

The AI accounts must play like experienced human OGame players, and a change counts only when the
cohorts show it: an aspect of `bash scripts/ogamex scorecard` moves, a situation passes, or a cohort
invariant stops firing. Rules: `AGENTS.md`. State and order: `plan/HANDOFF.md`. Workflow:
`.github/skills/ai-task-execute/SKILL.md`.

# Modules/AI

- The host is authoritative for mechanics, legality, resources, combat, queues, character-class
  restrictions and game state. The module perceives, decides, schedules and calls ordinary host
  actions; it never restates a host rule.
- Objects, prices and requirements are read from the host at decision time (Gate 1).
- Values that express human behaviour live in `resources/behavior/*.yaml` and are loaded by name in
  the class that uses them. Each traces to a source id in the research registry, a measurement, or an
  explicit tuning decision. Never invent doctrine.
- Work comes from `python3 plan/tasks/task.py next`; a row closes only through `task.py done`, which
  runs its proof. See `plan/tasks/USAGE.md` for the ledger.
- Read only what the row needs: its notes, its `file_ref`, the tests that build those classes.
