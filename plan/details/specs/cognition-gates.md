# Cognition gates

Three gates every AI slice is measured against. They are constraints on the design, not preferences: a
slice that fails one is changed before it is accepted. Recorded 14 September 2026 from the owner's
instruction, after the capability-chain audit in [`../GAP-REGISTER.md`](../GAP-REGISTER.md) showed what
happens when a plan is audited against itself instead of against the game.

## Gate 1 — no static, hardcoded AI

The host is a platform, not a fixed game. Mods, modules and future extensions add buildings, ships,
defence, technologies and premium officers. The AI must keep working — and keep being able to act — when
it meets an object it has never seen before.

**Required**

- The object universe, its kinds, its prices and its requirement graph are read from the host
  (`ObjectService` and `app/GameObjects/`) at planning time, every time.
- Legality, affordability, planet type, queue space and requirement satisfaction are the host's own
  answers, never restated in module code.
- Adding an object to the host must make it usable by the existing module code with no module edit.

**Forbidden**

- An object id, machine name or requirement encoded in module code or config as a source of truth: an
  enum of "the buildings we may build", a table of "a shipyard needs a robot factory at level two", a
  list of "the technologies we research".
- A capability whose reachability depends on a list the module keeps.

**Allowed**

- Module policy that is genuinely the module's own taste — *which* ambition it pursues, *how* it orders
  what the host offers — expressed as a rule over host data rather than as a list of names.
- Naming an object in a test, because a test asserts against today's catalogue.

Reference implementation: `app/Domain/Decision/FacilityChain.php` derives every step from
`ObjectService::getResearchObjects()`, `getUnitObjects()` and `getRecursiveRequirements()`, and names
nothing. `app/Domain/Decision/QueueableBuildingPlanner.php` then asks the host whether the step is legal
and affordable.

## Gate 2 — relatively simple, never over-engineered

The reference profile is a 2 vCPU / 2 GB VPS, and every slice is read by a human before it is read by
the next slice. Complexity is a cost paid again on every change that follows.

**Required**

- The smallest mechanism that closes the gap. One class, one loop and one sort key beat a framework.
- Readability first: a reader should be able to hold the whole slice in their head at once.
- A slice deletes what it makes dead — an enum, a settings key, a helper, a test double.

**Forbidden**

- An abstraction with a single implementation, config for a value that never varies, a cache or an
  optimisation without a measurement, a layer that only forwards.
- A design that needs a paragraph to justify each of its parts.

**Allowed**

- An explicit, boring implementation a maintainer can change in one place.

## Gate 3 — what a good professional OGame player does

Fifteen years of OGame produced a body of ordinary play. The AI imitates that play; it does not invent a
more efficient game of its own.

**Required**

- Every mechanism is nameable as something an experienced player does in ordinary play.
- Where the AI chooses, the choice an experienced player would call *normal* beats the one that merely
  looks optimal.

**Forbidden**

- Machine-shaped play: maximising a function no human maximises, rushing an end-game unlock while the
  opening is unfinished, an action order no human produces, or behaviour explicable only by reading the
  module.

**Play to imitate**

- The opening: economy, plus the facilities that unlock the rest of the game — robotics factory,
  shipyard, research laboratory.
- Prerequisites before the thing they unlock, and the easiest unlock before the largest one reachable.
- Mining while short instead of spending a queue slot on a building that cannot be paid for.
- Saving a fleet when a probe or an attack arrives — and sometimes failing to.
- Growing with the economy rather than instead of it.

**Verification**

- Name the human play a mechanism imitates, in the slice summary, in the plan record and in the test
  that covers it. A mechanism nobody can name is not ready.

## How the gates combine

- Gate 3 decides *what* the account does. Gate 1 decides *how* it is derived. Gate 2 decides how much
  machinery is allowed in between.
- When gate 3 wants behaviour that looks like a list — a persona's taste, an opening order — gate 1
  still requires that the list not be the reason a capability is possible or impossible.
- Every gap in [`../GAP-REGISTER.md`](../GAP-REGISTER.md) closes against these gates, and the register
  is re-run until it is empty: an empty register is the evidence, not a progress report.
