### FILE: resources/scenarios/inbound-attack.json
```json
{
    "name": "inbound-attack",
    "persona": "miner-standard",
    "observed_at": "2026-10-01T12:00:00Z",
    "input": {
        "resources": {
            "metal": 100000,
            "crystal": 100000,
            "deuterium": 100000
        },
        "ships": {
            "large_cargo": 5
        },
        "inbound_fleet": {
            "mission_type": 1,
            "lead_seconds": 150
        }
    },
    "decision_key": "fleet_save",
    "expect": {
        "action": "FleetSave"
    }
}
```