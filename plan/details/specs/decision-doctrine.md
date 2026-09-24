# Decision doctrine — the single spine

Owner direction, 23 September 2026: stop stacking ad-hoc mechanisms; the account's choices must be
derivable from *what an experienced player does*, in one clean algorithm. This file is that doctrine.
It consolidates the sourced research into one decision table and maps the current code onto it with a
keep / fold / delete verdict, so the rebuild is a refactor with a target, not another layer.

Sources are the already-verified research — `research/veteran-play.md` (every claim URL-checked),
`specs/decision-techniques.md` (the technique ranking), `specs/decision-policies.md` (the normative
"what"), `specs/gameplay-algorithms.md` (the "how" and provenance). No new claims are introduced here;
this file only fixes the *arrangement*.

## Precedence

1. **Hard constraints** (host legality) — affordability, requirements, free slots, fuel, bashing,
   missing fuel/shipyard/moon — never overridden by any score.
2. **Emergencies and expiring obligations** — an inbound hostile, a save that must happen before
   logging off, a commitment about to lapse.
3. **One bounded candidate set** built from host-quoted facts only (gate 1).
4. **One score** over normalized features, with hysteresis so the current goal is not abandoned for a
   hair (gate 2).
5. **Seeded tie-break** among near-equal candidates, revalidated at execution (gate 3, human variance).

This is `decision-policies.md` §"Common selection" restated. Everything below must slot into these five
steps; any mechanism that sits *beside* them is the spaghetti this file exists to remove.

## The doctrine — what a pro does, in one table

| # | Situation | What the pro does | Mechanism (host-derived) | Evidence |
| --- | --- | --- | --- | --- |
| D1 | Peace, growing | Next mine = production gained ÷ weighted price, cheapest payback first; stop past the persona's horizon | marginal payback, `M + 1.5C + 2D`, capped horizon | E1, veteran-play §1 |
| D2 | Energy | Build capacity *before* the level that would outdraw the planet | predictive interlock with hysteresis | Y1, veteran-play §2 |
| D3 | Storage | Upgrade only when fill time < time-to-spend or absence; storage is absence insurance, not growth | fill-time trigger | E3, veteran-play §3 |
| D4 | Research | Research when it out-pays the last purchase or unlocks a capability (astro, plasma, terraformer) | payback + capability graph | R1/R2, veteran-play §4 |
| D5 | Units | Roles from host unit properties: cargo first, then what the account observes needs | role derivation | U1/U2, veteran-play §4 |
| D6 | Inbound hostile | Save the fleet (deploy between own bodies), react 120–180 s before impact, land after being online; **never panic-build a day early** | fleetsave state machine + reaction window + deliberate failure | V1/V2/V3, veteran-play §6 |
| D7 | Inbound, nothing to save | Build the defence that makes the attack unprofitable; not a ratio, derived from the observed attacker | defence = unprofitability | U3, veteran-play §4/§7 |
| D8 | Offline gap ahead | Proactive save before the absence | proactive save | V6, veteran-play §5 |
| D9 | Raid candidate | Profit = loot − deuterium − expected losses, tail-tested; scout then calculate; bashing limit | P20 tail gate, bashing counter | T1/T2/T3, veteran-play §7 |
| D10 | Full warehouse / windfall | Spend before warehousing (mine or dump), never grow the store to chase it | spend-first | E6/E7, veteran-play §3 |
| D11 | Every session | Heavy-tailed gaps, a real dark period, <18 active hours/7d, no reaction under 10 s | routine distributions | H1/H2, veteran-play §5/§9 |

Every mechanism is nameable as ordinary veteran play (gate 3), and every object/price/requirement is
read from the host at decision time (gate 1).

## The current code vs the doctrine

