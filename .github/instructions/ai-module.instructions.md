---
name: AI Module
description: Architecture and workflow rules for Modules/AI work.
applyTo: "Modules/AI/**"
---

# Modules/AI

The host game is authoritative for mechanics, legality, resources, combat,
queues, character-class restrictions, and game state.

The AI module perceives, decides, schedules and calls normal host domain actions.
Do not duplicate host rules.

Before significant AI behaviour changes:
1. inspect relevant plan/spec/research files;
2. inspect existing implementation and tests;
3. identify the smallest compatible seam;
4. preserve deterministic seeded behaviour where applicable.

Human-behaviour constants belong in `Modules/AI/resources/behavior`.
Game mechanics do not.

Behavioural values must be traceable to:
- a source ID from the OGame research registry,
- measured simulation evidence, or
- an explicit INFERENCE/tuning decision.

Do not invent historical OGame doctrine.

Use `plan/tasks/USAGE.md` and the existing task database workflow.
Plan documents remain authoritative.

Prefer focused subagents for independent research/code-map/review questions.
Do not load unrelated project areas merely for completeness.

Every completed change requires focused tests and a compact handoff.
