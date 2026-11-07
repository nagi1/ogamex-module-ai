# Late-game tactics: escape, deception, betrayal, and PvE (5 Nov 2026)

Research note only; no code changed. It lists the documented late-game tactics:
- escape (fleetsave, jump gate)
- timing tricks
- deception (ninja, bait, decoys)
- espionage counter-play
- moon destruction
- alliance betrayal

For each tactic it records:
- the corpus sources;
- whether a real player could do it with only what they see;
- what the Rust sim (`rl/ogsim`) and the scripted teacher (`players.rs`, plus `errands.rs` `teacher_choice`) support today;
- the gap and its priority.

Support was checked read-only by grep on 5 Nov.

Errand kinds today (`errands.rs` `KIND_NAMES`): spy, raid, recycle, save, recall, order, wait, strike, moon_destroy, moon_shot, acs_join, acs_defend, expedition, deploy, gate_save, phalanx.

The BC smoke run (`runs/bc-wide-smoke`, 366k rows) showed:
- **Never offered (0% of rows):** moon_destroy and gate_save.
- **Rarely offered:**

  | Kind | Offered (% of rows) |
  | --- | --- |
  | save | 0.2% |
  | recall | 0.2% |
  | acs_defend | 0.3% |
  | acs_join | 0.3% |
  | strike | 0.1% |

Source ids are the corpus ids:
- `plan/research/ogame/raw/**` (WIK-, TP-, FOR-, GF-, PLW-, ORG-)
- principles in `plan/details/research/strategy/principles/*.yaml` (FS-, CRASH-, NIN-, ACS-, INT-, RAID-, EXP-, SOC-)

Columns in the table:
- **Visible?** Y means only player-visible information is needed.
- **Sim** and **Teacher**: what exists today.
- **P**: priority. P1 changes late-game outcomes, P2 is realism, P3 is later (after Stage D or alliances).

## 1. Inventory

