# Defended-raid loot tier — measurement before the change (RV-003)

**Date:** 17 September 2026 · **Task:** `RV-003` (review) · **Decision:** keep `RaidPlanner::LOOT_TIER_DEFENDED = 2.0`, do not change it yet.

## Question

`RaidPlanner::clearsLootTier()` lets a defended run fly on loot/fuel ≥ 2.0 where a farm needs ≥ 3.0,
because "the debris subsidises it". `RAID-014` decided debris stays *out* of the single-raid profit
gate. Does the looser tier admit a defended run the host's debris and defence-repair values do not pay
for, or refuse one they do?

## Method — one bounded read over the artifacts the module already writes

`ai_action_receipts` (queue + refusal only) and `ai_experience_cases` (battle outcomes), read from the
live `ogamex-grand` universe. No writes, no new collection.

## Before figure

| Artifact | Reading |
| --- | --- |
| Raid receipts | **1,587** (`action_type = 4` with a `decision.target_galaxy`) across **18** players |
| … completed | **1,033** (`state = 4`, reason `queued`) |
| … refused | **554** — 361 fleet-slot cap, 155 target active at dispatch, 33 missing probe, 5 no resources |
| Raid battle outcomes | **0** — `ai_experience_cases` holds no `Raid`-family rows (only `BuildingUpgrade`) |

The module records that a raid was **queued** and whether it was **refused**, but nothing records the
per-raid **outcome** — loot, debris, or defence repair. There is therefore no defended-vs-farm split
in the module's own artifacts and no debris/repair before-figure to test the 2.0 tier against.

## Verdict — keep the constant

The tier is an **unmeasured prior**, not a measured value: 1,033 raids flew, and none of their
outcomes is on the module's books, so nothing contradicts the constant and nothing confirms it. The
named play ("do I hit the turtle for the debris?") is ordinary; the constant errs on the side of
admitting that run, which is the safe side to err on while it is unmeasured.

Two facts bound the future decision, neither of which changes today:

- The debris path is a **separate trip** (`FLE-011`), so the subsidy the 2.0 tier assumes is collected
  later by a recycler, never folded into the raid's own loot — which is exactly why `RAID-014` keeps
  debris out of the single-raid profit gate.
- The host's own debris fraction (≈ 0.3 of destroyed hulls, `DebrisFactor`) and its defence-repair
  factor are the numbers a future change must be checked against.

## Ceiling (named)

`ponytail:` the module has no per-raid outcome record, so the defended tier cannot close the loop. The
upgrade path is `RV-006` — either decide the tier on the host's own debris/repair arithmetic on a
frozen clock, or wait until the outcome appraisal records raid results and re-measure.

**Recommendation:** keep `LOOT_TIER_DEFENDED = 2.0`; re-open when defended raids have recorded
outcomes, and let `RV-006` make the change with a measured before/after.
