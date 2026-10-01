---
name: ogame-doctrine-synthesis
description: Classify collected OGame evidence into competing player doctrines without losing provenance.
---

Frozen work (`AGENTS.md` → Direction): run only when the owner asks or a row's notes need a source.
Every rule or claim you record names the scorecard aspect it bears on (`bash scripts/ogamex scorecard`
lists them); one that bears on none is not recorded.

Read only the relevant raw corpus and source registry.

Group evidence into distinct schools/doctrines.
Preserve disagreements instead of averaging them.

For every rule cite source IDs.
Separate:
- documented mechanics,
- player doctrine,
- anecdote,
- simulation evidence,
- project inference.

Write/update the appropriate file under:
`Modules/AI/plan/research/ogame/synthesis/`.

Do not modify gameplay code or behavior YAML.

End with:
SCHOOLS
SUPPORTED NUMBERS
DISAGREEMENTS
MISSING EVIDENCE
IMPLEMENTATION QUESTIONS
