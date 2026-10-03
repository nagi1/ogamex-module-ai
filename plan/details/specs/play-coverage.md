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
