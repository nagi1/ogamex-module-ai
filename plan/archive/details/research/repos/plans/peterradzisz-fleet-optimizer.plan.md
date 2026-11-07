# Enhancement plan — peterradzisz/ogame-fleet-optimizer

## Verdict summary (one line)
The repo is a hardcoded-universe, over-engineered offline counter-fleet optimizer, so its combat engine and optimizer are refused wholesale; the only transferable value is its estimator statistics — a win-probability survival floor and common-random-number / screen-then-confirm sampling — which our `NativeRaidEstimator` should adopt (the floor now, the ladder gated on U6).

## ADOPT-IDEA — table
| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| Win-probability survival floor (M37 `_WIN_THRESHOLD=0.95`) | Refuse a raid that does not win/survive ≥ ~0.95 of sampled runs even when P20 profit is positive, because a coin-flip that "profits" on paper still loses the fleet | `app/Domain/Decision/RaidPlanner.php` (`plan()` gate), fed by `app/Domain/Raid/RaidEstimate.php` | A fleeter asks "will I survive this?" before "does it profit?"; our gate only answers the second, so a 60/40 raid that nets positive still flies — a machine tell and a fleet-crash risk. Nameable as ordinary caution. |
| Common-random-numbers + screen→confirm ladder (M39 `CRNManager`, sensitivity/final sims split) | Share one seed stream per candidate so the difference between candidates is composition, not RNG; run a cheap screen over candidates then a wide confirmation only on the winner | `app/Infrastructure/Battle/NativeRaidEstimator.php` — gated on the U6 launch-subset work | When U6 compares counter-selected launch subsets of our own fleet, paired seeds make the winner meaningful and the ladder spends sims only on the subset that passes. One line each over the existing `seed + i` stream; YAGNI until ≥2 candidates exist. |

## ENHANCE — table
| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| `NativeRaidEstimator::sample()` reads only `$result->loot` and `$result->attackerResourceLoss`, discarding the host battle outcome it already computed; `RaidEstimate` carries `samples`, `losingRuns` (money < 0), `p20NetProfit` | The repo keeps survival and profit as distinct questions: `win_prob` (did the attacker win/survive) next to net profit, with a hard win floor | Read the host's win/draw/loss per sampled run and carry `pWin` (fraction survived) and `lostFleetRuns` on `RaidEstimate` next to `p20NetProfit`/`losingRuns` — survival vs money, both from the same 50-run stream, no second pass | `app/Infrastructure/Battle/NativeRaidEstimator.php`, `app/Domain/Raid/RaidEstimate.php` |

## ALREADY-DONE (confirmed by grep)
- **Seedable, read-only battle question** — `app/Infrastructure/Battle/NativeRaidEstimator.php` calls `PhpBattleEngine::simulateBattle($seed, true)`; explicitly not a second engine.
- **Monte-Carlo screen + published tail percentile** — `NativeRaidEstimator::sample()` (50 runs) + `lowerQuantile()` P20; the repo's "published percentile method" is the same nearest-rank family we already ship.
- **Common random numbers for a single candidate** — `sample()` uses the shared stream `seed + i`, so a candidate is always compared through the same draw sequence; the cross-candidate use is the only missing piece (U6).
- **Combat core (M01–M09: 6-round cap, per-unit state, shield regen, tech multipliers, <1% bounce, explosion, attacker-first, rapidfire)** — host `app/GameMissions/BattleEngine/BattleEngine.php` + `PhpBattleEngine.php`, not module code.
- **Debris / moon chance / defense rebuild / loot (M22, M43 inputs)** — host `BattleEngine::calculateDebris()` / `calculateMoonChance()` / `DefenseRepairService` / `LootService`, returned on `BattleResult`.
- **Profit test, bashing limit, fuel tier, fresh intel** — `app/Domain/Decision/RaidPlanner.php` (`BASHING_LIMIT=6`, `LOOT_TIER_FARM`/`DEFENDED`, `roundTripFuel`, `withinBashingLimit`).
- **Dispatch-time activity/ninja re-check** — `app/Actions/QueueAiRaidAction.php` (`activityAt`, `moonOnlyActivity` → `TargetActiveAtDispatch`/`TargetStagingAtDispatch`).