| # | Tactic | Sources | Visible? | Sim | Teacher | Gap | P |
|---|---|---|---|---|---|---|---|
| 1 | Fleetsave before an offline gap (deploy or transport away, land after the next login) | FS-001, FS-007, TP-007, TP-009, GF-002 | Y (own schedule) | `save` kind; deploy and recall in world.rs | Saver persona saves by script | The model's save is offered in only 0.2% of rows. The teacher saves on threat, not on every planned absence (FS-001). | P1 |
| 2 | Save variants: carry the resources (FS-006), split waves (FS-009), vary landing time and route (FS-002, FS-005), mask the dispatch with activity afterwards (FS-008) | FS-002/005/006/008/009, BOT-005 | Y | One save route; the speed slider exists (`speed`) | Fixed route and speed | No route × speed enumeration and no split. A phalanx-predictable save is an easy crash. | P1 |
| 3 | Moon-deploy save, phalanx-invisible (a moon is never phalanx-scanned) | CRASH-006, ORG-011, FOR-005, TP-009, GF-002 | Y | `deploy` to own moon, `station()` | Saver stations on the moon | Supported. It is parked on the moon, not flying, so a moon destruction (#14) strands it. | P2 |
| 4 | Recalled-deployment save (deploy, then recall: phalanx-invisible) | FS-010, CRASH-013, FOR-005, TP-009 | Y | `recall` | Recall exists for threats | It isn't used as a save pattern. The recall's vanishing from a phalanx is a tell (CRASH-013). | P2 |
| 5 | Harvest / buddy-hold saves (recycler on debris, ACS-hold at a mate) | FS-011, PLW-001, ACS-008 | Y | `recycle`, `acs_defend` (hold) | Recycler trips; defend_mate | They aren't chosen as saves. ACS-defend is offered only when a mate is attacked. | P2 |
| 6 | Expedition is NOT a fleetsave (fleet-loss and pirate risk) | EXP-001, PLW-005, WIK-173 | Y | Expedition outcomes include fights and black hole (expedition.rs) | Expeditions are frequent (22.8% of labels) | The risk exists. Check that the teacher doesn't send its main fleet on expeditions as cover. | P2 |
| 7 | Jump-gate escape: an incoming attack on the moon, so jump the fleet to the other moon | CRASH-004, WIK-046, ORG-011 | Y (incoming list, own gate cooldown) | `world.jump()`, gate cooldown, `gate_open()`; `gate_save` kind | Saver gates by script after the errand | `gate_save` is offered only if an attack is incoming on that moon and the gate is ready, so 0% in practice. It also needs ≥2 moons with gates. The gate is also a logistics tool (move fleet between fronts) and that isn't offered. | P1 |
| 8 | Phalanx crash: scan the fleet's exact return second and hit it on landing | CRASH-002/007/009/010/011/014, WIK-006/009, GF-006 | Y (own phalanx) | `world.phalanx()`, `phalanx` kind (2.4% chosen) | Raider phalanx raid | Supported for raids. A deliberate "crash the returning fleet" with a war fleet isn't a distinct plan. | P1 |
| 9 | Phalanx counter-play: save from the moon only, use a phalanx-proof route, read a blind phalanx | CRASH-006/008/012/013, INT-006, INT-011/012 | Y | Moon is unscanned (world) | Partial (moon station) | No "am I inside an enemy phalanx range?" feature, so a saver can't choose a phalanx-proof save. | P1 |
| 10 | Ninja: hide the defending fleet (moon or mission), bring it back on the combat second to kill the raider | NIN-001/002/004, WIK-005, TP-011 | Y (incoming attack, own timing) | Missing: no timed return or ACS-defend landing on the impact second | None (0 hits for ninja/bait) | Missing entirely. This is THE late-game counter to raids and crashes. | P1 |
| 11 | Bait: a soft-looking farm, or a deathstar plus a wall of satellites, to lure a big attack and force a moon-making debris field | NIN-003, ninja-baiting moon-lure, TP-011, WIK-005 | Y | Missing | None | Missing. A bait detector on the attacker side is also missing (NIN-005). | P2 |
| 12 | Attacker anti-ninja: check for staging (fleets near the target, recent moon activity) and re-spy just before impact | NIN-005, WIK-005, TP-014, RAID-004/010 | Y (galaxy activity star, report) | Activity stars exist (world `star`) | Raider aborts on activity? (not verified) | Re-spying before impact and abort logic aren't in the teacher. | P2 |
| 13 | ACS tricks: a joining fleet may slow the group ≤30% (ACS-013); late join; ACS ninja (defender's mates ACS-defend into the impact second) | ACS-003/004/010/013, PLW-004, GF-003 | Y | `acs_join`, `acs_defend`, union in world.rs | join_raid, defend_mate | The 30% slow rule isn't verified in the sim. ACS-defend isn't timed to the impact second (that's the ninja, #10). | P2 |
| 14 | Moon destruction to strand a fleet or kill a phalanx/gate: returning fleets redirect to the planet (and become visible to the phalanx); no debris | CRASH-005, WIK-187, WIK-196, ACS-013 (no deathstars in ACS) | Y | `moon_destroy` kind, `MoonDestroy` mission | Raider `destroy_moon` sends the whole fleet | Offered 0%. It needs deathstars on the shelf (`moon_strike_fleet`), and the teacher rarely builds them. There's no "moon hides a fleet / holds a phalanx" trigger. See the Deathstar entry in STRONG-PLAYER-PLAN (5 Nov). | P1 |
| 15 | Deathstar wall breaker: a pure DS stack against a fodder wall; counter with plasma and domes | WIK-013, WIK-020, WIK-064, WIK-085, OGMEM | Y | DS only in templates and moon destroy | None | Missing. Measured by war cell `ds_mixed`. | P1 |
| 16 | Timing tricks: recall at the last second, return-time desync with the speed slider, a 98–101% half-flight deploy-recall to intercept | CRASH-003 (BOT-007 verified), CRASH-010/011, TP-014 | Y | Recall and speed exist | Recall on threat only | No deliberate timing play. | P2 |
| 17 | Espionage deception and counter-play: repeated probing alerts the target (INT-009); control your own activity signature (INT-007); counter-espionage destroys probes (host and sim `counter_chance`); spy from home, not from the attack colony (INT-011) | INT-005/007/009/010/011/012, TP-006/008, WIK-227 | Y | Counter-espionage roll in world.rs; activity stars | Spy cadence | No "I was spied, so expect an attack" reaction. No decoy probes. Activity masking only in the PHP routine. | P2 |
| 18 | Hiding the fleet when spied (move it after an enemy probe) | NIN-004, INT-009, TP-011 | Y (espionage notice) | Espionage notices: unclear in the sim | None | Missing: the probe-seen event isn't an errand trigger. | P2 |
| 19 | Bashing rule (attacks per target per day) | RAID-002, GF-005 §4 | Y | None (0 hits) | None | Missing in the sim. In the host, `bashingSystemLimitReached` is hard-coded false (FleetController.php:469), so the host doesn't enforce it either. A real-game rule. | P2 |
| 20 | Honour points / bandits / outlaw (loot modifiers, who may attack whom) | WIK-222, WIK-233, WIK-227 | Y | None | None | Missing in the sim. The host has outlaw strings; `isHonorableTarget` is commented out (GalaxyController.php:602). It matters for target choice on honour servers. | P3 |
| 21 | Tactical retreat (a weaker side flees before combat) | host TacticalRetreatService | Y | Out of scope ("v1", combat.rs:26) | None | Host has it, sim doesn't: a parity gap that changes crash outcomes. | P1 |
| 22 | Alliance diplomacy: NAP, trade, wars; NAPs "often broken" | WIK-205, WIK-199, SOC-002, FOR-009, `resources/behavior/diplomacy.yaml`, `alliance-war.yaml` | Y (messages, alliance page) | Alliances fixed per season (`alliance_at`, `alliance_id`); no NAP, no war declarations | None | No NAP, war or betrayal model. It is the foreign-ministry scope, after Stage D, needing `alliances=N` and self-play. Betrayal = leaving or attacking ex-mates and must be anchored to human data (CICERO lesson, RL-PITFALLS #12). | P3 |
| 23 | Back-stab inside an alliance (raid a mate, then leave) | WIK-199 ("often broken"); no rate in corpus | Y | `mates()` blocks hostile kinds against mates | None | Missing, and there's no real rate to calibrate against. Don't build it before a source gives a rate. | P3 |
| 24 | Fake-inactive / offline-looking (no activity star, to look like a farm or bait) | INT-004/007, TP-006, NIN-003 | Y | Inactive flag = 7 days without login; stars | None | Missing: the activity signature isn't a choice. | P3 |

### Realism measures available or needed
- **Have (war cells, `war_cells.py`):**
  - `odds`, `loss_ratio`, `line_share`, `light_share`, `satellites`, `defence`, `ds_mixed`
  - moon shots excluded via `mate_target`
- **Have (anomaly report):** never-used kinds; moon destruction / relocation never done.
- **Need:**
  - share of logins before a long offline gap that end with the fleet in flight or on a moon (FS-001; source for a rate: none found, so treat as a principle check, not a band)
  - ninja kills: defender's fleet arriving within the impact second (OGMEM battle reports show defender fleets far larger than the planet's own; a "defender had arriving ACS fleets" flag is not in our OGMEM parse)
  - gate jumps per moon-pair per week (no real data)
  - moon destructions per 1e10-account per month (no real data; OGMEM is battle reports only)

## 2. PvE in OGame

**What it is.** PvE ("player versus environment") in OGame means fighting or gaining from the game itself, not from other players.

**Classic OGame** has one PvE system: expeditions to slot 16 of a system.
- **Outcomes:**
  - resources
  - dark matter
  - ships (never deathstars, EXP-002)
  - items
  - a merchant
  - nothing
  - a delayed or faster return
  - total fleet loss (black hole)
  - fights with **pirates** or **aliens**
- **Pirates and aliens (WIK-173):**
  - Pirates are 5.6% of all expeditions and aliens 2.6% (officially communicated), so 70% / 30% of the expedition fights.
  - Pirates fight with each of your tech levels minus 3; aliens with plus 3.
  - Each encounter has a 10% chance to be large and 1% to be extra-large.
  - The fight leaves 10% of the destroyed fleet as debris in slot 16. Pathfinders harvest it, and only a Discoverer sees it.
- **Player lore (WIK-173):** send a destroyer to find reapers, and something like 150 or more probes reduces pirate attacks. The second claim is anecdotal; treat it as unverified.

**Newer Gameforge content.** The **Lifeforms** update (WIK-231, 2022) adds population, 18 lifeform technologies and lifeform buildings. It is economy content, not combat PvE.
- Lifeform "discoveries" (an exploration mission) also exist in live OGame. I could not verify them from our corpus, so this is unverified.
- Live events (e.g. seasonal events) exist but aren't documented in our corpus, also unverified.
- Our host has no lifeform system: `lifeform` matches only GalaxyController view strings.

**Host support (this repo):**
- `app/GameMissions/ExpeditionMission.php` with `ExpeditionOutcomeType` covers all the outcomes above, including `BattlePirates` and `BattleAliens`.
- Black hole is a server setting.
- `NPCPlanetService` and `NPCPlayerService` build the pirate or alien fleet as an NPC defender.
- There is no other PvE (no NPC planets to raid).

**Sim support.** `rl/ogsim/src/expedition.rs`:
- It is host-parity: it loads the same outcome table and runs the pirate or alien fight with the host's tech offsets.
- The Discoverer has halved pirate and alien weights.
- It handles black hole, the return-time multipliers and found ships.
- Expedition errands exist with escort, doubler and cargo plans, and the teacher picks expeditions in 22.8% of BC labels.

**What PvE means for the model:**
1. **Expedition fleet size against pirate strength.** The enemy scales with our own fleet and tech, so a bigger escort survives the large and extra-large encounters but risks more on a black hole. The real tradeoff is escort size against loss, and the model needs the encounter odds as features. They come from the host table, which is visible as public game rules.
2. **An expedition is not a safe fleetsave** (EXP-001). The main fleet shouldn't be "saved" on an expedition. Watch the share of warship value on expeditions in the anomaly report.
3. **Expeditions are a late-game economy** (finds scale with the top-1 score). The 22.8% label share is plausible but has no real band yet. There is no OGMEM data for expeditions.
4. **Expedition debris** (slot 16, pathfinders, Discoverer only) is a small extra errand. The sim has wrecks and recycle for normal debris; slot-16 harvesting isn't verified.

## 3. Top gaps (for the plan)
1. **Ninja / timed defence (#10)** and the attacker's anti-ninja re-spy (#12). Missing entirely; it's the main late-game counter.
2. **Moon destruction (#14) and the deathstar wall breaker (#15).** Offered 0%: the teacher rarely builds deathstars and has no "a moon hides a fleet or phalanx" trigger.
3. **Jump-gate escape and logistics (#7).** Offered 0%: the trigger is too narrow (an incoming attack on that moon only).
4. **Phalanx-proof saving (#2, #9).** There's no "inside an enemy phalanx range" feature and only one save route.
5. **Tactical retreat (#21).** A host/sim parity gap in combat.
6. **Bashing rule (#19).** A real rule, enforced in neither the host nor the sim.
7. **Diplomacy, NAP and betrayal (#22, #23).** Foreign-ministry scope after Stage D, anchored to human data. There's no betrayal rate in the corpus.
