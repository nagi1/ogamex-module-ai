# WIK-196 — Diameter

- ticket: WIK-196
- module: `Modules/AI`
- claim type: DOCUMENTED
- confidence: medium
- host confirmation: none
- mechanic encoded: none

## Why nothing was implemented

Section 2 of the source states no verbatim numbers for Diameter, and section 4 lists no existing AI
principle, constant, decision input or action derived from it. Encoding a mechanic would mean
inventing both the quantity and the rules that consume it, so Diameter is recorded here as a
community-documented, host-unconfirmed concept and no code is shipped for it.

## What the source supports

- Diameter is documented only by the community wiki; the source does not state what it measures,
  what unit it is expressed in, or which gameplay quantity it would describe.
- The source states no numeric value, range, threshold or growth rule for Diameter.
- The source names no AI principle, archetype, decision input, resource, ship or building that would
  read Diameter.
- No host build note confirms that OGameX implements Diameter at all.

## Consequences for `Modules/AI`

- No constant, enum case, config key, column, migration or action is created for Diameter.
- The module ships no Diameter-derived value; `tests/Unit/Ai/DiameterConceptTest.php` is the guard
  that keeps it that way.
- If a later source states the mechanic, that source is what the implementation must be built from.
  This file then only needs its claim type and confidence updated.

## Open questions

- Which host-confirmed mechanics, if any, does Diameter drive in OGameX?
- Should this stay documentation-only until the host confirms, or be dropped as out of scope for
  `Modules/AI`?
