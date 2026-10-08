# Play coverage — every aspect ordinary play needs, and where the module stands

Owner direction, 30 September 2026: **all aspects of the game have to be taken care of** — defence,
attack, alliance (friends and foes), social cognition, fleet management, raids, and the rest. This is
the ledger for that instruction: what each aspect is, what the live cohorts actually did, and the
task that closes each gap. The task rows reference this file; the DB stays the index.

Measured read-only on `ogamex-grand` (20 accounts, 171 planets) and `ogamex-pve` (19 accounts, 150
planets) on 30 September 2026 with `scripts/verify-cohorts.php` and direct SQL. Nothing here changes
policy on its own — where an aspect needs a doctrine decision, the row says so instead of deciding.

## The six aspects

| Aspect | Measured 30 Sep 2026 | State | Task |
| --- | --- | --- | --- |
| Defence | 5.82M units (grand) / 5.23M (pve); 82% of them Rocket Launchers; 60+ planets over the 20k ceiling, worst 1,285,633 units on one planet; 17/20 grand accounts hold a planet at zero defence beside a walled sibling | wall exists but is sized by exposure, not by threat, and lands on one planet | `DEF-001`, `QUAL-003` |
| Attack / raids | no attack mission since 28 Sep 22:44 (grand) and 18 Sep (pve); 0 raids in 24h; every battle in the window is an NPC expedition fight | dead | `ATK-001` |
| Alliance (friends and foes) | 20/20 grand in alliances across 5, one holding 15 (75%); 19/19 pve across 9; **0 applications in 7 days**, 0 buddy requests, 0 AI chat messages | membership is static; nothing recruits, reviews or welcomes | `DEF-002`, `ALLY-001` |
| Social cognition | 0 exchanges in 24h on both cohorts; grand's relationship graph updated once in 7 days (pve: 102); replies frozen at 21 Sep; 0 commitments; 134 memory facts | machinery alive, behaviour dormant | `SOC-001` |
| Fleet management | 555 expeditions/24h (grand) is the only fleet activity; no fleet save (work kind 6: **1 in the cohort's lifetime**), no recall, 0 phalanx scans ever, 28 transfers/24h | one verb only | `FLEET-001` |
| Raiding economy | **0 recyclers owned on either cohort**, 0 debris fields collected; `Recycle` intents are decided (6/24h) and cannot succeed | debris from the expedition losses is never harvested | `FLEET-002` |

Cooperative play is untested live as well: `ai_campaigns` is empty on grand, so the campaign and
consultation lane has never run against real accounts.

## Why attack and raids are dead (measured, not guessed)

`RaidPlanner::plan` refuses every report, and the traces name the gate: `raid_not_viable` and
`attack_not_permitted`. The cause is the target-class escalation (RV-011): inactives are farmable in
every phase, a **shipless** active target from Mid, and anything at all only at astrophysics 23.

- No account is inactive: every AI account plays continuously, so the "farms inactives" class is
  permanently empty.
- The cohort's best astrophysics is 21, so nobody is Late phase and the top class never unlocks.
- The remaining class is a shipless active neighbour, which the seeded accounts (444k cargo, 40k
  warships) rarely present to each other.

So in an AI-only universe the ladder cannot open, and the accounts never attack — not because the
planner is wrong but because the doctrine's classes cannot be reached. `ATK-001` states the decision
that has to be made rather than picking one.

## Why the social lane is dormant

The social machinery is observation-triggered: exchanges, buddy requests, alliance reviews and
replies all fire on something arriving. With no human in either universe and no account initiating,
nothing arrives, so the tables stop moving — `ai_conversation_replies` has not been written since
21 September. `SOC-001` covers making initiation real (it exists as `DEF-018`) and, more
importantly, making the absence visible in the cohort read instead of reading as health.

## How this file is used

- Every row above names the aspect, the measurement and the file that owns the decision.
- A row is closed only when a cohort read shows the aspect moving: an attack mission flown, an
  application decided, an exchange written, a fleet save executed, a debris field collected.
- The cohort read (`scripts/verify-cohorts.php`) is the instrument. Its invariants already raise a
  row per violation; the aspects here that have no invariant are the ones this file adds.

## `file_ref` decides who does the work

The harness attempts any ready row that carries a **proposal** or a **`file_ref`**, and it delivers
by writing files. So `file_ref` is not a hint, it is an assignment:

- **`file_ref` set** — the work is code, the named file owns the decision, and the harness will write
  it. The row must be closable by a code change plus a test.
- **`file_ref` empty** — the work is a run, not a change: open a campaign, provoke an attack, read a
  cohort back. The harness skips it and an operator or the Plan Executor picks it up.

Getting this wrong is not harmless. `CAMPAIGN-001` was raised as an operator run ("open a campaign on
pve and read it back") and was given a `file_ref` to make it visible to the harness; the harness
returned a new test for `OpenAiCampaignAction`, every campaign test passed, and it wrote an
`implemented` marker while the campaign was never opened. The marker was deleted and the `file_ref`
removed (30 Sep 2026). A row whose work is an observation must never carry one.


## The player's day, step by step (LOOP-001, read from the code 3 Oct 2026)

Each step an experienced player takes in one sitting, what does it, and whether anything plays it.
"Runs" is read from the code path (the session, the work-item kind and the scorecard aspect that
measures it); the live read is the scorecard (`bash scripts/ogamex scorecard`).

| Step of the day | Owner | Runs | Row |
| --- | --- | --- | --- |
| Log in on the account's own waking window, answer messages | `SessionPlanner`, `RunAiConversationCycleAction` | yes; accelerated cohorts now keep the night (QUAL-010) | `QUAL-010` |
| React to an inbound fleet: wall the targeted planet | `QueueableUnitPlanner` (threatened planets) | yes (PERS-006) | `PERS-006` |
| Save the fleet when the attack cannot be stopped | `QueueableFleetSavePlanner` | reactive yes; proactive before an absence open | `FLEET-003` |
| Scout the hit before the fight (phalanx on moons) | `QueueablePhalanxPlanner` | only with a moon | `LIFE-003` |
| Queue buildings and research on every planet | `QueueableBuildingPlanner`, `FacilityChain`, `EconomyUpgrades` | yes | `ECON-001` |
| Throttle mines while short | `QueueableMinePercentPlanner` | yes | none |
| Build ships and defence from what is left | `QueueableUnitPlanner` | yes; ship orders now clamp to what is affordable | `STUCK-QueueUnits-queue-not-created` |
| Move resources to the planet that needs them | `QueueableTransferPlanner` | yes; one ferry at a time, fuel room in the hold | `STUCK-DispatchFleet-no-transport-fleet` |
| Probe neighbours, then raid the profitable ones | `QueueableSpyPlanner`, `RaidPlanner` | yes; every persona may raid, skill sets the risk | `LIFE-001` |
| Pick up the wreckage | `QueueableRecyclePlanner` | yes; raids now count debris for a harvester owner | `LIFE-003` |
| Send expeditions with idle hulls | `QueueableExpeditionPlanner` | yes | none |
| Settle new planets | `QueueableColonyPlanner` | yes | none |
| Apply to, review and leave alliances | `AdvanceAiAllianceLifeAction` | now from the session path (ALLY-001) | `ALLY-001` |
| Trade on the market | none | **no step plays it**: `TraderPolicy` and `AiEconomicRole::ActiveTrader` exist with no action behind them | `LOOP-002` |
| Defend an ally's planet or fly a joint attack | none | **no step plays it**: no hold or ACS mission is planned | `LOOP-003` |
| Abandon or relocate a poor planet | none | **no step plays it** | `LOOP-004` |

## A player's day, step by step (LOOP-001, measured 6 October 2026)

Measured on one 18-hour, 12-account run (staged mid-game accounts, sleepless, seed 77): the saved database's work items, decision
traces, missions, messages and social tables. The universe has no human, so everything social is AI to AI. Re-read it from
the 20-day runs in `storage/rl/long/` before closing any row below.

| Step of the day | Owner (planner or action) | Measured in 18 h | State |
| --- | --- | --- | --- |
| Read events and messages | `PlayerObservationService`, `ObserveCommittedFleetMessage` | 1,823 observations | plays |
| Queue buildings, research | `QueueableBuildingPlanner`, `QueueableResearch` | 98 build decisions, 1,871 building work items | plays |
| Set the mine mix | `QueueableMinePercentPlanner` | 14 work items | plays |
| Buy ships and defence | `QueueableUnitPlanner` | 905 yard decisions | plays |
| Spy on neighbours | `QueueableSpyPlanner` | 362 espionage missions from 10 accounts | plays |
| Move resources between own planets | `QueueableTransferPlanner` | 208 transports | plays |
| Expeditions | `QueueableExpeditionPlanner` | 60 missions | plays |
| Recycle debris | `QueueableRecyclePlanner` | 34 missions | plays |
| Defend an ally | `QueueableDefendPlanner` | 39 ACS-defend missions | plays |
| Missiles | `QueueableMissilePlanner` | 30 missions | plays |
| Colonise | `QueueableColonyPlanner` | 251 decisions; one account sent 55 ships and 46 failed on arrival | played badly: fixed in 57615da (a flying ship claims its slot and a planet of the cap) |
| Raid | `RaidPlanner`, `WaveFarmPlanner` | 162 decisions, 266 work items, **4 attack missions flown** | mostly refused: the gap that decides battles, moons and fleet saves |
| Fleet save | `QueueableFleetSavePlanner` | 4 work items | follows from the missing raids (little to flee from) |
| Phalanx scan | `QueueablePhalanxPlanner` | 22 decisions, 0 scans, 1 moon in the universe | decided, never executed: no moon to scan from |
| Moon, lunar base, jump gate | `FacilityChain`, `QueueableJumpGatePlanner` | 1 moon, 0 jumps | moons only come from battles and debris, so it waits on raids |
| Relocate, recall | `QueueableRelocationPlanner`, `QueueableRecall` | 2 decisions, 0 recalls | dormant |
| Marketplace trade | `QueueableTradePlanner` | 20 decisions, 2 work items | thin |
| Alliance: found, apply, join | `AdvanceAiAllianceLifeAction` | 9 alliances for 12 members, 14 applications | fragments: accounts found one-member clubs instead of joining a full one |
| Alliance: leave, kick | same, `leaveOneMisfit`, `kickOneEnemy` | not triggered | built, not seen live |
| Talk: chat, buddy, exchange, commitment | `InitiateAiSocialContactAction` and the exchange actions | 13 chat messages, 0 exchanges, 0 buddy requests, 0 commitments | dormant: observation-triggered, and nothing arrives |

The gaps worth a row each, by what they cost the north star: raids refused (no battles, moons, debris or fleet saves follow),
alliance fragmentation, phalanx with no moon, social initiation. Each is a planner fix that reads the host, never a new class.

## Late-game fast loop, 7 Oct 2026 (test 9008: 24 h, budget 2e11, 6 accounts, teacher)

- All six staged moons now build lunar_base and sensor_phalanx (moon field kept for the station, ferry covers the reserve and rounding).
- Missions seen: espionage 686, transport 323, expedition 120, colonise 72, deploy 51, recycle 34, hold 22, attack 20, missile 12.
- Still missing: jump_gate (staging gives one moon per account, so a gate pair is impossible; test artefact), cruiser, battle_ship, bomber, battlecruiser, large_shield_dome, anti_ballistic_missile.
- Next: find why the yard never picks the mid warships and the dome/missiles, then one full run and retrain.

## Late-game fast loop, 8 Oct 2026 (12 h, budget 2e11, 6 accounts, teacher; `rl/scripts/probe.sh` for the why)

The yard was the bottleneck: it runs its queue in order, and junk orders held it for the whole run.

| Fix | Commit | What it ended |
|---|---|---|
| One colony ship at a time | 5a74245 | ten colony ships per account stacked behind the deathstars, none built |
| Opening cargo only for an account with no ship anywhere | eccac73 | 63 single-cargo orders in one home yard while its fleet was out |
| War batch at most two hours of yard time | 2810600 | 37 deathstars in one order holding the yard; no probes, no reports, no raids |
| Planets keep holds for what they store | 0f25fb0 | a 19M home with no hull could not ferry its moon or fleet-save |
| Yard hulls count as owned | 927ef36 | a deathstar ordered every login while the first was building; no escort |
| Yardless colony no longer freezes walls; wall before fleet in the yard plan | dbe6b0b | homes at zero defence through 21 battles |
| Moon steps gathered in instalments | 56715a5 | the jump gate waited for one planet to hold 2M/4M/2M |

Before (9311-9315): 0-6 attacks, most late units missing. After the batch cap (9316-9321): 4-13 attacks, 13-23 battles,
up to 70 recycles, 18 missiles, deploys and holds; gauss and ion appear. Still missing: jump gate, large dome,
anti-ballistic missile, and in most worlds battlecruiser and bomber (the last three fixes target these).

### Later on 8 Oct: the war fleet and the moon

| Fix | Commit |
|---|---|
| Escort built in the capital hull's yard first | 6b47e23 |
| Interceptors in the later defence doctrines (two per plasma turret); uncalled advice class deleted | 433634b |
| Escort weighed by price, not counted; template counts cargo it holds; escort takes only warships | 121b53f, 28cf886, bbba8c1 |
| Moon builds the station that shortens construction before the jump gate, with a field of its own; queued levels count | 5c610ef, 345a22e, 42f9acc |
| Yard backlog capped at four hours; only a warship batch holds the next war order back; a yard mid-batch is skipped for the next big yard | cab3188, 98589e0, 25644e3 |
| Recyclers and one-per-planet domes already queued are not reordered | 711bdbb |

24 h world 9339 (budget 2e11, 6 accounts): 102 attacks, 89 battles, 91 missile strikes, 132 recycles, 131 deploys,
24 holds, 40 colonisations. Every ship, defence and station reached except the jump gate, which was still building.
12 h worlds now miss one to three hulls at most (yard time, not a gate).

### 8 Oct, afternoon: the jump gate

Moons received the gate's money but never built it. Each cause was read from a finished world's end state.

| Cause (world) | Fix | Commit |
|---|---|---|
| A moon has no storage, so the surplus sweep flew every gate instalment back out (9352) | A moon still owed a station is never a source | 9df9713 |
| Gate queued behind an unbuilt base and phalanx, at robotics 0, taking 3.5 days; warehouses took moon fields (9354) | One moon station at a time; only moon stations on a moon | 0131fed |
| Factory bought whenever the gate was unaffordable, so the gate still ran 43 h; factories filled every field (9361) | While the factory pays back, the gate waits; the factory takes only fields not owed to the gate or phalanx, otherwise the lunar base rises; a moon's cheap step is ferried; the gate's research is not a moon step | 3e19828 |
| Bare moons waited up to 17 h for their first shipment behind the planets (9373) | Moons are fed first | e5d46fa |

9373: robotics 9 and lunar base 5 on a moon, gate queued as an 8.5 h build instead of 84 h. 9371 (24 h, before the
last fix): **nothing missing** for the first time: a gate built, 103 attacks, 80 battles, 90 missile strikes. No jump
yet: a jump needs a gate on both moons.

### 8 Oct, evening: moon destruction and the mission check

The reach check counted objects only. In 9383-9392 no moon was ever destroyed: every report on an enemy moon was
refused at `below_loot_tier` or `unprofitable`, because a moon carries little home. Fixed in e25734e. An enemy moon
reached by a fleet that holds the host's moon-breaking hulls skips the loot and profit gates; survival and fuel still
decide. `reach.txt` now lists `mission:<name>` for any mission nobody flew, plus the light units and the small dome.
Verification: worlds 9401-9404 (12 h, 6 accounts).

Alliance politics (wars, pacts, betrayal, a coalition against a dominant alliance) shipped in 3eea3b4, 9fb8cf3 and
718f330, and is being read in 9393-9394. The language-model and classifier stack (JEV readings, agents, distillation;
f4cdfa3-12eb1ba) is off until the owner supplies a key.

### 8 Oct, late afternoon: strikes that never launched, a full silo, JEV live

| Cause (world) | Fix | Commit |
|---|---|---|
| The planner chose deathstar strikes on enemy moons; dispatch refused them because the defender was online (9401) | A moon strike launches at an online defender: logging in can save a fleet, never a moon | e4d15da |
| Interceptors filled every silo slot, so no attack missile was ever stocked (9401-9404) | An account whose doctrine fires missiles fills only half its silo with interceptors | bfdc3e2 |
| The fleet sat on moons, while raids launched from the first body with any ship, a home with probes (9405) | A raid launches from the body holding the most valuable free fleet | 5e5163d |
| No gated fleet was ever attacked, so the escape jump never fired (9392) | With no threat, a fleet jumps to a gated moon nearer an enemy | 9a4fb4e |

9406 flew **4 moon destructions**, the first in any world. reach.txt now also counts gate jumps.

JEV is live (key in the root .env, jev-1.13.0, 0.4 s a reading). `rl/eval/jev-eval.php` and `jev-holdout.php` measure it on
labelled messages, alliance pages and applications:

- Message acts: 40/40 on the tuning set, 21/24 held out. The authored matcher scores 9/40 and 4/24.
- Alliance pages: 8/8. Applications: 8/8.
- Human-leader flow: a pact offer, a pact break, a war declaration and an alliance page each move the AI alliance's
  relations. A human mate's ACS call puts the target's owner at enemy threat for the AI mates.

807 persona voice lines are installed (resources/behavior/social-voices.yaml). The one doctrine proposal (WIK-013
turtle ratio) was already in defence-doctrines.yaml.

### 8 Oct, evening: war volume, the first club, moons that wait

| Cause (world) | Fix | Commit |
|---|---|---|
| No account drew the leader's trait, so no club formed (9402, 9405) | The joiner whose kind most readily leads founds the first club | 93c03ad |
| target_active_at_dispatch refused 31 raids against 7 flown, and every simulated account looks online (9401) | An enemy at war is struck while online; neutrals keep the farm rule | 67ac431 |
| Two cargo orders of about 2,180 ships held a yard for seven hours (9409) | A loot-sized cargo batch is two yard hours, and cargo in the yard counts as held | 818c58d |
| Peer clubs traded four battles without declaring war, according to the LLM judge (9406) | war_grievance 0.8: one raid is a grudge, two are a war | 5e7d254 |
| Every club sent the same welcome sentence, according to the judge (9406) | The welcome is an authored line in the founder's persona voice | 5e7d254 |
| A moon waited six hours behind fifteen planets' economy, and its gate came after the run (9407, 9408) | Moon stations get their own pass right after the wall | ac7535b |

9412 (12 h, fixes through 818c58d): **10 ACS attacks, 6 moon destructions, 4 missile strikes**. Before these fixes, 12 h
worlds flew none of these. 9393 (24 h, 12 accounts, earlier code) flew everything except moon destruction and gate jumps.

### 8 Oct, night: a full day of war; bare moons; solo joiners

9415 (24 h, 6 accounts, fixes through 8cd41ce) used every mission except gate jumps:

- 216 ACS attacks, 103 attacks and 217 battles.
- 69 missile strikes and 12 moon destructions.
- 31 holds, 122 recycles and 287 deploys.

9417 (the same build) declared the first alliance war. 9414 flew 18 moon destructions.

In every world so far, no jump gate was built and no fleet jumped. Two other things held back.

| Cause (world) | Fix | Commit |
|---|---|---|
| Staged late-game moons started bare, while their planets stood at robotics 17. A gate on a bare moon is about thirty hours of building at speed 8, so no day-long world could see a jump (9393-9414) | A late account's moons get a share of the staging budget. A moon's own stations keep only the base's field. Moons now start at base 14, phalanx 10, gate 4 | 466ecc2 |
| A raider and a miner wanted an alliance and stayed solo all day beside one two-seat club. Only a drawn leader founds (9414-9417) | A joiner no club will take founds one when another such joiner would follow it (language, pace, no grudge). The first version founded three one-seat clubs (9425) | 67477da, e117521 |
| Accounts held millions of metal and deuterium beside forty thousand crystal. The merchant was called only when a store was 80% full, which late-game stores never reach (9419, 9421) | The merchant is also called when one stock is worth twenty times a thin one at base rates. 9425 called it 7 times | 4ce4dff |
| Domes were last in every defence ratio, so a planet missing several kinds always took a laser first. The balanced doctrine named no dome at all (9424: 4 domes on about 80 planets) | Domes lead every doctrine's ratio, and balanced names them | 00b227f |

**Complete worlds:** 9420, 9424 and 9426 (12 h, 6 accounts) each used every object and every mission. In each, fleets jumped
through gates (gate stamps on 2 to 8 moons). 9426 flew 238 ACS attacks and 218 missile strikes and fought 160 battles. The weak
side is alliances: with six accounts, each holds a grudge against someone in every club.

### 8 Oct, evening: alliance diagnosis, then grudges that fade

9427 (12 accounts) took five members in half an hour and kept two. The members left minutes after acceptance: they were raided between applying and being accepted (f262e9e: a leader seats an applicant only if the applicant would still ask). Missiles were not the cause (16ce425 spares mates anyway). 9429's war collapsed when bare colonies took one dome per login and starved the probes (6b26fb2); 9428 built no light laser once domes led the ratio (92d587b).

The social model had one more fault: threat only rose. 63 of 86 relationships in 9432 stood at 0.6 or more, and a war, once declared, was never ended. Read against the 2006 board interviews (Cursed, Schism, Fury: "no real bitterness", hostilities that "diminished" when the fighting did, sides drawn by who hit whom) and the wiki's "wars end by peace or surrender":

- Grudges fade after 24 quiet hours, halving every 48 (`grudges.yaml`, 5ace00e). A new raid resets the clock.
- A club that shares the account's enemy ranks higher when it picks one (`common_enemy` in `alliance_fit.yaml`, 5ace00e).
- A war ends in peace once it has stood 24 hours and the grudges between the two sides have faded under 0.3 (`alliance-war.yaml`, 0e1e09f).

9434 (12 accounts, `COGNITION=full`, 6 h) used every mission and object and held 109 social exchanges (the native worlds hold none), but one club of 6 and no alliance war. No exchange was an apology, ceasefire or compensation: the ceasefire evaluator always answers "enforcement unavailable". 9435 (36 h, with the fade and peace) and 9433 (100 accounts, 6 h) are running; their reads follow.
