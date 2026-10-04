# Features (encoder version 1)

All produced by `app/Domain/Choice/EconomyChoiceEncoder.php`; names in the order they appear. `log1p` keeps
large quantities near 0–25; ratios are 0–1; one-hots are 0/1. The model normalises again with training-set
mean/std stored inside the network.

## State (45): account (once per login) + the planet of this choice

| Name | Meaning |
| --- | --- |
| `acct_planets` | log1p(planets owned) |
| `acct_resources` | log1p(metal + crystal + deuterium held on all planets) |
| `acct_production` | log1p(total production per hour) |
| `acct_score` | log1p(host general score) |
| `acct_rank_pct` | rank / ranked players (1 = last or unranked) |
| `acct_age_days` | log1p(days since registration, simulated clock) |
| `acct_research_levels` | log1p(sum of all research levels) |
| `acct_lab_busy` | research running on the current planet |
| `speed_economy` | log(universe economy speed) |
| `phase_early/mid/late` | `GamePhaseMachine` phase one-hot |
| `archetype_*` (7) | persona archetype one-hot (`AiArchetype` cases) |
| `skill` | skill band 0 / 0.5 / 1 |
| `stockpile_*` (6) | stockpile strategy one-hot |
| `pl_metal/crystal/deuterium` | log1p(held on this planet) |
| `pl_*_fill` (3) | held / storage capacity |
| `pl_*_ph` (3) | signed log1p(production per hour) |
| `pl_energy_max`, `pl_energy_used` | log1p(energy produced / consumed) |
| `pl_factor` | production factor 0–1 (energy shortfall) |
| `pl_temperature` | average temperature / 100 |
| `pl_fields_used`, `pl_fields_max` | used / max fields; max / 200 |
| `pl_is_moon`, `pl_position` | moon flag; position / 15 |
| `pl_queue_length` | items in this planet's building queue / 5 |
| `kind_research` | 1 for the lab choice, 0 for a planet's build queue |

## Candidate (27): one row per option, row 0 is wait

| Name | Meaning |
| --- | --- |
| `is_wait` | 1 only on row 0 |
| `type_building/station/research` | host object type |
| `pass_*` (6) | which planner pass offered it first (wall, doctrine, storage, surplus, routine, ambition) |
| `order` | position in the planner's list / 32 (the teacher's priority signal) |
| `legal` | the host would accept it now (price only, no reserve) |
| `teacher_ok` | the planner would accept it (price + its own reserve floor) |
| `spendable` | allowed by the account's savings goal |
| `cost_metal/crystal/deuterium` | log1p(price of the next level) |
| `cost_hours` | log1p(price / planet production per hour) |
| `cost_vs_stock` | min(price / held, 10) / 10 |
| `build_hours` | log1p(build or research time, hours) |
| `level` | current level / 40 |
| `gain_ph` | signed log1p(production per hour the next level adds, host formula at 100%) |
| `payback_hours` | log1p(price / gain), 10,000 h when it produces nothing |
| `energy_delta` | signed log1p(energy the next level adds or uses) |
| `storage_gain` | log1p(storage capacity the next level adds) |
| `unlocks` | objects that list this one as a requirement / 10 |
| `eta_hours` | log1p(hours until this planet can pay the price); on wait: the soonest such ETA |

## Adding or changing a feature

1. Add the name to `stateNames()` or `candidateNames()` **and** the value at the same position.
2. Host-derived only (Gate 1). No object id or name, no other players' hidden state.
3. Bump `VERSION`; update this file; run `EconomyChoiceSeamTest`; regenerate data; retrain.
4. Prove it helps with an ablation (improving.md §3), not with one lucky run.

Known gaps worth trying (in this order): time remaining in the building queue in hours; the doctrine's next
goal as "is this candidate on the doctrine list" (already partly in `pass_doctrine`); fleet/defence value of
the planet (threat context); the account's rank change over 24 h.
