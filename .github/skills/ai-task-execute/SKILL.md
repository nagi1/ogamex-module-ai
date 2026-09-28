---
name: ai-task-execute
description: Execute one task from the Modules/AI task database with bounded context and focused verification.
---

Input: task code.

Follow `Modules/AI/plan/tasks/USAGE.md`.

Resolve the task, dependencies and referenced docs using the existing task CLI.
Read only the relevant implementation/research/specification surfaces.

If the task has separable research/code-archeology questions, use subagents.

Implement only the task scope.
Use central behavior configuration for behavioural tuning.
Keep host mechanics in host services.

Add/update focused Pest tests and run them.

Do not mark work complete if required evidence/specification is missing.

Return the standard compact implementation handoff.
