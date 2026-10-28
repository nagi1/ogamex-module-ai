# Growing-account fidelity inventory

Paths are relative to the host checkout. `missing` means no directly identified host-versus-simulator
parity test for this specific rule; it does not by itself mean the simulator has no implementation.
This inventory is a starting audit, not evidence that every edge case is covered.

| Mechanic | Host | Simulator | Parity evidence or gap |
| --- | --- | --- | --- |
| Field limits and field-adding upgrades | `app/Services/PlanetService.php:709`, `:2603` | `Modules/AI/rl/ogsim/src/economy.rs:596`, `:615` | `economy.rs:1040` exercises the exported field addition; direct host parity missing. |
| Storage capacity | `app/Services/PlanetService.php:2697` | `Modules/AI/rl/ogsim/src/economy.rs:552` | missing: host comparison across storage levels. |
| Production at a full store | `app/Services/PlanetService.php:1428` | `Modules/AI/rl/ogsim/src/economy.rs:571` | missing: direct host parity, including stock already above capacity. |
| Uncapped delivery | `app/Services/PlanetService.php:1547`; `app/GameMissions/TransportMission.php:66` | `Modules/AI/rl/ogsim/src/world.rs:709` | `world.rs:1718` replays host missions; missing: explicit above-cap delivery case. |
| Energy factor and fuel depletion | `app/Services/PlanetService.php:2281`, `:2481` | `Modules/AI/rl/ogsim/src/economy.rs:496` | missing: direct parity at empty fuel and changing energy supply. |
| Research production bonuses, including plasma | `app/Services/PlanetService.php:2229`, `:2369` | `Modules/AI/app/Actions/ExportAiEconomyCatalogueAction.php:426`; `Modules/AI/rl/ogsim/src/economy.rs:453` | missing: direct host comparison across research levels and combinations. |
| Character-class production bonuses | `app/Services/PlanetService.php:2229` | `Modules/AI/app/Actions/ExportAiEconomyCatalogueAction.php:70`; `Modules/AI/rl/ogsim/src/economy.rs:496` | missing: cross-class host parity for production. |
| Research cost paid on the chosen planet | `app/Services/ResearchQueueService.php:110`, `:303` | `Modules/AI/rl/ogsim/src/world.rs:486`; `Modules/AI/rl/ogsim/src/economy.rs:823` | `world.rs:1284` checks shared research, but missing: host parity for the paying planet. |
| Building and research queues | `app/Services/BuildingQueueService.php:81`, `:318`; `app/Services/ResearchQueueService.php:110` | `Modules/AI/rl/ogsim/src/economy.rs:724`, `:823` | missing: host comparison of queue limits, including account bonuses. |
| Yard blocked during an upgrade | `app/Services/UnitQueueService.php:158` | `Modules/AI/rl/ogsim/src/units.rs:288` | `units.rs:411` replays host orders; `units.rs:469` checks the upgrade refusal. |
| Export maximum level | `Modules/AI/app/Console/Commands/ExportAiRlCatalogue.php:23`; `Modules/AI/app/Actions/ExportAiEconomyCatalogueAction.php:70` | `Modules/AI/rl/ogsim/src/economy.rs:615` | missing: test of behavior at and beyond the exported table limit. |
| Colonisation limits and site fields | `app/GameMissions/ColonisationMission.php:42`; `app/Services/PlanetService.php:709` | `Modules/AI/rl/ogsim/src/world.rs:646`, `:831` | `world.rs:1718` replays host missions; missing: host parity across sites and account limits. |
| Fleet dispatch, travel, fuel and cargo | `app/Services/FleetMissionService.php:95`, `:215`; `app/GameMissions/TransportMission.php:66` | `Modules/AI/rl/ogsim/src/world.rs:627`, `:709` | `world.rs:1718` replays host missions. |
| Combat, debris and moon creation | `app/GameMissions/AttackMission.php:305`, `:436` | `Modules/AI/rl/ogsim/src/world.rs:992`; `Modules/AI/rl/ogsim/src/combat.rs:709` | `combat.rs:709`, `:738` compare outcomes and distributions; missing: direct host parity for moon/debris settlement. |
| Unit production and energy | `app/Services/PlanetService.php:2281` | `Modules/AI/rl/ogsim/src/economy.rs:496` | `economy.rs:1012` checks composition; missing: host comparison across unit counts and energy boundaries. |
| Score calculation | `app/Services/HighscoreService.php:56` | `Modules/AI/rl/ogsim/src/score.rs:236` | `score.rs:236` compares with host scores. |
| Account and colony clocks | `app/Services/PlanetService.php:1428` | `Modules/AI/rl/ogsim/src/world.rs:373`, `:505` | missing: host parity through idle periods and horizon; row 47 is in progress. |
