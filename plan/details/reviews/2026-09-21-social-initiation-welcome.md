# Review record — a member welcomes a new ally (21 September 2026)

Cheap, bounded, machine-parsable record per the [improvement loop](../specs/improvement-loop.md).

```json
{
  "window": "social-initiation-welcome",
  "date": "2026-09-21",
  "artifacts": {
    "wiring": "app/Actions/InitiateAiSocialContactAction.php",
    "tests": "tests/Feature/SocialInitiationTest.php"
  },
  "counters": {
    "tests": 2,
    "passed": 2,
    "full_suite": "1005/1005",
    "gate2_findings": 0,
    "phpstan": "9 pre-existing (unchanged)",
    "rector_changed_files": 0
  },
  "findings": [
    "grand produced zero social exchanges in a full watched hour — the loop was alive but nobody spoke",
    "AllianceMembershipJoined observations were written for every co-member and never consumed",
    "a member now greets a newly-joined ally through the authored pipeline, gated on sociability >= 0.5",
    "the greeting is keyed on the membership observation, so it fires once per joiner"
  ]
}
```

## What happened

The one-hour live read of the running cohorts showed the social loop was effectively dead: grand
produced zero exchanges and zero replies in sixty minutes, while building completions kept flowing.
The accounts were playing but not talking.

## The root cause

`RecordObservedAllianceMembershipStartAction` writes an `AllianceMembershipJoined` observation for
every AI co-member and bonds them (trust +0.15, affinity +0.20), but nothing ever consumed that
observation. The leader's welcome (`DEF-007`) fires only on the application-accept path, so an
ordinary member never greeted a newcomer. With no inbound messages and no transfers, the whole
conversation surface stayed empty.

## The fix

`InitiateAiSocialContactAction` now greets a newly-joined member through the same authored pipeline
it uses to thank a transfer, gated on `sociability >= 0.5`: a chatty member says hello, a quiet one
stays silent. The greeting is a `Greeting` exchange keyed on the membership observation, so the
existing "an exchange is the marker" rule makes it fire exactly once per joiner, and the same join
reads as a hello from some members and silence from others — the persona divergence the owner asked
for.

## The check

`SocialInitiationTest` proves a chatty member welcomes while a quiet one stays silent, and that the
welcome is idempotent. Full suite 1005/1005, Gate 2 clean, PHPStan unchanged at 9 pre-existing
findings, Rector dry-run clean.
