# Troubleshooting

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| `SIM ABORTED: ... no session has run` | a PHP error on every session; read the "Errors by kind" digest | fix the named error; common ones below |
| `Call to undefined method ...PlanetService::X` | module ahead of host (or the reverse) | pull both `main` branches; `composer dump-autoload` |
| `Class "Symfony\Component\Yaml\Yaml" not found` | module installed without its `symfony/yaml` requirement | `composer install` (the module declares it since 4 Oct 2026) |
| `UNIQUE constraint failed: ai_stop_counters...` | a date column looked up as a bare `Y-m-d` string on SQLite | look the date up as `->startOfDay()` or `whereDate` (see `RecordAiStopReasonAction`) |
| A SQL error only with `--in-memory` | MySQL-only SQL (`FIELD()`, `IF()`, `NOW()`, `UNIX_TIMESTAMP`) | rewrite portably (`CASE`, bind PHP `Date::now()`); never `NOW()` in SQL: it ignores simulated time |
| Two runs of the same seed differ | something drew randomness outside the container `Randomizer` (`random_int`, `rand`, `shuffle`, `array_rand`, unseeded battle) or read the wall clock | route it through `app(Random\Randomizer::class)`; find it by diffing `digest.php` outputs after 1 h, 2 h... |
| `Class "FFI" not found` on every battle or raid work item; errors climb after the first fights | the host PHP has no FFI extension, so the Rust battle engine cannot load (short runs never fight and look healthy) | run every `ai:sim` inside the `local-docker-dev` app container (`docker compose exec -T -e BROADCAST_CONNECTION=log -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e PHP=php ogamex-app bash Modules/AI/rl/scripts/generate.sh ...`); check a 100 h run reports 0 errors before any long one |
| Two runs of one seed differ, or session counts swing between runs | sims shared the app's cache, queue or broadcaster (database cache store, Reverb) | the `rl/scripts` and bench scripts export `BROADCAST_CONNECTION=log CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync`; set the same for a manual `ai:sim` |
| `teacher` index points at an illegal row | `choiceSets()` disagrees with `steps()` (a pass changed, a cap cut it) | `EconomyChoiceSeamTest`; make sure the teacher's object stays in the list (cap keeps it) |
| Recording changes the game | a side effect in `choiceSets()` / the encoder (writing, caching an instance) | both must only read; compare digests with and without `--record-choices` |
| `RL: N choice(s) fell back` with N > 0 | server not running, wrong socket path, timeout, model returns an illegal row, schema mismatch | start `ogrl.serve`; same `--choice-socket`; raise `AI_RL_SOCKET_TIMEOUT_MS`; check `serve.log` for errors |
| `ogrl.data`: different feature schema | files from two encoder versions | record again with one version; never concatenate |
| Very few choice points | accounts' queues are busy (normal), few accounts, short runs, or sessions erroring | more universes/days; check `sim-*.log` errors |
| Validation top-1 high, closed loop much worse | compounding errors: the model reaches states the planner never visits | DAgger (improving.md §2), epsilon data |
| Model always waits / never waits | class imbalance on row 0 or `eta_hours` scale | compare `wait_rate_*`; add wait-weighted loss or more epsilon data |
| ONNX export fails | exporter change in a new PyTorch | `export.py` tries the classic then the dynamo exporter; pin the PyTorch version that works and note it |
| GPU idle, training slow | data loading on CPU dominates | the dataset fits in memory; raise `--batch`; the MLP is tiny, CPU training is fine too |
