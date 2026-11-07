# WIK-208 — Diplomat (concept, not mechanics)

Status: DOCUMENTED (community wiki)
Confidence: medium confidence — host must confirm

## What the source says

WIK-208 names a "Diplomat" concept for OGameX. The entry is community-wiki only: the host has not
confirmed that the concept exists in OGameX, and the source carries an empty numbers section.
Nothing about cost, effect, duration, ratio, cap, unlock condition or timing is stated, so nothing
numeric can be encoded.

## Numbers

None.

## What is wired

Nothing. This spec adds no AI behaviour, no constant, no enum case, no config key and no
`resources/behavior/` data file. The AI module must not read, reference or branch on a Diplomat
until the host confirms the mechanics and their numbers. Any future mechanics must arrive from the
host before code is written.

Because no behaviour is wired, this slice ships no replay scenario under `resources/scenarios/`
either: a scenario states the action the decision engine must choose, and no confirmed action for a
Diplomat exists to choose. Shipping one would encode a guess as a mechanic.

Recording the concept here is deliberate: a concept without numbers cannot be implemented, and a
guess encoded as behaviour would be indistinguishable from a host-confirmed mechanic.

## Acceptance

The plan asked for the spec check under `tests/Unit`. This module accepts only Feature tests, and a
check over the module tree belongs beside the module it describes, so the same clauses run from
`tests/Feature/DiplomatConceptSpecTest.php`: the spec exists, carries the markers above, declares no
numeric gameplay values, and no file under `app/` or `config/` mentions the Diplomat.

## Open questions

- Does the host confirm Diplomat exists in OGameX and with which mechanics?
- Should the spec live under `plan/ai/` or a `plan/specs/` subtree once that layout is fixed?
