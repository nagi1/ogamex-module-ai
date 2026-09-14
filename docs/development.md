# AI module development and maintenance

The AI module is maintained as an independent Laravel module repository:

- Repository: https://github.com/nagi1/ogamex-module-ai
- Local installation: `OGameX/Modules/AI`
- Installation model: normal Git checkout
- Git submodules: not used

The module is developed separately, but its integration tests run inside the
OGameX application because the module depends on OGameX models, services,
middleware, events, and game actions.

## Repository model

The module repository is the canonical source for all AI-module code:

```text
OGameX repository
├── app/
├── config/
├── Modules/
│   ├── HelloWorld/
│   └── AI/                 # independent Git checkout, locally ignored
└── composer.json

AI module repository
├── app/
├── config/
├── database/
├── docs/
├── resources/
├── routes/
├── tests/
├── composer.json
└── module.json
```

The OGameX parent repository does not track the contents of `Modules/AI`.
This prevents module commits and OGameX commits from becoming mixed together.

## Installing the module

Install the module into an OGameX checkout with a normal clone:

```bash
cd /path/to/ogamex-next
git clone git@github.com:nagi1/ogamex-module-ai.git Modules/AI
composer dump-autoload
php artisan module:list
php artisan module:enable AI
```

The module is disabled by default. Enabling it changes the host application's
module status, not the module repository.

When updating an existing installation:

```bash
cd /path/to/ogamex-next/Modules/AI
git pull --ff-only

cd /path/to/ogamex-next
composer dump-autoload
php artisan optimize:clear
```

## Daily development loop

Develop from the module checkout:

```bash
cd /home/nagi/code/ogamex-next/Modules/AI

git pull --ff-only
# edit module files
bash scripts/ogamex test
```

The helper runs commands from the OGameX host while keeping the command short:

```bash
bash scripts/ogamex install              # host lifecycle: verify, migrate, hook, enable, caches
bash scripts/ogamex install --dry-run    # print the plan without changing anything
bash scripts/ogamex uninstall            # uninstall hook, disable, caches (data kept)
bash scripts/ogamex doctor               # read-only queue/container/Redis wiring report
bash scripts/ogamex enable               # raw status-file toggle only
bash scripts/ogamex disable              # raw status-file toggle only
bash scripts/ogamex artisan module:list
bash scripts/ogamex test
bash scripts/ogamex test-all
bash scripts/ogamex quality
bash scripts/ogamex e2e-lifecycle        # live host trial: absent, install, pickup, uninstall
bash scripts/ogamex artisan ai:reconcile-language-requests
bash scripts/ogamex artisan ai:language-conformance --confirm
```

`install`, `uninstall` and `doctor` are thin wrappers around the host's
`ogamex:module:*` commands, so the module never reimplements lifecycle logic:
the host verifies the runtime wiring, migrates the module's own migration path (which
works while the module is still disabled), runs the module's hooks, enables or
disables it, refreshes the compiled module cache, clears the application caches and
restarts the queue workers. `enable`/`disable` remain available for quick manual
toggling and touch nothing else. See
[`../../../docs/module-lifecycle.md`](../../../docs/module-lifecycle.md) for the
host-side contract and [`../../../docs/horizon.md`](../../../docs/horizon.md) for the
queue/container side.

`e2e-lifecycle` is the exception that is not a Pest run: it drives the real containers
and the real database through the whole life of the module — moved out of `Modules/`,
installed, picked up by supervisord and Horizon, uninstalled — and restores the tracked
`modules_statuses.json` and the module directory afterwards. It runs on the host
because it stops containers and moves directories. See
[`../../../docs/module-lifecycle.md`](../../../docs/module-lifecycle.md) for the 22
checks it makes.

`test`, `test-all` and `quality` run Pest **in parallel with `--bail`**; there is no
serial mode. Parallel workers are isolated behind their own database, so fail fast
never costs isolation:

- `--bail` stops at the first failing test instead of finishing a run that is
already known to be red.
- Every worker process gets its own database clone
  (`ParallelTestSchemaServiceProvider`), so tests cannot contend for rows.
- Tune the run with `PARALLEL_PROCESSES=4 bash scripts/ogamex test`; extra
  arguments (`--filter=<name>`, `--display-notices`) are forwarded to Pest and keep
  the parallel and bail flags.
