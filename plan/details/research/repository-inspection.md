# Repository inspection — 10 September 2026

Read-only source inspection; no application tests or benchmarks were run. Both repositories had clean working trees before these documentation changes. Dependency versions below are manifest requirements, not a claim about the running environment.

Host: /home/nagi/code/ogamex-next, commit 350657a3ef932ba98e904d5d161b22e87de6cedc. Independent module: Modules/AI, commit 45f8a2760b15366520f3efc47b6d9b2c90b4c160.

## What already exists

| Evidence | Observed state |
|---|---|
| Host README, composer manifest and [module guide](https://github.com/nagi1/ogamex-next/blob/350657a3ef932ba98e904d5d161b22e87de6cedc/docs/modules.md) | OGameX Next; PHP 8.5, Laravel 13 and nWidart Modules 13 requirements. Existing module loader and enabled-state file. |
| [AI README](https://github.com/nagi1/ogamex-module-ai/blob/45f8a2760b15366520f3efc47b6d9b2c90b4c160/README.md), docs/development.md, provider and test | Independent module checkout, provider, admin page, translations, helper script and isolated-status test. Domain directories remain placeholders. |
| Host app/Events/Game | PlayerCreated, PlanetCreated, BuildingCompleted, ResearchCompleted, FleetMissionArrived and BattleResolved are implemented. |
| ModuleSlotService | Only admin.nav is registered; no in-game navigation slot yet. |
| HasModuleData and ModuleEntityDataStore | Player/planet metadata exists. Namespace is supplied by callers; it is not enforced module authorization. Alliance model has no matching helper. |
| DomainEventsTest, DomainFleetMissionEventTest, DomainBattleEventTest | Event payload tests exist; reviewed assertions do not establish rollback suppression or durable delivery. |

## Concrete gaps

The host's [extension-point draft](https://github.com/nagi1/ogamex-next/blob/350657a3ef932ba98e904d5d161b22e87de6cedc/docs/module-extension-points-plan.md) says only chat events exist and proposes entity metadata; implementation has overtaken it. Its proposed battle payload also differs from the shipped class. Treat source as current evidence and reconcile the draft/documentation.

- **Battle lifecycle:** app/GameMissions/BattleEngine/BattleEngine.php, around lines 118–142 and 300, reads live defender services and emits BattleResolved during calculation. The payload has attacker IDs, defender ID and planet ID, not mission/report ID. Repeated AI estimates cannot safely treat this entry point as a pure legal-intel estimator or committed result feed.
- **Transactions:** FleetMissionService emits FleetMissionArrived around line 667 within processing that can be wrapped by the destination transaction around line 767. The event classes do not themselves implement after-commit dispatch, and reviewed queue connections default after-commit dispatch to false. Inspect each path and test rollback behavior.
- **Action parity:** FleetController performs speed, expedition-duration and ACS checks before dispatch. BuildingQueueService.add contains an ownership-check TODO. Calling a low-level service alone does not establish full human-path authorization.
- **Actor context:** GlobalGame binds PlayerService from the authenticated request, refreshes player/current planet/fleets and processes moves. Workers need equivalent supported refresh without shared request identity.
- **Read boundaries:** GalaxyController builds galaxy output; FleetEventsController uses IncomingFleetIntelService. Reuse those rules through supported actor-scoped reads rather than querying hidden enemy state.
- **Social lifecycle:** ChatService broadcasts ChatMessageSent, while MessageService persists report/system messages separately. No recipient-delivery or alliance membership events were found in the reviewed event set.

These findings become scoped work items in [extension-point work](../specs/module-extension-points.md). No claim is made that every existing action or core mechanic has been audited.
