---
description: The goal every AI-module slice is judged against — read before planning or writing any slice
---

# North star — accounts nobody can tell from experienced humans

## The goal

Run thousands of OGame accounts on real servers that another player cannot distinguish from people
who have played for years. "Indistinguishable" is measured by what a player can observe, not by how
polished a message is: reaction latency to a probe or an attack, a fleet save that sometimes fails,
an uptime shape that follows a day, a public growth curve that matches the server, an action sequence
that is not self-similar, and the breadth of social contact
(`plan/details/research/account-authenticity.md`, `player-personas.md`).

## How the accounts decide

- **Deterministic first.** Every gameplay move is a rule over host data, chosen by the decision engine
  (`app/Domain/Decision`). Ordinary play makes **zero** generative calls; that is what lets one small
  VPS carry a population.
- **Strategy is community doctrine, made executable.** Openings, mine ratios, fleet saves, raid
  profitability, defence shapes and colony choice come from the ingested community and wiki sources
  (`plan/details/research/strategy/principles/`), each with its numbers in a YAML file under
  `resources/behavior/` so a modder can retune it without code.
- **Personas give variance, not randomness.** Two accounts with the same situation may choose
  differently because their persona and history differ, never because a die was rolled for no reason.
- **Models are a scarce, optional layer.** An LLM writes human-language replies only when an authored
  reply is not enough. A hosted classifier (Jev) may *read meaning* — what a message is, whether it is
  coercive, whether a player announces they are away — behind one seam, off by default, budgeted, and
  only after its offline benchmark beats the rules (`plan/details/research/jev-opportunity-map.md`).
  It never picks a build, a target or a timing: calibration is measurement, not a model problem.

## How a slice is accepted

1. Name the player behaviour it adds as something an experienced player does (gate 3).
2. Read objects, prices and requirements from the host at decision time (gate 1).
3. Change the class that already owns the decision; no rival class, no layer that forwards (gate 2).
4. A Feature test drives the real engine path, and a scenario under `resources/scenarios/` states the
   action the engine must choose — plus the opposite situation where the rule has a boundary.
5. The live cohort read (`scripts/verify-cohorts.php`) is the final judge: a rule a cohort never
   exercises is not delivered, however green its test.

## Priorities

Work the worst observable badness first (`plan/tasks/USAGE.md`): an account that does not play (P0)
before one that plays visibly wrongly (P1), before missing policy (P2), before new capability (P3).
