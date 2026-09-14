# OGameX AI module

The `AI` module adds a home for persistent, believable AI players in OGameX.
This repository contains the module only. OGameX remains responsible for game
rules, legal state, validation, and execution.

The module includes the Phase 1 building adapter and Phase 2 deterministic
profiles, perception, session scheduling and recorded decision intents. Broader
gameplay capabilities remain conditional on validated action adapters.

Phase 3 is implemented: source-backed facts, claims, relationships, commitments
and affect, typed zero-LLM social protocols with authored replies, outcome-based
experience, a sealed host delivery ledger, bounded context and atomic usage
ledgers, a deterministic conversation cycle, and an optional provider escalation
that is off by default. FAtiMA/CiF, CBRKit and AgentOS are implemented and opt-in
behind module configuration, with the native path as the default and the fallback;
none of them has cleared its activation gate yet. Ordinary gameplay, structured
exchanges and AI-to-AI replies make no generative call.

Phase 4 operability is implemented: admission caps with recorded stop reasons, a
staff switch, redacted decision inspection, a read-only scenario replay,
production-refusing test-universe seeding and a pilot report, all on the module's
`admin/ai` page and its commands. What remains before a population grows is
evidence, not code: the load measurements, the real-provider conformance artifact,
the driver activation gates and a disclosed pilot. See [`plan/`](plan/README.md).

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
# Install through the host lifecycle command: verify the runtime wiring, migrate the
# module's own migrations, run app/Hooks/InstallModule.php, enable the module, refresh
# the compiled caches and restart the queue workers.
bash scripts/ogamex install --dry-run   # print the plan first
bash scripts/ogamex install

# Read-only wiring report (queue driver, Horizon, phpredis, Redis, supervisor
# fragments, entrypoint hooks) with a non-zero exit code when something blocks.
bash scripts/ogamex doctor

# Uninstall: run app/Hooks/UninstallModule.php, disable, refresh caches and workers.
# Module data is kept unless you ask for it to be dropped.
bash scripts/ogamex uninstall
bash scripts/ogamex uninstall --drop-data --force

# Raw status-file toggles, for manual browser checks while developing. They skip the
# migrations, hooks and cache refresh that `install`/`uninstall` handle.
bash scripts/ogamex enable
bash scripts/ogamex disable

# Run only this module's tests through the OGameX application.
# Tests always run in parallel with --bail; PARALLEL_PROCESSES=<n> pins the count.
bash scripts/ogamex test

# Coverage is the one serial run; it fails when any module statement is untested.
bash scripts/ogamex coverage

# Run an individual test or pass normal Pest arguments.
bash scripts/ogamex test --filter=AI

# Run any Artisan command in the OGameX application.
bash scripts/ogamex artisan module:list
```

Set `OGAMEX_ROOT` when the OGameX checkout is elsewhere:

```bash
OGAMEX_ROOT=/path/to/ogamex-next bash scripts/ogamex test
```

The wrapper uses host PHP by default. When using the already-running local
development environment, execute through its existing application service:

```bash
OGAMEX_RUNNER=local-docker-dev bash scripts/ogamex test
```

This never starts a default Compose stack or creates a one-off application
container. Start `local-docker-dev/` once from OGameX and keep using its
`ogamex-app` service for module commands.

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

## Implementation plan

Start with [the short five-phase roadmap](plan/README.md). It explains what we are building and the next step. Technical details, research and the unchanged original proposal are optional references.