- `bash scripts/ogamex coverage` is the one serial command: Laravel's parallel
  runner cannot merge PCOV coverage across workers, and PCOV needs the CLI opcode
  cache off.

A new module migration reaches the parallel run by itself: adding a migration file changes
`ParallelTestSchemaServiceProvider`'s schema version, so the next parallel run rebuilds its
`*_parallel_template` database and every worker clones it. The **serial** coverage run uses the
base database instead, so apply the module's migrations there once with the same command the
template builder uses:

```bash
cd local-docker-dev
docker compose exec -T ogamex-app php artisan migrate \
    --path=/var/www/Modules/AI/database/migrations --realpath --force
```

`php artisan migrate` and `php artisan module:migrate AI` both report "Nothing to migrate",
because the module does not register its migration path with the console.

Use a different OGameX checkout by setting `OGAMEX_ROOT`:

```bash
OGAMEX_ROOT=/path/to/ogamex-next bash scripts/ogamex test
```

When `local-docker-dev/` is already running, use its existing application
service instead of starting a separate Compose container:

```bash
OGAMEX_RUNNER=local-docker-dev bash scripts/ogamex test
```

The wrapper runs `docker compose exec -T ogamex-app` from
`local-docker-dev/`; it does not run `docker compose run` and never starts the
default OGameX stack. See the host project's
[`local-docker-dev/README.md`](../../../local-docker-dev/README.md) for the
environment setup.

Because the module checkout is physically inside the OGameX directory, the
existing OGameX Docker bind mount includes the module without an additional
mount or synchronization step.

## Operator tooling

The module's operator page lives at `admin/ai` and is reachable from the admin sidebar through
the host's `admin.nav` slot. It shows the staff switch, the configured limits, today's counters,
today's refusals and the newest recorded decisions, and it can replay a scenario shipped with
the module — a read that writes nothing.

```bash
php artisan ai:seed-test-universe --players=6 --confirm   # test/pilot accounts; refuses production
php artisan ai:explain-decision --player=42               # redacted decision explanation
php artisan ai:explain-decision --trace=118
php artisan ai:replay-scenario miner-under-visible-raid   # read-only, frozen time and seed
php artisan ai:pilot-report --days=1                      # outcomes, failures, lateness, tokens
php artisan ai:run-due-work                               # dispatches what the caps allow
```

Scenarios are JSON files under `resources/scenarios/`; the admin page can only replay a shipped
one, while the command also accepts a path to a file you wrote yourself. Every limit the module
owns lives in `config/population.php` plus `ai.language.daily_limits`, and a refusal is counted
per reason and day in `ai_stop_counters`, which is what the page and the pilot report read.
A pilot run needs three things that the module cannot arrange for itself: the module installed and
enabled (`bash scripts/ogamex install`), a queue worker actually running, and a universe that
already has a human account, because seeding refuses to create the first account in a universe.
`QUEUE_CONNECTION` is `database` in this stack, so Horizon is not provisioned and the AI lanes are
served by the plain worker pools: start the documented `ogamex-queue-worker` service (it sits behind
the `queue` profile) and its supervisor fragment picks the lanes up. Then seed, dispatch, and read
the window back:

```bash
bash scripts/ogamex artisan ai:seed-test-universe --players=10 --confirm
bash scripts/ogamex artisan ai:run-due-work
bash scripts/ogamex artisan ai:pilot-report --days=1
```

Seeded accounts are ordinary accounts: the host registration path gives them their planet,
resources and welcome message, so a fresh cohort pays the same early-game costs a human does and
will legitimately choose nothing until production has accumulated.
## Conversation operations

An account answers an inbound direct message when the session that already holds its lease
recognises the message as a known social exchange: a greeting, thanks, an apology, a warning, a
ceasefire or cooperation approach, or a trade offer. Anything the matcher cannot place is
answered with nothing, which is the intended default rather than a gap: the module never guesses
at intent, and none of this needs a provider.

`AI_CONVERSATION_ENABLED=false` stops the module answering at all while keeping every
observation, exchange and relationship record, so an ablation can compare a population that
talks against one that does not. `AI_CONVERSATION_REPLY_TTL_MINUTES` (default 180) bounds how
late a composed reply may still be sent.

A conversation is bounded at one response turn: after the account has answered twice in the same
conversation it goes quiet, so two automated neighbours cannot exchange messages indefinitely.

## Language operations

