# WIK-164 — planet size and available fields

- source: WIK-164 (community wiki, concept page)
- status: community-wiki — never official, never validated against a host
- host-confirmable: yes — the mechanic is only real once the host confirms it
- principles: COL-004, INT-008

## What the source actually states

Section 2 of WIK-164 is empty. The page names the *concept* of planet size and available fields and
stops there: no planet position, no size class, no field count, no formula, no cap. Nothing numeric
can be derived from it.

Nothing numeric may therefore be encoded in the AI module either. A number invented here would be
indistinguishable from a confirmed host rule, and this plan's own risk note says the mechanic may be
wrong until the host confirms it.

## How the AI is allowed to learn available fields

Only by observation: the INT-008 espionage report carries the fields a planet actually has, and that
report is read at decision time. Planet size is an *input from the report*, never a constant, never a
default and never a fallback inside the colonization target selection.

COL-004 already owns the colonization policy and its `fields` entry. This file does not restate
COL-004; it records the missing source and the guard that keeps planet size out of the module's code.

## Guard

`tests/Unit/Wik164PlanetSizeSpecTest.php` fails when a planet-size or field-count literal appears in
the module's runtime sources, or when this file stops marking the mechanic as community-wiki and
host-confirmable.

## Not proposed

The work item's title suggests giving the espionage planner a planet-size input so that scouting
targets can be ranked by a documented max-field range. WIK-164 documents no max-field range, so such
an input could only carry a number nobody can confirm. The plan's DECISION is facts-only; no such
input is added, and fields keep arriving through the INT-008 espionage report alone.

## Host confirmation required

- Which host mechanic confirms available fields per planet position?
- Is Terraformer (WIK-164 section 3, not yet ingested) needed before fields can be treated as a hard
  colonization constraint?

Until both are answered, the mechanic stays community-wiki and the AI keeps reading fields from the
INT-008 espionage report only.
