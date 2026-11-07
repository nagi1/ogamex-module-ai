# WIK-041 — Hull Plating

Status: blocked — dependency spec only. The claim comes from the community wiki and is unconfirmed
on the host; it names a plating bonus without naming a value, so nothing can be encoded yet.

## Prerequisites

<!-- prerequisites:start -->
Structural Integrity: ingested
Armour Technology: not ingested
<!-- prerequisites:end -->

## Dependency order

1. Structural Integrity is ingested, so the hull value that a plating bonus would scale is known.
2. Armour Technology is not ingested, so the per-level plating bonus value stays unstated and
   cannot be inferred from anything already ingested.
3. Combat is ingested, so a plating bonus would be consumed by the existing combat pipeline once
   its value is known.

A plating bonus may be encoded only after both conditions hold, in this order:

1. Armour Technology is ingested and its per-level effect is taken from the source.
2. The source states the per-level plating value for combat hulls, and states whether the bonus is
   additive or multiplicative with Armour Technology.

Until then the value stays out of this spec and out of the module app tree.

## Guard

The guard test `HullPlatingIngestionGuardTest` fails when a numeric plating bonus appears in this
file or in the module app tree while Armour Technology is still uningested.

## Open questions

- What armour bonus per level does the host apply?
- Does the bonus apply to civilian hulls as well as combat ships?
- Is the bonus additive or multiplicative with Armour Technology?