The language slice stays provider-off until `AI_LANGUAGE_ENABLED=true`. Two operator
commands keep its accounting honest and produce the conformance evidence the
[Laravel AI SDK integration](../plan/details/specs/laravel-ai-sdk.md) plan requires:

```bash
bash scripts/ogamex artisan ai:reconcile-language-requests
bash scripts/ogamex artisan ai:language-conformance --confirm
bash scripts/ogamex artisan ai:language-conformance --corpus --confirm
```

`ai:reconcile-language-requests` settles attempts whose provider completion can no
longer be observed. A timed-out request keeps its reservation `Reserved`, because the
provider may still have completed remotely; after
`AI_LANGUAGE_RECONCILIATION_MINUTES` (default 30) the attempt is charged at its
reserved maximum exactly once and the request stays `Uncertain`. A definite provider
failure and an invalid envelope settle immediately at the usage the provider
reported, so a reserved attempt is never released and never counted twice. The command
is scheduled every ten minutes while the module is enabled.

`ai:language-conformance` is the opt-in external run: it is the only module code that
may contact a real provider, it refuses to run without `--confirm`, and it sends
sanitized fixtures only — no player data, no private chat, no production identifiers.
`--corpus` sends all four labelled cases (three English interpretation cases plus a
prompt-injection case) instead of the first smoke case. Every run writes a JSON
artifact to `storage/app/ai-language-conformance/<timestamp>.json` holding the
selected provider/model/timeout, and per case: status, interpretation, character
count, proposed-candidate count, token usage, latency and the expected
interpretation/proposal. The report also carries the completed-case count and the
repeated-reply count, and the command exits non-zero when any case did not complete.
Provider credentials stay in the host environment; the module never stores a secret,
and CI never runs this command — the deterministic suite uses the SDK's agent fake
with stray prompts prevented.

Review the artifact against the recorded expectations before treating a provider run
as accepted. The command collects evidence; it does not score believability, prompt
quality or cost, and the reviewed thresholds stay the human gate from the validation
plan.

## Provider routing

Which vendor answers is module policy; the failover itself is the SDK's, because `laravel/ai`
already walks an ordered provider list and reports which rung it fell over from. Routing is off
until `AI_ROUTING_ENABLED=true`, and while it is off the single `ai.language.provider` / `model`
pair behaves exactly as it did before ladders existed.

`config/routing.php` holds three things: named peak `windows` in UTC, per-vendor `vendors`
metadata that says which window applies, and one ordered `ladders` list per
`AiLanguageTaskKind` (`conversation_reply`, `conformance`). A rung is a provider and a model and
may carry `during` => `peak` | `off_peak` | `any`:

```php
'ladders' => [
    'conversation_reply' => [
        // A free or flat-priced vendor takes the expensive half of DeepSeek's day.
        ['provider' => 'openrouter', 'model' => '<free-model>', 'during' => 'peak'],
        ['provider' => 'deepseek', 'model' => 'deepseek-flash'],
    ],
],
```

Three rules decide what a ladder ends up holding. A vendor whose key is absent from `ai.providers`
is dropped before the call, so a missing credential reads as that vendor switched off rather than
a round trip that must fail. An unknown vendor or a malformed window throws, because a rung
silently dropped for a typo is a vendor nobody can find out about. If nothing survives, the
configured `ai.language.provider` pair is the last resort, and if that has no key either the
ladder is empty: the reply action answers with the authored text and spends no attempt, and
`ai:language-conformance` refuses to start instead of contacting anyone.

## Cognition operations

The optional cognition sidecars stay absent until they are started explicitly, and nothing in
Phase 3 requires them:

```bash
docker compose -f Modules/AI/docker/cognition/docker-compose.yml up -d
```

```bash
AI_EXPERIENCE_DRIVER=cbrkit AI_COGNITION_DRIVER=fatima \
  bash scripts/ogamex artisan ai:cognition-conformance --confirm --iterations=30
```

`ai:cognition-conformance` is the opt-in measurement run for the driver acceptance gates. It is
the only module code that may contact a real cognition sidecar and it refuses to run without
`--confirm`. It measures each selected driver over a fixture casebase written inside a
transaction that is rolled back, so the operator's own records are untouched, and writes a JSON
artifact to `storage/app/ai-cognition-conformance/<timestamp>.json` holding per-driver
p50/p95/min/max latency, request and response bytes, the HTTP call count and the observed
failure modes.

