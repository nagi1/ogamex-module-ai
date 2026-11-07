# Review record — P7-001 colony trigger (18 September 2026)

Cheap, bounded, machine-parsable record per the [improvement loop](../specs/improvement-loop.md).

```json
{
  "window": "p7-001-colony-trigger",
  "date": "2026-09-18",
  "artifacts": {
    "trigger": "app/Enums/AiCampaignConsultationTrigger.php",
    "config": "config/campaign-consultation.php",
    "action": "app/Actions/RecordAiColonyCampaignSignalAction.php",
    "listener": "app/Listeners/RecordAiColonyCampaignSignal.php",
    "provider": "app/Providers/AIServiceProvider.php",
    "test": "tests/Feature/CampaignColonyTriggerTest.php"
  },
  "counters": {
    "tests": 5,
    "passed": 5,
    "assertions": 5,
    "gate2_findings": 0,
    "provider_calls": 0
  },
  "findings": [
    "a coalition member landing a colony signals every active campaign with new_colony",
    "the homeworld, a moon, and a non-member colony never signal",
    "the trigger is admitted only when the operator keep it in the allowlist (new_colony added)",
    "war declaration stays unbuilt: the host has no war-declaration seam"
  ]
}
```

## The player-visible gap this closes

An active campaign consults on fleet losses, setbacks, contested objectives, coalition conflict, phase
changes and rank changes — but not on a coalition member colonising a new planet. A human observer
watching the campaign page sees the coalition's economy and stronghold positions change while the
campaign's own view stays frozen: the lane never consults on the single most visible mid-game economic
event an account makes. The `new_colony` trigger closes that gap by recording the same campaign signal
the other six triggers use.

## What happened

`AiCampaignConsultationTrigger::NewColony` was added to the trigger allowlist, and a `PlanetCreated`
listener records the signal for every active campaign when the event is a colony — a second-or-later
planet, never a homeworld, moon or debris field, and only for an enabled coalition member.

## Verification

- `tests/Feature/CampaignColonyTriggerTest.php` — 5 tests / 5 assertions passed (dev-stack parallel
  runner).
- `bash scripts/ogamex gate` — 0 findings; Pint on the touched files passed.
- The war trigger is recorded as dropped: the host has no war-declaration feature, and adding one is a
  paired host PR, not module work.
