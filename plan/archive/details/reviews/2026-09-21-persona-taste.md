# Review record — persona taste (21 September 2026)

Cheap, bounded, machine-parsable record per the [improvement loop](../specs/improvement-loop.md).

```json
{
  "window": "persona-taste",
  "date": "2026-09-21",
  "artifacts": {
    "taste": "app/Domain/Persona/PersonaTaste.php",
    "wiring": "app/Domain/Decision/QueueableFleetSavePlanner.php; app/Domain/Routine/RoutineProfile.php; app/Actions/InitiateAiSocialContactAction.php",
    "tests": "tests/Feature/PersonaTasteTest.php; tests/Feature/ProactiveSaveTest.php; tests/Feature/RoutineCadenceTest.php; tests/Feature/SocialInitiationTest.php",
    "spec": "plan/details/specs/persona-taste.md"
  },
  "counters": {
    "tests": 3,
    "passed": 3,
    "full_suite": "1003/1003",
    "gate2_findings": 0,
    "phpstan": "9 pre-existing (unchanged)",
    "rector_changed_files": 0
  },
  "findings": [
    "the persona was categorical (5 archetypes x 3 skill bands), so accounts of one archetype read as copies",
    "PersonaTaste derives three continuous seeded dimensions (diligence, aggression, sociability) deterministically per account",
    "aggression is wired into the fleetsave exposure band (proactive only), so a bold account leaves a larger fleet unsaved",
    "diligence places sessionsPerDay inside the archetype's own presence band, so a lazy miner plays the floor and a diligent one the ceiling",
    "sociability caps the thank-yous one session sends, so a chatty account works a whole pile and a quiet one thanks one sender",
    "the dark-period test tripped on a latent artifact: the silence after the last simulated session was never counted"
  ]
}
```

## What happened

The owner's day-one acceptance is that 100 accounts must be different players, not the same persona
100 times. The persona was categorical, so two Miners played identically apart from a few seeded
nudges. This slice adds the missing continuous half: `PersonaTaste`, a value object that derives
`diligence`, `aggression` and `sociability` from the account's `random_seed` through the same
`RandomSource` as every other per-account draw.

## The wiring

- **aggression → save threshold.** `QueueableFleetSavePlanner::exposureBand` scales the archetype
  base by `0.5 + aggression`, neutral at 0.5, proactive saves only. The reactive save under an
  inbound keeps the base band, so "a save fires under attack" is never traded for taste.
- **diligence → cadence.** `RoutineProfile::sessionsPerDay` places the account inside its own
  archetype presence band (`[fewest, most]` from the analogue benchmark). The band itself is the
  guard: a lazy miner plays the floor, a diligent one the ceiling, and no account leaves the band
  that the cadence tests measure.
- **sociability → initiation.** `InitiateAiSocialContactAction` caps the thank-yous one session
  sends at `round(5 × sociability)`, floored at one. The reply-turn cap in
  `RunAiConversationCycleAction` stays fixed: sociability belongs on the *initiation* surface, not
  inside the bounded reply protocol (the earlier reply-turn wiring broke the loop-prevention test).

## The test fix

The dark-period measurement in `RoutineCadenceTest` had a latent artifact: the silence after the
last simulated session is a night too, but the coverage only counted gaps between consecutive
sessions. An account that went absent right after an early-morning session on the last day left that
day with one session and no following gap, a false positive. The coverage now extends to the end of
the run, which is correct regardless of how the cadence target changes.

## The check

`PersonaTasteTest` proves determinism, bounds and per-seed divergence, and that two miners play a
different number of sessions a day. `SocialInitiationTest` proves a chatty account thanks a pile of
senders while a quiet one thanks one. `RoutineCadenceTest` holds the dark-period, presence, absence
and heavy-tail guarantees for the band-bounded cadence. Full suite 1003/1003, Gate 2 clean, PHPStan
unchanged at 9 pre-existing findings, Rector dry-run clean.