Three details make its figures worth trusting, and each exists because the obvious version was
wrong:

- The byte and call counters come from Laravel's global request/response middleware, so they
  count the module's own traffic instead of repeating what a driver claims about itself.
- `http_calls` must reach the requested iteration count, and for FAtiMA the observed figure
  must differ from the native engine's. An equivalent ranking or the same emotion cannot
  distinguish a driver that answered from one that silently degraded to native, so a run where
  the driver never contributed fails even when the result looks right.
- The measurement selects the driver explicitly. With the module enabled,
  `config('ai.cognition.fatima.scenario_path', $default)` returned the stored `null` and ignored
  the default argument, which made the FAtiMA fixture resolve to the filesystem root and every
  appraisal degrade to native. That is what a measured run caught and a green suite did not.

CPU and memory are deliberately absent from the artifact: the application container cannot read
the sidecar's cgroup. Capture them on the host next to the run and record both together.

```bash
docker stats --no-stream --format '{{.Name}} cpu={{.CPUPerc}} mem={{.MemUsage}}' \
  ogamex-ai-cognition-cbrkit-1 ogamex-ai-cognition-fatima-1
```

## Composer and autoloading

The module's `composer.json` supplies its PSR-4 namespace. OGameX merges the
module Composer metadata through its existing `Modules/*/composer.json`
configuration.

After changing the module Composer metadata, run:

```bash
cd /path/to/ogamex-next
composer dump-autoload
```

Composer also supports local `path` repositories and symlinked development
packages. That is useful for generic Laravel packages, but the current OGameX
module loader expects modules under `Modules/`. Keep the AI module at
`Modules/AI` unless the OGameX loader is deliberately extended and tested.

Reference: https://getcomposer.org/doc/05-repositories.md#path

## Testing layers

Use the smallest test that proves the change:

1. Pure domain and decision behavior belongs in `tests/Unit`.
2. Module-to-OGameX behavior belongs in `tests/Feature`.
3. Core game rules remain tested in the OGameX repository.
4. Race-sensitive work must use OGameX's race-condition test commands.
5. Combat simulation must use the existing Rust battle engine rather than a
   second PHP rules implementation.

The module test suite is executed by the host application:

```bash
bash scripts/ogamex test
bash scripts/ogamex coverage
```

`scripts/e2e-queue-horizon.sh` is the container trial for the queue/container wiring
(Horizon lanes, supervisor pools, entrypoint hooks, Redis, install/uninstall including
`--drop-data`, and a real `ProcessAiWork` job executed by the module's own Horizon
lane via `scripts/e2e-dispatch-ai-job.php`). Run it inside the dev container, not from
the host shell:

```bash
docker compose exec -T ogamex-app bash /var/www/Modules/AI/scripts/e2e-queue-horizon.sh
```

It writes to throwaway status files and restores everything on exit, so the tracked
`modules_statuses.json` is never modified.

The suite is expected to finish in seconds, not minutes. When a run feels slow,
check the host notes in [`docs/testing.md`](../../../docs/testing.md): the two
usual causes are a serial run (no `--parallel`) and native prepared statements
on a high-latency database link, which the host exposes as
`DB_EMULATE_PREPARES`.

Two harness invariants explain almost every stall observed here, and both are enforced
by the runner rather than left to discipline:

1. **A wait that must expire cannot be measured with `Carbon::now()`.** Laravel's
   `Lock::block()` takes its deadline from it, and feature tests freeze the clock with
   `IsolatedAccountTestCase::travelTo()`, so the deadline never arrives and the retry
   loop spins forever. Bound such a wait with `microtime(true)`, which the test clock
   does not touch; `FatimaCognitionSession::acquireWithin()` is the reference
   implementation.
2. **`docker compose exec` does not forward signals.** A cancelled or timed-out run
   leaves its worker alive in the container still holding a transaction, and the host's
   one-second `innodb_lock_wait_timeout` then makes every later test fail: one stale
   lock that reads as a hang. `scripts/ogamex` therefore bounds each run with `timeout`
   inside the container (`TEST_TIMEOUT`, default 120; `COVERAGE_TIMEOUT`, default 600)
   and reaps leftover sessions with `scripts/ogamex reap` before `test`, `test-all`,
   `quality` and `coverage`.
