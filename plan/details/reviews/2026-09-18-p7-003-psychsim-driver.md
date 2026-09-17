# Review record — P7-003 PsychSim diplomacy driver (18 September 2026)

Cheap, bounded, machine-parsable record per the [improvement loop](../specs/improvement-loop.md).

```json
{
  "window": "p7-003-psychsim-driver",
  "date": "2026-09-18",
  "artifacts": {
    "sidecar": "docker/cognition/psychsim/server.py",
    "driver": "app/Infrastructure/Cognition/PsychSimSocialCognition.php",
    "client": "app/Infrastructure/Cognition/PsychSimClient.php",
    "selector": "app/Support/SocialCognitionSelector.php",
    "enum": "app/Enums/AiCognitionDriver.php",
    "config": "config/cognition.php",
    "test": "tests/Feature/PsychSimSocialCognitionTest.php"
  },
  "counters": {
    "tests": 10,
    "passed": 10,
    "assertions": 17,
    "gate2_findings": 0,
    "provider_calls": 0,
    "sidecar_depth": 1,
    "counterparts_per_call": 1,
    "temptation_mapping": "threat * 2.0"
  },
  "findings": [
    "the sidecar's original symmetric Chicken game resolved to mutual defection regardless of payoff, so it was replaced with a sequential dilemma where the account moves first and the counterparty responds at depth one — the account's decision now varies with the counterparty's defection incentive (cooperate below ~1.1, defect above)",
    "the driver only withholds an acceptance the native rules would have granted; it never grants, so native/provider-off play is unchanged",
    "the default fatima binding is untouched; psychsim activates only when ai.cognition.driver = psychsim",
    "the module maps its own threat rating into the counterparty's defection incentive, keeping OGame concepts out of the sidecar wire format"
  ]
}
```

## What happened

The PsychSim sidecar (deployed Wave 3.2) was a fixed Chicken game whose depth-one output did not vary
with the relationship, so the module driver would have been a forwarding no-op. The sidecar contract was
replaced with a sequential cooperation dilemma and the module driver (`PsychSimSocialCognition`) now maps
the exchange's `threat` into the counterparty's defection incentive and withholds an acceptance only when
the depth-one model says the counterparty would exploit it — the named play of a wary account that will
not warm to a counterparty it rates more than half threatening.

## Verification

- `tests/Feature/PsychSimSocialCognitionTest.php` — 10 tests / 17 assertions passed (host runner; the
  container runner was unavailable at the time due to a host-MySQL reverse-DNS regression, unrelated to
  this change).
- Gate 2 review (`scripts/ogamex gate`) — clean, zero must-fix findings.
- Pint (`--test`) — clean on all changed files.