| Current mechanism | File | Verdict | Why |
| --- | --- | --- | --- |
| `UtilityScorer` weighted score | `Domain/Decision/UtilityScorer.php` | **keep** | the spine (decision-techniques §ranking) |
| per-type feature constants | `CandidateActionFactory.php` | **folded** | now one intent table — `features()` is a single `match` over the action type, taste values in one place, host-derived values (confidence, travel_cost, recovery) passed per candidate |
| `buildScarcityBoost` (10:1→1000:1 log) | `CandidateActionFactory.php` | **keep** | the scarcity normalization of `resource_need`; one named helper, not a layer |
| `EconomyUpgrades::storage` build-time bound | `Domain/Decision/EconomyUpgrades.php` | **folded** | the 23 Sep build-time bound now sits beside the fill-time trigger inside `storage()` — one trigger, two bounds |
| `EconomyUpgrades::spendSurplus` / `researchDump` | `EconomyUpgrades.php` | **keep** | E7 (full warehouse is a spend signal) and E9 (field-full dump) are each a single named rule |
| `QueueableBuildingPlanner` three passes | `Domain/Decision/QueueableBuildingPlanner.php` | **keep, de-duplicated** | the tiers encode two different iteration orders (blocking is cross-planet, routine is per-planet); the blocking sweeps now share `firstQueueableAcross()` |
| `QueueableUnitPlanner` role chain | `Domain/Decision/QueueableUnitPlanner.php` | **keep** | roles are correct; cargo-first and defence-under-attack already express D5–D7 |
| `idleOverride` + `threatAppetite` (affect) | `DecisionEngine.php`, `UtilityScorer.php` | **keep** | the "did nothing" variance (never over a save) and the opt-in affect appetite; each single-purpose |
| `FacilityChain` | `Domain/Decision/FacilityChain.php` | **keep** | the prerequisite closure that makes capabilities reachable (gate 1); it is a candidate source, not a layer |
| fleetsave reaction (V1/V2/V3) | `QueueableFleetSavePlanner.php` + `PlayerObservationService::inboundThreat` | **keep as-is** | already the doctrine (D6) and the highest-weighted safety action |

## The rebuild target — one spine

```text
for one decision:
  1. hard constraints reject anything illegal or impossible (host answers)
  2. if inbound hostile or a save is due before logout: the emergency candidate set wins
     (fleetsave; if none possible, defence; if neither, spend to reduce exposure)
  3. build one candidate set: DoNothing + each published capability + fleetsave/recall/raid/...
     each candidate's features are host-derived, normalized against daily income
  4. score = benefit(normalized) − exposure − attention/disruption + persona preference + seeded variation
     (affect only when its opt-in weight is on)
  5. keep the current goal unless an alternative beats it past the switch margin; seeded tie-break
  6. record features, rejections, next observation time; revalidate at execution
```

One list, one score, one override (emergency). The hard interlocks (energy, storage fill, bashing,
fleetsave trigger) are small threshold tables with hysteresis, evaluated before the score — never
inside it.

## LLM consultation — where it belongs

- **Design time (this research): yes.** An LLM is the right tool to stress-test the D-table against
  written scenarios ("what does a veteran do when a probe lands at 02:00 with a full warehouse") and
  to author the scenario fixtures that prove each row.
- **Run time (the decision path): never.** The module's standing rule — zero generative calls on the
  ordinary decision path — is unchanged. The doctrine is a table + arithmetic over host numbers; a
  language model adds latency and cost where a 12-row table is enough.

## Acceptance for the rebuild

- Every row D1–D11 has a scenario test that names the veteran-play rule it exercises (extend
  `DeterministicSessionLoopTest` / `PersonaPolicyMechanicsTest` / `BuildingChainReachabilityTest`).
- The one real tangle — scattered per-type feature tuples — is folded into a single intent table
  (`CandidateActionFactory::features()`), and the duplicated blocking sweeps share
  `QueueableBuildingPlanner::firstQueueableAcross()`. Nothing is wrapped for wrapping's sake.
- Changed module code keeps 100% PCOV; full Pest green; `scripts/ogamex gate` clean; Pint clean.
- No new abstraction with one implementation, no new dependency, no new config key (gate 2).
