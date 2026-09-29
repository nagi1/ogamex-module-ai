# Position concept — solar-system slot

## Provenance

WIK-193 — a community wiki concept page, medium confidence. It describes the *position* a planet
occupies: which slot inside a solar system it sits in. The page carries no verified value, no
range, no default, and the bundle that accompanies it ships no numbers at all.

## Status: unresolved

The AI module has no opinion on position. No action, planner branch, enum, config key or scenario
fixture reads a slot, because the host has never confirmed that a slot is a value the module can
consult. Encoding one now would mean inventing the object universe — the forbidden move — so this
note records the concept and stops there.

## Why nothing was encoded

Two open questions block every mechanical form of this concept:

- Does the host define a fixed slot range the AI must respect, and what are its bounds?
- Does the slot change any AI decision — fleet timing, colony placement, raid selection — or is it
  purely presentational?

Until a host-confirmed source answers them, a range written into PHP or a data file would be a
guess dressed as policy. When that source lands, the bounds belong in a data file under
`resources/behavior/`, read by the planner, never inline in a class.

## Why the guard lives where it does

This note is the deliverable, so the test that protects it is the only testable behaviour: a later
edit that types a slot literal has to justify it against a confirmed source rather than against
this page. The check runs `php artisan test --filter=PositionConceptSpecTest`, from
`tests/Feature`, because this module does not collect unit tests and a check under `tests/Unit`
would never run. No scenario fixture ships with this note: a scenario would assert whatever the
decision engine happens to answer today, which is not behaviour this page defines.

## Guard

Nothing here states a slot number. Do not add one until the Open Questions above are answered.
