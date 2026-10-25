# M6: research order in the fast simulator (spec, 25 October 2026)

Goal: a model that picks the next research the way a good player does, trained in `rl/ogsim` and exact against the host
like the building model. One planet with its own research queue beside its building queue; research levels are
account-wide (single-account worlds in the catalogue). Gates: `next-steps.md` §1.

## Catalogue version 3 (the host's answers, no object named in code)

Everything below is asked of the host through `ObjectService`/`PlanetService` on the real planet, never typed in.

- `research`: every queueable research (`ObjectService::getResearchObjects()`), same row shape as an `objects` entry
  (`id`, `machine_name`, `requirements` by object id and level, `price[level-1]`, `build_seconds`, `build_seconds_grid`,
  `unlocks`) with `consumes_field: false`. Research time is read from the host with every time modifier at zero and then
  over the grid of modifier levels (the lab level and the research network are found by asking, as `timeModifiers` finds
  the building ones). Add `time_modifiers_research` shaped like `time_modifiers`.
- `planets[].production_research` (per planet: production depends on its position and temperature): for each production row the host gives, which researches change it: found by raising each
  research one level on the planet and comparing `getObjectProduction`. Shape:
  `{"<object id>": {"by": [research ids], "rows": [level][combo] = [metal, crystal, deuterium, energy]}}`, with
  `combo` the row-major index of the research levels in `by`, each 0..`research_grid_level` (default 12). A building with
  no research dependence is absent and uses `planets[].production` as before. Same for `storage` if the host ever makes it
  research dependent (it does not in OGameX; assert that instead of exporting).
- `planets[].levels` also holds the research levels of that planet's owner, by object id.
- `research_grid_level` at top level.
- Bump `VERSION` to 3. Existing building-only fields keep their meaning so the v2 simulator path still works when `research` is absent.

## Replay (`ai:rl-replay`)

Schedule steps gain `"queue": "research"`; the replay adds them through the host's `ResearchQueueService` and reports host
stock and host seconds exactly as it does for buildings. Parity target: 0.0000% stock gap and 0 build-time differences,
including research that raises production mid-run (plasma technology on the mines) and the lab level changing research time.

## Simulator (Rust, written beside the exporter)

Two queues on one clock: a building decision when the building queue is free, a research decision when the research queue is
free. Each queue's decision is its own choice kind and its own model; the other queue's pick is made by the player rule.
