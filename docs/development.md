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

Use the Docker application service when the OGameX environment is running in
Docker:

```bash
OGAMEX_RUNNER=docker bash scripts/ogamex test
```

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

The module chooses intent. OGameX validates and executes it. If the module
needs a missing extension point, make that a separate, focused OGameX change.

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

