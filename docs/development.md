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
bash scripts/ogamex artisan module:list
bash scripts/ogamex enable
bash scripts/ogamex disable
bash scripts/ogamex test
bash scripts/ogamex test-all
bash scripts/ogamex quality
```

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
```

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
- adapters for optional chat or strategic advice

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
PCOV—not Xdebug. In the existing `local-docker-dev` service, use the module
configuration and point PCOV at the module source explicitly:

```bash
PAO_FORCE=1 php -d pcov.enabled=1 \
  -d pcov.directory=/var/www/Modules/AI/app \
  ./vendor/bin/pest --configuration=Modules/AI/phpunit.xml --coverage --exactly=100
```

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

## References

- Laravel package development: https://laravel.com/docs/packages
- nWidart Laravel Modules: https://github.com/nWidart/laravel-modules
- nWidart module publishing: https://nwidart.com/laravel-modules/v6/advanced-tools/publishing-modules
- Composer path repositories: https://getcomposer.org/doc/05-repositories.md#path
