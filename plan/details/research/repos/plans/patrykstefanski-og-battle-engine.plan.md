# Enhancement plan — patrykstefanski/og-battle-engine

## Verdict summary (one line)
A standalone C combat simulator whose entire surface (6-round loop, rapid fire, shield bounce, hull = structural/10, <70% explosion, draw, seeded runs) is already owned by the host `BattleEngine`/`PhpBattleEngine` and sampled by `NativeRaidEstimator`, so it is a wholesale REFUSE as a second battle engine; a formula cross-check confirms the host matches on every point and needs no correction.

## ADOPT-IDEA — table
| Mechanism | What it does | Where in our module | Why worth it |
|---|---|---|---|
| — | None: every mechanism the repo offers is already provided by the host battle engine we sample. | — | Adopting any of it would duplicate the host's authority, violating the "never duplicate a driver's capability" rule. |

## ENHANCE — table
| Our current | What the repo does better | Proposed change | Files touched |
|---|---|---|---|
| — | Nothing. The repo's only genuine differentiators are determinism (Lehmer MINSTD + same-seed multi-sim) and input validation at the CLI boundary; our seeded pure-mode sampling already covers the former and the host already covers the latter. | No change. | — |

## ALREADY-DONE (confirmed by grep/read)
- **6-round cap + attacker-first fire + both-alive draw + shield regen per round (M01–M04, M16)** — host `app/GameMissions/BattleEngine/PhpBattleEngine.php::fightBattleRounds()` (`while ($roundNumber < 6 …)`), `cleanupRound()` restores `currentShieldPoints = originalShieldPoints`.
- **Attack/shield/hull formulas (M05–M07)** — host reads `structural_integrity`, `shield`, `attack` from `UnitObject` properties (gate-1 correct); hull = `structuralIntegrity / 10` in `app/GameMissions/BattleEngine/Models/BattleUnit.php::__construct`.
- **Uniform random target + wasted shots on dead units (M08–M09)** — host `attackUnit()` (`array_rand` over alive units; `currentHullPlating <= 0` → shot wasted but still counted/rolls rapidfire).
- **Shield bounce rule (M10)** — host `attackUnit()`: `$shieldPercentile = 0.01 * originalShieldPoints; $depletedPercentiles = floor($damage / $shieldPercentile)` — identical to the repo's `0.01 * floor(100 * damage / max_shield) * max_shield`.
- **Shield penetration + hull clamp (M11)** — host `attackUnit()` shield-break branch (`hullDamage = damage - currentShieldPoints`, clamped to remaining hull).
- **Explosion below 70% hull (M12)** — host `BattleUnit::damagedHullExplosion()` (`hullPercentage >= 0.7` → false; chance `1 - hull/original`); repo's `hull < 0.7 * max_hull` test matches.
- **Rapid fire (M13)** — host `app/GameObjects/Models/UnitObject.php::didSuccessfulRapidfire()` (`mt_rand(1, $amount) > 1`, extra-shot probability `(N-1)/N`); repo's `RANDOM_NEXT(r) % N != 0` is the same probability.
- **Per-round stats (M14)** — host `BattleResultRound` (`hitsAttacker/hitsDefender`, `fullStrength*`, `absorbedDamage*`, per-round losses) + `PhpBattleEngine` per-fleet counters.
- **Seeded, read-only "what if" battle (M17–M18)** — host `BattleEngine::$seed`/`$pure` + `PhpBattleEngine::simulateBattle($seed, true)`; module `app/Infrastructure/Battle/NativeRaidEstimator.php` already samples `simulateBattle($seed + $i, true)` over `SCREEN_SAMPLES = 50` with a P20 tail.
- **Debris / moon chance / loot (repo has none; host superset)** — host `BattleEngine::calculateDebris()`, `calculateMoonChance()`, `rollMoonCreation()`, `app/GameMissions/BattleEngine/Services/LootService.php`, `Services/WreckFieldService.php`.
- **Draw / retreat semantics** — host `app/GameMissions/BattleEngine/Services/TacticalRetreatService.php` (both-withdrew = no combat, no loot).

## REFUSE — list with one-line reason
- **Whole engine (M01–M18) as module code** — forbidden second battle engine; the host `BattleEngine`/`PhpBattleEngine` is the single combat authority `NativeRaidEstimator` already samples.
- **`OG.php` / `OG.py` hardcoded 22-kind catalogue (M20–M21)** — gate-1 violation: static unit stats in module/plan code; the host `ObjectService`/`GameObjects` unit properties are the only source of truth.
- **Unit-per-ship C memory model + no per-run time bound (M15/M04 concern)** — a 250k-battleship sim allocates one `struct unit` per ship with no execution bound; irrelevant and hostile to a 2 GB VPS.
- **`float`-then-`uint64` stat truncation** — lossy for large counts; the host keeps per-fleet/per-round `UnitCollection` accounting with no such precision loss.
- **No debris/moon/loot/fleet-escape (M23)** — the repo is an incomplete combat oracle; a raid gate needs exactly what it omits, so it adds nothing the host does not already return.
- **AGPL-3.0 C binary + `proc_open` subprocess wrapper** — a new process-spawning dependency for a capability we already have in-process; "no new dependency if it can be avoided".

## Priority recommendation — single highest-value change
No change. The lazy senior answer is to build nothing: every formula this repo contributes is already the host's, verified point-by-point above, and `NativeRaidEstimator` already turns the host engine into the seeded Monte Carlo oracle the repo would otherwise provide. The only work item this review produces is negative evidence — a recorded cross-check that rapid fire, shield bounce, hull, rounds and draw all match the host, so no correction (and no code) is needed.

## Open questions / risks
- The cross-check compares the repo's documented formulas (read from `BattleEngine.c`) against the host's PHP engine (`PhpBattleEngine.php`); the host's Rust FFI engine is not bit-compared here — if the Rust engine diverges from PHP on any of the five formula points, that is a host bug, not a module concern.
- The repo's determinism (Lehmer MINSTD, same seed across `num_simulations`) differs from the host's seeded stream (`seed + i` per sample); the module's shared-stream scheme is already reproducible and is the one to keep — do not switch RNG families on this repo's evidence.
- No other module code consumes battle-rule constants directly (grep found no rapid-fire/shield/hull literals in `Modules/AI/app`), so there is no caller to fix.
- If a future slice needs a battle-report *appraisal* (not a decision gate), the host `BattleResult`/`BattleResultRound` already carry the per-round counters — revisit this repo only as an idea reference, never as an implementation.
