# Review record — social loop forms sides (21 September 2026)

Cheap, bounded, machine-parsable record per the [improvement loop](../specs/improvement-loop.md).

```json
{
  "window": "social-loop-sides",
  "date": "2026-09-21",
  "artifacts": {
    "bond": "app/Actions/RecordObservedAllianceMembershipStartAction.php",
    "shared_enemy": "app/Actions/RecordObservedBattleReportAction.php",
    "tests": "tests/Feature/CommittedChatObservationTest.php; tests/Feature/AllyUnderAttackObservationTest.php"
  },
  "counters": {
    "tests": 2,
    "passed": 2,
    "full_suite": "997/997",
    "gate2_findings": 0,
    "phpstan": "9 pre-existing (unchanged)"
  },
  "findings": [
    "the live trust/threat graph was flat — average trust 0.00, zero friends, zero enemies — despite 136 AI-vs-AI battles, 2-3 alliances and hundreds of exchanges",
    "cause was structural: only delivered compensation moved trust, and a battle moved threat only on a defender loss",
    "alliance membership now bonds the joiner and each co-member both ways (trust +0.15, affinity +0.20)",
    "an ally under attack now marks the attacker (threat +0.20, trust -0.05) and warms the ally (affinity +0.10)"
  ]
}
```

## What happened

The deterministic suite was green, but the live read of the running cohorts showed the social graph
never developed sides: `ai_relationships` averaged trust 0.00 and threat 0.03, with zero pairs at the
friend or enemy line. Contact (alliances, exchanges, chat, battles) was being recorded, but the only
thing that moved trust was a counterparty delivering promised compensation — rare in a closed AI
cohort — and battles only moved threat when the defender lost and the affect path fired. The result
was the exact "no proper friends and enemies" shape the owner had called out.

## The fix

Two bloc mechanics, added as small methods on the two existing actions that already observe the
events:

- **Alliance bond** — `RecordObservedAllianceMembershipStartAction::bondAllies` writes a mutual
  trust (+0.15) and affinity (+0.20) between the joining member and each co-member, because the
  alliance is itself the standing agreement. This is the one cooperative path that grants trust
  without a separately kept promise.
- **Shared enemy** — `RecordObservedBattleReportAction::recordAllyUnderAttackObservations` now
  writes, for each observing ally, threat (+0.20) and trust (−0.05) toward the attacker and affinity
  (+0.10) toward the attacked ally. Repeated attacks accumulate, turning an incident into a side.

## Verification

- New tests: the alliance bond (both directions, 0.15 trust / 0.20 affinity) and the shared enemy
  (0.20 threat toward the attacker, 0.10 affinity toward the ally) — both pass.
- Full suite: 997/997. Gate 2: 0 findings. Pint: clean. PHPStan: 9 pre-existing findings, unchanged.

## What stays unmeasured

- Whether the cohorts' running graph now actually crosses the friend/enemy lines is a live-observation
  question for the next window, not this one: the mechanisms are proven, the population still has to
  replay the events that use them.