3. **The module's status file is not part of the suite's contract.** These tests wire every
   module binding themselves, so they assume the AI module is not enabled in the host
   workspace. Enable it and the module's providers boot first: `HorizonServiceProvider` has
   already contributed `horizon.defaults`, which is why `AiHorizonConfigurationTest` then fails
   on a value it expects to have cleared. That is a status-file mismatch rather than a broken
   test, and it cuts both ways — no test may depend on the workspace status file to pass.

The application container ships neither `ps` nor `pkill`, so leftover workers are
cleared by database session instead of by process listing. A healthy full suite is
about 300 tests in thirteen seconds with four workers; a run that does not finish is a bug in
a test, not a capacity problem.

Before pushing a meaningful change, run the OGameX quality chain:

```bash
bash scripts/ogamex quality
bash scripts/ogamex test-all
```

The OGameX repository's required order is Rector, Pint, PHPStan, and tests.
Run the full project commands from the OGameX root when a change affects core
integration.

Laravel package authors commonly use an application-like test harness such as
Orchestra Testbench for framework-independent packages. The AI module's
integration tests still belong against OGameX because its real contract is the
OGameX application.

Reference: https://laravel.com/docs/packages#package-discovery

## Ownership boundary

The AI module owns:

- routine and session behavior
- legal player perception
- candidate intents and utility scoring
- memory and relationships
- response scheduling
- module-owned persistence
- adapters for optional human language; strategic advice remains a disabled later experiment

OGameX owns:

- authoritative game state
- game rules and legality
- resource accounting
- building and research queues
- fleet missions and combat execution
- shared domain events
- core database behavior

## Implementation standards

All module agents follow the durable [Nagi agent baseline](../AGENTS.md). It
is the module's Codex agent definition and repository memory: use descriptive,
small action-oriented code; early returns rather than `else`; brief
why/invariant comments only; real Laravel feature fixtures; and the module's
Pest 5/PCOV verification gates.

AI module code follows Laravel's normal extension patterns and is designed for
safe replacement over time. Depend on interfaces in jobs, commands, and domain
services; register concrete implementations in `AIServiceProvider`; and keep
framework-facing code thin. Do not put policy branches, database queries, or
game-rule calculations into controllers, commands, or jobs.

Use integer-backed enums for finite persisted categories, named constants or
value objects for every stable value, and migrations with indexes driven by the
actual query paths. Run focused parallel tests while developing, then the full
parallel suite before handoff.

## Container actions

Treat a job, command, or listener as a thin framework boundary. It resolves a
small module action contract through `app(Contract::class)`; the default action
is registered in `AIServiceProvider`. This keeps application behavior
replaceable in integration tests with `$this->app->instance(...)` and avoids
hard-coding concrete orchestration into queue delivery. Actions choose or
coordinate intent only—host action services still validate and execute game
rules.

Resolve every container-managed action, job, service, policy and collaborator
through `app()` (or `app()->makeWith()` when runtime scalar arguments are
required), in production code and tests. Do not instantiate those classes with
`new`; that would bypass Laravel bindings and make replacement/testing harder.
Use `new` only for simple value objects, enums' data, or other objects that are
deliberately outside the container.

## Domain identifiers

Use an enum for every stable AI domain value: persona, work and receipt state,
candidate type, capability, candidate reason/rejection, and defined action
outcome. A string is acceptable only for an external framework/database field,
serialized observation key, or a one-off dynamic value such as an exception
message. Feature tests assert enum values for defined behavior.

## Test realism

AI module feature tests use real OGameX models, services, database state,
queues, locks and validation paths. Do not use Mockery or routine mocks. A
container override is permitted only when the test specifically proves that a
replaceable package/action boundary works; keep that override narrow and test
the production implementation separately. Prefer production-like fixtures over
stubs, and treat a mock as an exceptional last resort that must be explained in
the test name.

## Agent test runs and coverage

Write all AI module tests in native Pest 5 syntax. Do not introduce PHPUnit
test classes or PHPUnit assertion methods in module tests; use Pest's `test`,
`expect`, hooks and named datasets so repeated policy cases remain one readable
behavior specification. Run changed-test selection with `--tia` after a full
green run; its baseline branch is explicitly `main` in `tests/Pest.php`. Keep Pest tooling in this module's `composer.json`; do not add it to
the OGameX host solely for AI tests.

