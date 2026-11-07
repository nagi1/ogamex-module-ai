# The errand choice: one model for the fleet side of a login (spec, 25 October 2026)

The economy models choose a build or a research. A player's login also holds an *errand*: spy someone, raid a target,
send recyclers to a field, save the fleet, order ships, or nothing. This spec makes that one more choice kind behind the
same seam (candidates, masks, one scorer, ticket rewards), so the existing PPO harness trains it (`kind` = 2).

## The choice

At a login of an account (a capital slot with `decision_kind` Build or Idle) the world offers **candidates**; the model
picks one or `wait`. Every candidate is a complete errand with its sizing done by the scripted player's rule
(`players.rs`), so the model decides *whether and at whom*, not fleet arithmetic.

| Candidate | Offered when | Sizing (rule from the scripted players) |
| --- | --- | --- |
| `spy(target)` | a probe is on the shelf, target within range, no report newer than 24 h | probes the raider uses |
| `raid(target)` | a report on the target is fresh and the rule's fleet exists | fighters and cargo as the raider sizes them |
| `recycle(field)` | a harvester is on the shelf and a field is worth the fuel | harvesters to fill from the field |
| `save` | an attack on this account is flying and has not landed | ships out at the slowest speed, recalled later |
| `order(unit)` | the yard is idle and the unit is buildable | an amount the rule uses |
| `wait` | always | nothing |

Targets are the nearest K accounts (default 8) plus fields over them; nothing is named: roles come from host tables
(`Kit::of`). A candidate is illegal when the world's own `dispatch`/`order_units` would refuse it, so the mask is exact.

## Features (all derived, none per object name)

Account: log stock, stock/cap, ships by role (probe, cargo, fighter, harvester) as log counts, fleet strength, hour of
day (sin/cos of the login's time of day), hours since last login, hours since the last attack on us, idle flag,
speed (log). Candidate: kind one-hot, distance, flight hours, fuel/stock, expected loot/fuel (raid), target
defence+fleet strength over our strength (log ratio), report age, target inactive flag, target resource log, target
stock ratio, field size/capacity (recycle), seconds until the incoming attack lands (save), our fleet at home share.

### Feature compatibility (4 November 2026)

Rust errand feature version 2 replaces the hidden live target research used by the threat ratio with the research in
the spy report, or the observer own technology as an explicit estimate when research was not revealed. The candidate
column is now named target_ratio_observed_or_estimated. Tensor widths remain the same, but prior errand labels,
checkpoints and policies are not comparable and must be regenerated or retrained. Training checks the saved feature
names before an errand warm start; Rust rejects unversioned and older exported errand policies. Economy policies use a
separate schema and are unaffected. The Python extension must be rebuilt before generating new errand data.

Champion errand labels (`champion-errand-out=`) write one sidecar beside the file: `{"errand_feature_version": 2,
"candidates": 32}`. The width has to be declared because the errand menu offers `wait` first and pads the rest, so a
reader cannot infer it from a legal last row the way build labels allow; `train_exit` reads it there and no longer
needs `--k` (`--k` still overrides). A missing sidecar, an older version or a missing width is refused by name
rather than parsed.

## Reward and the value fix

`Game::value` counts built value and stock. For fleet decisions an account's value also counts ships at their price
(metal-equivalent) and resources in flight; a lost ship is a loss, a destroyed target ship is not a gain (no farming
the other side's value). Everything else as the economy's ticket reward. The anchor to human behaviour (below) is a
penalty term, not a part of value.

## Teacher and anchor

The teacher is the scripted player rule (`players.rs`): its choice at each login is a label. Training order: behaviour
cloning on the teacher's choices, then PPO with a KL penalty to the cloned policy. The scripted miner/raider/saver mix
is the opponent population, so the model sees raided accounts, savers and quiet ones.

## Human-likeness measures (read at every evaluation, not optimised blindly)

From `rl/scripts/humanlike.py`: login times (distribution of hour of day and gaps), reaction latency to a probe or an
attack (login after the event), fleet-save success rate (should be well below 100%), raid cadence per day, growth
curve against `plan/rl/human-growth-check.md`'s bands. The evaluation prints these beside value; a model that raises
value while leaving the bands is a failure.

## Rollout

1. Sim: `errands.rs` (candidate generation, features, masks, apply), `VecWorld` gains an errand mode (`python.rs`).
2. Train: `train_world.py` gets kind 2 (BC then PPO + KL), evaluation of value and human-likeness vs the scripted mix.
3. PHP: a choice point for errands in the module (the economy seam's pattern) so the served model decides in the real
   game; sim and PHP must agree on the same states (like `ogrl.econ.compare`). Not started.
