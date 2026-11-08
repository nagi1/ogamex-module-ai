# OGameX AI module

The `AI` module adds a home for persistent, believable AI players in OGameX.
This repository contains the module only. OGameX remains responsible for game
rules, legal state, validation, and execution.

## What it does today

Every AI account is a full player: it builds, researches, raids, defends, colonises, sends
expeditions, joins alliances, declares wars and pacts, and talks in private and in the alliance
channel. Each login reads only what a real player could see (rule 10, no cheating), decides with
the built-in rules, and executes through the normal OGameX actions.

- **Rules first.** The planners in `app/Domain/Decision` decide by default. They are the
  reference behaviour the trained models were taught from.
- **Two trained models, optional.** Both were trained in the Rust simulator (`rl/ogsim`) and run
  natively in PHP. Neither is in this repository; point the settings below at the files.
  - Fleet errands (raid, spy, expedition, wait): `AI_RL_ERRAND_MODEL=<path>/final-g16.policy.json`.
    `AI_RL_FLEET_ERRANDS_SCRIPTED_PERSONAS=raider` keeps raiders on the scripted raider.
  - Economy (what to build or research): `AI_RL_POLICY=model` and
    `AI_RL_ECONOMY_MODEL=<path>/best-es3-s10.policy.json`. Any other choice stays with the rules.
- **Social layer with no LLM.** Messages, truces, alliance politics and replies are authored and
  rule-driven; an optional provider escalation exists and is off by default.
- **Operability.** The `admin/ai` console shows every account, its decisions and stop reasons.
  With `AI_MODEL_LAB_ENABLED=true` its *Model lab* tab shows, in plain words, what each trained
  model would do for each account right now beside what the rules would do (a dry run, nothing
  is queued).

Configuration lives in `config/` (`rl.php`, `model_lab.php`, `population.php`, `cognition.php`, …);
every switch is read from the environment and is off or neutral by default.

### Faster universes

Everything time-based is read in the universe's own hours, so the same accounts play a 1x and a
1000x universe: dispatch-refusal cooling and espionage-report freshness shrink with the speed, and
a warehouse is only raised once it is half full. The 1000x play universe used to tune this is the
host's `local-docker-dev/docker-compose.grand.yml`.

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

# One test file by name (fast; the usual loop while fixing something).
bash scripts/ogamex test-one ProcessAiWorkTest
```

Watching a running universe (read-only):

```bash
bash scripts/ogamex pulse 10        # what every account did in the last 10 minutes, refusals, backlog
bash scripts/ogamex stuck           # accounts failing the same way again and again
bash scripts/ogamex account 7       # one account: planets, queues, fleets, recent work
bash scripts/ogamex why 7           # what its next login will see and why it chose what it chose
```

`bash scripts/ogamex help` lists every command.

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

## Continuous integration

`.github/workflows/ci.yml` checks out the OGameX host (`nagi1/ogamex-next`), places this
repository at `Modules/AI`, starts the host's Docker Compose stack and runs Pint, PHPStan (with
the module's own rules) and this module's Pest suite inside it. The host's own pipeline never runs
module tests.

## Implementation plan

Start with [the short five-phase roadmap](plan/README.md). It explains what we are building and the next step. Technical details, research and the unchanged original proposal are optional references.
