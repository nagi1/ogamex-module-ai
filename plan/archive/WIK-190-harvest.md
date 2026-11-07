# WIK-190 — Harvest

- status: unconfirmed, not implemented
- source: community wiki extract (DOCUMENTED, confidence medium)
- plan bundle: c5ed42de9898, generated 2026-09-28 21:17 UTC, status VALIDATED (deterministic checks only — not approved)
- code touched by this task: none

This file records an extraction gap. It is not a mechanic and it is not a specification.

## Facts extracted from the source

1. Section 2 states no numbers.
2. Section 3 has no pages.
3. Section 4 shows the AI module has no opinion on Harvest.

There is nothing else. No threshold, no bound, no cap, no ratio, no page list and no decision
input was extracted, so none is written down here: any number added to this file would be an
invention. Because the extract states no numbers, the test below pins no numeric bound either.
The AI module therefore has no Harvest behaviour to implement, and none was implemented.

## The pin

`tests/Unit/Ai/HarvestConceptUnimplementedTest.php` asserts the absence:

- no file under `app/Ai/**` or `app/Actions/**` carries a Harvest name;
- no `config/*.php` declares a `harvest` key;
- the two matchers used above do recognise a Harvest symbol, so the absence cannot pass vacuously;
- this note is still recorded under `plan/`.

The test fails the moment a Harvest symbol is added, which is the point: a future Harvest
implementation cannot land silently. Lifting the pin means changing the test and this file in the
same change as the host confirmation.

## How the acceptance paths were read

- `app/Ai/**`, `app/Actions/**` and `config/*.php` are read under both the AI module root and the
  project root, and every root that exists is scanned. The union only makes the assertion stricter.
- Name matching is case-insensitive: `harvest.php` is as much a Harvest opinion as `Harvest.php`,
  and a class name is not guaranteed to capitalise the word.
- A Laravel config file's basename is its top-level config key, so a `config/harvest.php` counts as
  a `harvest` key even when its own keys are named differently.
- If none of the named roots exist the test fails instead of passing vacuously.

## How to lift the pin

When the host confirms the Harvest mechanics, replace this note with the confirmed extract and
delete `tests/Unit/Ai/HarvestConceptUnimplementedTest.php` in the same commit that adds the first
Harvest symbol.

## Risks

- The source is a community wiki (DOCUMENTED, confidence medium); any mechanic written into this
  file later may be wrong.
- An absence-asserting test adds maintenance friction once the host confirms the real mechanics.
- This file may duplicate future wiki extracts if `plan/**` gets no naming convention.

## Open questions

- Which section 2 numbers, if any, were meant to be attached? None were extracted.
- Does the host confirm Harvest mechanics before any `app/Ai/**` work is started?