Run static analysis with the module-local `phpstan.neon`. It intentionally
avoids host-wide suppression rules so the module gate remains independent of
unrelated host diagnostics.

Run agent-facing verification through Laravel PAO's compact result format and
PCOV—not Xdebug. PAO decides from the environment whether the process is an agent, and
`docker compose exec` does not inherit this shell's environment, so the runner passes
`AI_AGENT` through to the container (and `PAO_FORCE`/`PAO_DISABLE` when they are set).
A container command run by hand needs the same `-e AI_AGENT=...` or `-e PAO_FORCE=1`.
PAO is declared in this module's `composer.json` as well as the host's, so the module
owns its own test tooling; only the host install is on the test path today, because the
runner boots the suite through the host's Pest. Coverage is the one serial run: Laravel's
parallel runner cannot
merge PCOV coverage across workers. The runner owns the whole recipe, including the
module-scoped 100% gate:

```bash
bash scripts/ogamex coverage
# Module coverage (Modules/AI/app, excluding app/Rules): 1952/1952 = 100.00%
```

It switches the CLI opcode cache off (PCOV cannot instrument opcodes that opcache
interned before it was enabled), runs the suite through the host configuration and
then reduces the clover report to this module. `app/Rules` is out of scope because
those PHPStan rules are verified by the PHPStan gate. The command exits non-zero when
any module statement is untested.

Run static analysis with the module-local `phpstan.neon`.

The module `phpunit.xml` is the strict source filter; `pcov.directory` is
equally important because the container defaults PCOV to the host application
directory. Keep branch-focused feature fixtures alongside the PCOV line
coverage gate: PCOV does not expose a branch-coverage metric. The local
container is the test runtime; do not alter application source configuration
just to enable a coverage driver.

The module chooses intent. OGameX validates and executes only intents it can
already accept. When a capability is unavailable, keep the decision and trace
inside the module and use a record-only/no-op adapter; do not add an AI-specific
OGameX API. A host extension is exceptional and must be generic, reusable, and
independently justified.

## Branches, commits, and releases

Keep changes separated by repository and concern:

```text
AI feature work       → AI module branch and pull request
Missing core hook     → OGameX branch and separate pull request
```

Use small commits that describe one change. Do not mix unrelated OGameX
refactors into the AI module repository.

Use Git tags for installable module releases:

```bash
git tag -a v0.1.0 -m "AI module v0.1.0"
git push origin v0.1.0
```

Consumers can then install or update to an explicit release tag instead of
depending on an unknown moving commit.

## Maintenance checklist

Before merging a module change:

- module code is committed only to the AI repository
- module aliases, namespaces, and routes remain consistent
- module-disabled OGameX behavior is unchanged
- user-facing strings are translated
- tests are deterministic where randomness is involved
- no hidden game information is used
- no game rules are duplicated in the module
- module tests pass through OGameX
- the OGameX quality chain passes when integration changed
- the README and architecture docs still describe the real workflow

The module should remain small and understandable. Add framework machinery only
after a real AI-module requirement demonstrates that it is needed.

## Phase 3 planning and driver work

The [Phase 3 specification](../plan/details/specs/phase-3-cognition.md) is the
implementation reference for native cognition, social protocols, experience,
memory, context and language. Its [current-state assessment](../plan/details/research/phase-3-current-state.md)
and [decision history](../plan/details/DECISIONS.md) prevent obsolete scaffold
assumptions or discarded provider recommendations from being reintroduced.

Use small module-owned Laravel contracts with native/disabled implementations;
add a real external driver only through a focused compatibility and behavior
experiment. No framework extraction, universal planner or mandatory sidecar
stack is authorized. Keep future optional contracts unimplemented until they
have a concrete caller. Record driver versions, real Linux checks, state/swap
behavior and measured costs rather than assuming library claims are true.

The documentation-only planning revision requires Markdown/link/source and
consistency checks. It does not require a live LLM, dependency installation or
a claim that PHP gameplay tests were rerun. Subsequent implementation must pass
the existing Pest 5/PAO/PCOV/TIA and quality gates with real feature scenarios.

## References

- Laravel package development: https://laravel.com/docs/packages
- nWidart Laravel Modules: https://github.com/nWidart/laravel-modules
- nWidart module publishing: https://nwidart.com/laravel-modules/v6/advanced-tools/publishing-modules
- Composer path repositories: https://getcomposer.org/doc/05-repositories.md#path
