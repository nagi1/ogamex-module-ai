### FILE: resources/scenarios/expedition-empty-returns-at-limit.json
```json
{
    "name": "expedition-empty-returns-at-limit",
    "situation": "An expedition returns with an empty hold while the account sits at its expedition slot limit; the reader records the empty outcome, the returning mission frees its slot, and the planner must fill that slot again instead of going quiet.",
    "status": "unverified",
    "persona": "balanced",
    "input": {
        "player_id": 1,
        "astrophysics": 1,
        "small_cargo": 3,
        "metal": 10000,
        "crystal": 10000,
        "deuterium": 10000,
        "expedition_slots_max": 1,
        "expedition_returns": 1,
        "expedition_outcome": "empty"
    },
    "decision_key": "expedition",
    "expect": {
        "action": "QueueAiExpedition"
    }
}
```