# Relational memory — friends don't forget, foes can become friends

Written 20 Sep 2026. Closes the gap between `phase-3-cognition.md` ("an AI remembers who
helped or harmed it … a request from a trusted ally can produce a different social response
from the same request by a past betrayer") and what actually ships.

## What ships now

`AiRelationship` holds five bounded 0..1 scores (`trust`, `threat`, `affinity`, `respect`,
`social_importance`) but only inbound **chat** ever moved them, via
`ContactImpactPolicy`. Trust had no upward path and no downward path from gameplay, so a
raider the AI had never chatted with was indistinguishable from a stranger. The
`AiMemoryPredicate` taxonomy (`AllianceMembership`, `ResourceDebt`) could not express "who
attacked me", so flow 3's "load the betrayal from native recall" had no fact to load.

## Shipped: DEF-011 — a losing battle scars the relationship and is remembered

`AppraiseObservedBattleReportAction` now, when an enabled AI comes off worse (the same
trigger that already records an emotional episode), also:

1. writes the relationship toward the attacker through the existing
   `RecordAiRelationshipInteractionAction` — `trust -0.20`, `threat +0.30`, `affinity -0.10`
   (bounded 0..1); and
2. records an `AttackReceived` fact (`AiMemoryPredicate::AttackReceived`, `Verified`) keyed
   on the observation, subject = attacker, so native recall can later surface it.

Both are guarded by the episode's `wasRecentlyCreated`, so a replayed reduction never
scars twice, and the relationship writer is already idempotent on the observation.

The relationship scar is consumed **today**: `NativeSocialCognition::standingWeight()` reads
`trust/threat/affinity`, so a raided AI is measurably colder to its raider in every later
apology/ceasefire/cooperation decision. Repeated raids escalate `threat` toward the
`0.75` reject threshold — "don't forget" accumulates instead of resetting.

## Shipped: DEF-012 — a fulfilled counterparty promise earns trust back

`FulfillAiCommitmentAction` now, on the Accepted → Fulfilled transition of an
`ExpectedFromCounterparty` commitment (the counterparty owed the AI something and delivered),
writes the relationship through the existing `RecordAiRelationshipInteractionAction`:
`trust +0.20`, `threat −0.15`, `affinity +0.10`. A promise the AI itself kept
(`PromisedByPlayer`) says nothing about the counterparty and repairs nothing, and an Expired
commitment never repairs. This is the genuine-amends half of "foes become friends": the only
way trust rises is a promise actually honoured.

## Shipped: DEF-014 — forgiveness is earned, never spoken

The apology decision previously accepted at `standingWeight >= 0.5`, where `standingWeight`
folded in `affinity`, `respect` and `socialImportance` — all three inflate from cheap contact
(an apology itself granted `affinity +0.02, respect +0.05, socialImportance +0.05`). A raider
could therefore destroy a fleet and grind the AI back to acceptance with repeated apologies
and greetings, without ever repairing anything. `evaluateApology` now gates acceptance on a
`forgivenessScore = trust − anger` against a `0.5` floor: trust rises only via DEF-012, so no
volume of polite words forgives a betrayal, while transient anger still sustains the grudge
until it fades. Warmth still gates *cooperation* (help/cooperation requests) through
`standingWeight`, but it can never forgive.

## Remaining slices

### DEF-013 — Relationship stance modulates reply wording

The same response (accept/decline) currently uses the same authored line for a friend and a
foe. Derive a coarse stance from the scores and pick a warm/neutral/cold variant.

- **Files**: a tiny stance lookup (e.g. `friend/neutral/foe` from `trust`/`threat`
  thresholds) consumed by `BuildAuthoredSocialReplyAction`; tests.
- **Behaviour**: an Accept to a high-trust counterparty uses the warm line, to a high-threat
  counterparty the cold line, otherwise the existing line. Thresholds are module taste; the
  scores are relationship state.
- **Proof**: same decision, different wording across friend/foe.
- **Gate**: gate 3 — players talk differently to friends than enemies; gate 2 — one lookup
  table, no new transport. Lower priority than DEF-012 (authenticity is measured by
  behaviour before wording — `account-authenticity.md`).

## Non-goals

- Per-person emotional memory (an `angry_at` column). Out of scope: the single scalar
  affect state is deliberate, and the relationship score is the per-person signal that
  already reaches decisions.
- A full friend/neutral/foe state machine beyond the DEF-013 lookup. No social-state
  promotion without an evidenced consumer.
- Any new driver or LLM call. This is native structured cognition, exactly the kind that
  stays in the module with zero generative calls.
