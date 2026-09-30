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