## REFUSE — list with one-line reason
- **Counter-fleet optimizer core (M27 `COUNTER_MAP`, greedy M27–M30, multi-start GA M33–M38, sensitivity M40, alternatives M41/M42)** — hardcoded enemy→counter table (gate 1) + a GA/sensitivity/alternatives stack that needs a paragraph per part (gate 2) + exact-budget 95%-floor optimization no human performs (gate 3).
- **Analytical resolver + bit-identical Rust port + CPython MT19937 port (M10–M18, M21)** — a second battle engine, and a triple-duplicated, four-dial over-engineered model; the host engine is the single authority we sample.
- **Per-unit Monte-Carlo core (M02, M21 Xoshiro)** — a second battle engine in module code; forbidden duplication.
- **Hardcoded ship/defense stats, costs, rapidfire (M49–M51; `ships.rs`, `fleet.py`, `fast_combat.py`, `ogame_tables.json`)** — gate-1 source-of-truth universe; we read the host, and we do not "correct" host values in module code.
- **Drive multipliers, fuel/speed penalty, resource-preference penalty (M46–M48)** — the host computes speed/fuel; reimplementing is forbidden duplication, and the preference knobs are composition-taste for a feature we do not ship.
- **Recycler planning formula (M43)** — the recycler capability is already planned as a new host-read capability in `plans/trilogi77-ogamebot.plan.md`; its formula hardcodes cargo values, and re-proposing it here duplicates that plan.
- **Mid-evaluation wall-clock deadline (GA loop)** — we have no iterative optimizer loop to bound; YAGNI.
- **JS `SHIP_META`, `localStorage` presets/history, TXT/XML export** — display-only UI for a paste tool; nothing to adopt into module code.

## Priority recommendation — the single highest-value change
Add the **win-probability survival floor** to the raid gate. It is one new field on `RaidEstimate` (`pWin`, read from the host outcome the estimator already computes but throws away) plus one `plan()` guard in `RaidPlanner` — the smallest diff that closes a real fleet-crash risk. Gate 3 names it directly ("a fleeter does not fly a coin-flip raid"); gate 1 is untouched (the outcome is the host's, no object named); gate 2 is one field plus one condition. The threshold should be persona flavour over the host outcome, not a new config constant.

## Open questions / risks
- **Host outcome field name** — confirm the exact `BattleResult` winner/outcome property (e.g. `->winner` or `->attackerWon`) by reading host source; `loot`/`attackerResourceLoss` are already confirmed by `NativeRaidEstimator`. Host files live outside `Modules/AI`, so module-only grep cannot confirm it.
- **"Win" semantics for a raid** — define whether surviving-with-loot (attacker fleet not wiped, draw included) counts as "win"; a raid is fine with a draw as long as the fleet returns. Match the repo's `attack: win_prob ≥ 0.95` vs `defend: 1 − win_prob ≥ 0.95` only for the attacker side.
- **Threshold value** — the repo's 0.95 is a UI constraint, not a measured human behaviour; tie the floor to persona (fleeter tighter, miner looser) over host data rather than a constant in config.
- **U6 gating** — the CRN/ladder row must not be built before the launch-subset work gives ≥2 candidates; until then it is dead machinery (gate 2).
- **Debris separation** — keep debris out of the raid profit gate (`strategy-principles.md` TP-004 / RAID-014); the recycler/debris loop is owned by `plans/trilogi77-ogamebot.plan.md`, do not re-scope it here.
- **No new dependency, no second engine** — every row above reuses `PhpBattleEngine::simulateBattle($seed, true)`; nothing ports the repo's Rust/Python combat or optimizer code.
