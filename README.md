# OGameX AI module

The `AI` module adds a home for persistent, believable AI players in OGameX.
This repository contains the module only. OGameX remains responsible for game
rules, legal state, validation, and execution.

The module is intentionally a foundation at this stage. It includes a
module-local boot page, configuration, translations, an isolated feature test,
and the domain areas where AI behavior can grow. It does not yet implement AI
accounts or gameplay decisions.

## OGameX integration

This repository is a separately installed module repository. It is checked out
inside the OGameX module directory:

```text
OGameX/
└── Modules/
    └── AI/  # this repository
```

This is a normal independent Git checkout, not a Git submodule. Install it
with a regular clone:

```bash
git clone git@github.com:nagi1/ogamex-module-ai.git /path/to/ogamex-next/Modules/AI
```

The module uses the OGameX module conventions documented in
`OGameX/docs/modules.md` and follows `Modules/HelloWorld` as its reference.
See [docs/development.md](docs/development.md) for the complete development
and maintenance workflow, including installation, testing, releases, and
repository ownership.

## Development loop

Run these commands from this repository when it is checked out at
`OGameX/Modules/AI`:

```bash
# The module is disabled by default. Enable it for manual browser checks.
bash scripts/ogamex enable

# Run only this module's tests through the OGameX application.
bash scripts/ogamex test

# Run an individual test or pass normal Pest arguments.
bash scripts/ogamex test --filter=AI

# Run any Artisan command in the OGameX application.
bash scripts/ogamex artisan module:list

# Disable the module after manual checks.
bash scripts/ogamex disable
```

Set `OGAMEX_ROOT` when the OGameX checkout is elsewhere:

```bash
OGAMEX_ROOT=/path/to/ogamex-next bash scripts/ogamex test
```

The wrapper uses host PHP by default. Set `OGAMEX_RUNNER=docker` to run the
same commands through OGameX's `ogamex-app` service:

```bash
OGAMEX_RUNNER=docker bash scripts/ogamex test
```

After changing `composer.json`, refresh the OGameX autoloader from the OGameX
root:

```bash
cd /path/to/ogamex-next
composer dump-autoload
```

## Structure

```text
app/
├── Console/Commands/       # module Artisan commands
├── Domain/
│   ├── Decision/            # intent, candidates, scoring
│   ├── Memory/              # persistent memories and relationships
│   ├── Perception/          # legal player-visible state
│   ├── Routine/             # timezone, sessions, activity, delays
│   └── Scheduling/          # due work and wakeups
├── Http/Controllers/        # module HTTP entry points
├── Jobs/                    # short, retry-safe AI work
├── Listeners/               # core event reactions
├── Models/                  # module-owned persistence
├── Providers/               # registration only
└── Support/                 # module infrastructure and test helpers
config/                      # module configuration
database/migrations/         # module-owned schema changes
lang/en/                     # translations
resources/views/             # module views
routes/                      # module routes
tests/Feature/               # OGameX integration tests
tests/Unit/                  # deterministic decision/domain tests
```

## Architecture boundary

The module decides intent. OGameX executes legal game actions. The module must
not duplicate resource, building, research, fleet, or combat rules, and it
must only build perception from information the player could legally know.

The existing Rust battle engine remains the source of battle outcomes when
combat simulation is introduced. Laravel remains responsible for orchestration,
scheduling, perception, decision policy, memory, and execution through OGameX.
